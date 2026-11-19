<?php

namespace App\Console\Commands;

use App\Jobs\ProcessRfidRawLogs;
use App\Models\Location;
use App\Models\RfidRawLog;
use App\Services\Rfid\RawLogProcessor;
use App\Services\Rfid\RecordedReadTimes;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Replays reads that the reader server logged but never delivered to EMS. The file has one read per line, as the
 * reader's own log writes it: TAG|OUT or IN|date and time|yard id|  (for example
 * EE0000000000000000064624|OUT|11/12/2026 4:53 pm|5|). Each read becomes a row in rfid_raw_logs, exactly as if the
 * reader had sent it, and RawLogProcessor / MovementRecorder build the scans and trips.
 *
 * Safe on a database that already has some of the reads: a read of a registered tag is left out when its equipment
 * already has a scan or a trip of that kind at that time (within the trip window), and the unique key stops the
 * same raw read being stored twice. Reads of tags EMS does not know are stored too, as the reader would have sent
 * them; they end as "unknown tag" and create nothing.
 */
class ImportReaderLogRfidRawLogs extends Command
{
    protected $signature = 'rfid:import-reader-log
        {--file= : Text file, one read per line: TAG|OUT or IN|date and time|yard id|}
        {--yard= : Use this yard (id or exact name) for every line instead of the number on the line}
        {--retention-days= : Accept reads this many days old for this run only (default: the RFID_RAW_LOG_RETENTION_DAYS setting); the reads are then processed straight away}
        {--process : Process the reads now instead of leaving them to the queue}
        {--execute : Really add the reads; without it the command only shows what would happen}';

    protected $description = 'Add reads from the reader server\'s own log file to the RFID raw logs so the normal process builds the trips';

    public function handle(RawLogProcessor $processor): int
    {
        $file = $this->path((string) $this->option('file'));

        if (! is_file($file)) {
            $this->error('Pass --file=<path to the log file>. File not found: '.$file);

            return self::FAILURE;
        }

        $forcedYard = $this->forcedYard();

        if ($forcedYard === false) {
            return self::FAILURE;
        }

        $retentionDays = $this->option('retention-days') !== null ? max(1, (int) $this->option('retention-days')) : RfidRawLog::retentionDays();
        $plan = $this->plan($file, $forcedYard, $retentionDays);

        $this->report($plan, $retentionDays);

        if (! $this->option('execute')) {
            $this->info('Preview only - nothing was written. Run again with --execute to add the reads.');

            return self::SUCCESS;
        }

        // The processor reads the retention setting, so a longer window for this run has to be set before it runs.
        $overridden = $this->option('retention-days') !== null;
        $processNow = $overridden || $this->option('process');

        if ($overridden) {
            config(['rfid.raw_log_retention_days' => $retentionDays]);
        }

        $inserted = 0;
        $totals = null;
        $addReads = function () use ($plan, &$inserted): void {
            foreach (array_chunk(array_values($plan['reads']), 500) as $chunk) {
                $inserted += RfidRawLog::query()->insertOrIgnore($chunk);
            }
        };

        if ($processNow) {
            // Added and processed under the processing lock: the every-minute run must not pick the reads up in
            // between, because it would judge them by the normal retention and mark the old ones "invalid time".
            try {
                $totals = $processor->addAndRun($addReads);
            } catch (LockTimeoutException) {
                $this->error('The RFID processing is busy right now, so nothing was added. Run the command again in a minute.');

                return self::FAILURE;
            }
        } else {
            $addReads();
        }

        activity()
            ->event('created')
            ->withProperties(['reads_inserted' => $inserted])
            ->log("Added {$inserted} RFID reads from the reader server's log file");

        $this->info("Added {$inserted} read(s) to the RFID raw logs.");

        if ($totals !== null) {
            $this->table(['Result', 'Count'], collect($totals)->map(fn (int $count, string $outcome): array => [$outcome, $count])->values()->all());
        } elseif ($inserted > 0) {
            ProcessRfidRawLogs::dispatch();
            $this->line('They are processed by the queue, or now with: php artisan rfid:process-raw-logs');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{reads: array<string, array<string, mixed>>, counts: array<string, int>, known: int, unknown: int, lateOut: int, badLines: list<string>, badYards: list<string>}
     */
    private function plan(string $file, ?int $forcedYard, int $retentionDays): array
    {
        $oldest = now()->subDays($retentionDays)->format('Y-m-d H:i:s');
        $latest = now()->format('Y-m-d H:i:s');
        $batch = (string) Str::uuid();
        $yards = Location::query()->pluck('id')->flip();
        $owners = DB::table('asset_tags')->where('is_deleted', false)->pluck('asset_parent_id', 'tag_id')
            ->mapWithKeys(fn ($assetId, string $tag): array => [mb_strtolower($tag) => (int) $assetId]);

        $counts = ['lines' => 0, 'same minute' => 0, 'too old' => 0, 'future' => 0, 'already recorded' => 0, 'bad yard' => 0];
        $badLines = [];
        $badYards = [];
        $candidates = [];

        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $number => $line) {
            if (trim($line) === '') {
                continue;
            }

            $counts['lines']++;
            $read = $this->parse($line, $forcedYard);

            if ($read === null) {
                $badLines[] = 'line '.($number + 1);

                continue;
            }

            if (! $yards->has($read['yard'])) {
                $badYards[$read['yard']] = (string) $read['yard'];
                $counts['bad yard']++;

                continue;
            }

            $key = $read['yard'].'|'.mb_strtolower($read['tag']).'|'.$read['time'].'|'.$read['status'];

            match (true) {
                $read['time'] < $oldest => $counts['too old']++,
                $read['time'] > $latest => $counts['future']++,
                isset($candidates[$key]) => $counts['same minute']++,
                default => $candidates[$key] = $read + ['asset' => $owners[mb_strtolower($read['tag'])] ?? null],
            };
        }

        $times = app(RecordedReadTimes::class);
        $recorded = $times->forAssets(array_values(array_unique(array_filter(array_column($candidates, 'asset')))));
        $window = (int) config('rfid.movement_window_seconds');
        $reads = [];
        $known = 0;
        $unknown = 0;

        foreach ($candidates as $key => $read) {
            if ($read['asset'] !== null && $times->hasNear($recorded[$read['asset']] ?? [], $read['status'], strtotime($read['time']), $window)) {
                $counts['already recorded']++;

                continue;
            }

            $read['asset'] === null ? $unknown++ : $known++;

            $reads[$key] = [
                'batch_uuid' => $batch,
                'yard_id' => $read['yard'],
                'tag_id' => $read['tag'],
                'status' => $read['status'],
                'detected_at' => $read['time'],
            ];
        }

        return [
            'reads' => $reads,
            'counts' => $counts,
            'known' => $known,
            'unknown' => $unknown,
            'lateOut' => $this->lateOutUnits($candidates, $reads),
            'badLines' => $badLines,
            'badYards' => array_values($badYards),
        ];
    }

    /**
     * Units that already have an IN with no OUT, after an OUT this run adds. The recorder opens a new trip for such
     * an OUT ("missing time in") instead of completing the old one ("missing time out"), so those need a look.
     *
     * @param  array<string, array<string, mixed>>  $candidates
     * @param  array<string, array<string, mixed>>  $reads
     */
    private function lateOutUnits(array $candidates, array $reads): int
    {
        $outs = [];

        foreach ($reads as $key => $read) {
            if ($read['status'] === 'OUT' && ($candidates[$key]['asset'] ?? null) !== null) {
                $outs[$candidates[$key]['asset']][] = $read['detected_at'];
            }
        }

        if ($outs === []) {
            return 0;
        }

        $units = [];

        foreach (array_chunk(array_keys($outs), 500) as $chunk) {
            $trips = DB::table('asset_movements')
                ->whereIn('asset_id', $chunk)
                ->whereNull('time_out')
                ->whereNotNull('time_in')
                ->whereNull('disregarded_at')
                ->get(['asset_id', 'time_in']);

            foreach ($trips as $trip) {
                if (collect($outs[$trip->asset_id])->contains(fn (string $out): bool => $out <= $trip->time_in)) {
                    $units[$trip->asset_id] = true;
                }
            }
        }

        return count($units);
    }

    /**
     * @param  array{reads: array<string, mixed>, counts: array<string, int>, known: int, unknown: int, lateOut: int, badLines: list<string>, badYards: list<string>}  $plan
     */
    private function report(array $plan, int $retentionDays): void
    {
        $this->table(['Result', 'Count'], [
            ['Lines in the file', $plan['counts']['lines']],
            ['Reads to add', count($plan['reads'])],
            ['  of tags EMS knows (they build scans and trips)', $plan['known']],
            ['  of tags EMS does not know (kept in the raw logs only)', $plan['unknown']],
            ['Repeats of the same tag in the same minute (merged)', $plan['counts']['same minute']],
            ['Already recorded here (scan or trip at that time, skipped)', $plan['counts']['already recorded']],
            ["Older than {$retentionDays} days (skipped)", $plan['counts']['too old']],
            ['Times in the future (skipped)', $plan['counts']['future']],
            ['Lines that could not be read (skipped)', count($plan['badLines'])],
            ['Lines with a yard that does not exist (skipped)', $plan['counts']['bad yard']],
        ]);

        if ($plan['badLines'] !== []) {
            $this->warn('Unreadable: '.implode(', ', array_slice($plan['badLines'], 0, 15)).(count($plan['badLines']) > 15 ? ', ...' : ''));
        }

        if ($plan['badYards'] !== []) {
            $this->warn('Yard ids not found: '.implode(', ', $plan['badYards']).'. Use --yard=<id or name> to put every line in one yard.');
        }

        if ($plan['counts']['too old'] > 0) {
            $this->warn('Some reads are older than the retention period. Add --retention-days=<days> to accept them for this run.');
        }

        if ($plan['lateOut'] > 0) {
            $this->warn("{$plan['lateOut']} unit(s) already have a time in with no time out, after one of these OUT reads. Each such OUT opens its own trip (missing time in); the old trip (missing time out) stays. Check them on the Time In / Out screen afterwards.");
        }
    }

    /**
     * @return array{tag: string, status: string, time: string, yard: int}|null
     */
    private function parse(string $line, ?int $forcedYard): ?array
    {
        $parts = array_map('trim', explode('|', $line));

        if (count($parts) < 3 || $parts[0] === '' || strlen($parts[0]) > 64) {
            return null;
        }

        $status = strtoupper($parts[1]);
        $yard = $forcedYard ?? (ctype_digit($parts[3] ?? '') ? (int) $parts[3] : null);
        $time = $this->time($parts[2]);

        if (! in_array($status, ['IN', 'OUT'], true) || $time === null || $yard === null) {
            return null;
        }

        return ['tag' => $parts[0], 'status' => $status, 'time' => $time, 'yard' => $yard];
    }

    private function time(string $value): ?string
    {
        foreach (['n/j/Y g:i a', 'n/j/Y g:i:s a', 'Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
            $time = DateTimeImmutable::createFromFormat('!'.$format, $value);

            if ($time !== false && $time->format($format) === $value) {
                return $time->format('Y-m-d H:i:s');
            }
        }

        try {
            return CarbonImmutable::parse($value)->format('Y-m-d H:i:s');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return int|false|null the yard to use for every line, null when each line carries its own, false after an error
     */
    private function forcedYard(): int|false|null
    {
        $yard = trim((string) $this->option('yard'));

        if ($yard === '') {
            return null;
        }

        $matches = ctype_digit($yard)
            ? Location::query()->whereKey((int) $yard)->get()
            : Location::query()->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($yard)])->get();

        if ($matches->count() === 1) {
            return (int) $matches->first()->getKey();
        }

        $this->error($matches->isEmpty() ? "No yard has the id or name \"{$yard}\"." : "More than one yard is named \"{$yard}\"; use its id.");

        return false;
    }

    private function path(string $path): string
    {
        return str_starts_with($path, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) ? $path : base_path($path);
    }
}
