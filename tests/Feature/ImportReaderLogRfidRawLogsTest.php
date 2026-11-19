<?php

namespace Tests\Feature;

use App\Models\AssetMovement;
use App\Models\Equipment;
use App\Models\RfidRawLog;
use Carbon\Carbon;

class ImportReaderLogRfidRawLogsTest extends AssetMovementTestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-11-19 09:00:00');
        config(['rfid.raw_log_retention_days' => 7]);
        $this->file = tempnam(sys_get_temp_dir(), 'readerlog');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    /**
     * @param  list<string>  $lines
     * @param  array<string, mixed>  $options
     */
    private function importLog(array $lines, bool $execute = true, array $options = []): void
    {
        file_put_contents($this->file, implode("\r\n", $lines)."\r\n");

        $this->artisan('rfid:import-reader-log', array_filter(['--file' => $this->file, '--execute' => $execute] + $options))->assertSuccessful();
    }

    private function line(string $tag, string $when, string $status = 'OUT', ?int $yard = null): string
    {
        return $tag.'|'.$status.'|'.$when.'|'.($yard ?? $this->yard->id).'|';
    }

    public function test_reader_log_lines_become_raw_logs_that_open_trips(): void
    {
        $asset = Equipment::factory()->equipment()->create(['eqm_prc_code' => 'TH29']);
        $this->makeTag($asset, 'TAG-1');

        $this->importLog([$this->line('TAG-1', '11/18/2026 4:53 pm'), $this->line('TAG-1', '11/18/2026 10:02 pm', 'IN')]);

        $this->assertSame(
            ['OUT|2026-11-18 16:53:00', 'IN|2026-11-18 22:02:00'],
            RfidRawLog::query()->orderBy('detected_at')->get()->map(fn (RfidRawLog $log): string => $log->status.'|'.$log->detected_at->format('Y-m-d H:i:s'))->all(),
        );
        $this->assertSame(2, RfidRawLog::query()->where(['yard_id' => $this->yard->id, 'outcome' => RfidRawLog::PROCESSED])->count());
        $this->assertSame(1, AssetMovement::query()->where('asset_id', $asset->eqm_id)->whereNotNull('time_out')->whereNotNull('time_in')->count());
    }

    public function test_repeats_in_the_same_minute_are_stored_once_and_unknown_tags_are_kept_but_build_nothing(): void
    {
        $asset = Equipment::factory()->equipment()->create(['eqm_prc_code' => 'TH30']);
        $this->makeTag($asset, 'TAG-2');

        $this->importLog([
            $this->line('TAG-2', '11/18/2026 5:04 pm'),
            $this->line('TAG-2', '11/18/2026 5:04 pm'),
            $this->line('TAG-2', '11/18/2026 5:04 pm'),
            $this->line('NOT-REGISTERED', '11/18/2026 5:04 pm'),
        ]);

        $this->assertSame(1, RfidRawLog::query()->where(['tag_id' => 'TAG-2', 'outcome' => RfidRawLog::PROCESSED])->count());
        $this->assertSame(1, RfidRawLog::query()->where(['tag_id' => 'NOT-REGISTERED', 'outcome' => RfidRawLog::UNKNOWN_TAG])->count());
        $this->assertSame(1, AssetMovement::query()->count());
    }

    public function test_a_rerun_and_a_preview_add_nothing(): void
    {
        $asset = Equipment::factory()->equipment()->create(['eqm_prc_code' => 'TH30']);
        $this->makeTag($asset, 'TAG-2');
        $lines = [$this->line('TAG-2', '11/18/2026 5:04 pm')];

        $this->importLog($lines, execute: false);
        $this->assertSame(0, RfidRawLog::query()->count());

        $this->importLog($lines);
        $this->importLog($lines);

        $this->assertSame(1, RfidRawLog::query()->count());
        $this->assertSame(1, AssetMovement::query()->count());
    }

    public function test_reads_the_system_already_has_are_not_added_again(): void
    {
        $asset = Equipment::factory()->equipment()->create(['eqm_prc_code' => 'TH30']);
        $tag = $this->makeTag($asset, 'TAG-2');
        $this->makeTag($asset, 'TAG-3');
        $this->scan($tag, 'OUT', '2026-11-18 17:04:20');

        $this->importLog([
            $this->line('TAG-3', '11/18/2026 5:04 pm'),
            $this->line('TAG-3', '11/18/2026 9:00 pm'),
        ]);

        $this->assertSame(['2026-11-18 21:00:00'], RfidRawLog::query()->get()->map(fn (RfidRawLog $log): string => $log->detected_at->format('Y-m-d H:i:s'))->all());
    }

    public function test_old_reads_are_accepted_only_with_a_longer_retention_for_the_run(): void
    {
        $asset = Equipment::factory()->equipment()->create(['eqm_prc_code' => 'TH31']);
        $this->makeTag($asset, 'TAG-9');
        $lines = [$this->line('TAG-9', '11/08/2026 8:41 am')];

        $this->importLog($lines);
        $this->assertSame(0, RfidRawLog::query()->count());

        $this->importLog($lines, options: ['--retention-days' => 14]);

        $this->assertSame(1, RfidRawLog::query()->where('outcome', RfidRawLog::PROCESSED)->count());
        $this->assertSame(1, AssetMovement::query()->where('asset_id', $asset->eqm_id)->count());
    }

    public function test_lines_that_cannot_be_read_or_name_a_missing_yard_are_skipped_and_yard_can_be_forced(): void
    {
        $asset = Equipment::factory()->equipment()->create(['eqm_prc_code' => 'TH32']);
        $this->makeTag($asset, 'TAG-4');

        $this->importLog([
            'garbage',
            'TAG-4|SIDEWAYS|11/18/2026 5:04 pm|'.$this->yard->id.'|',
            'TAG-4|OUT|not a time|'.$this->yard->id.'|',
            $this->line('TAG-4', '11/18/2026 5:04 pm', 'OUT', 99999),
        ]);

        $this->assertSame(0, RfidRawLog::query()->count());

        $this->importLog([$this->line('TAG-4', '11/18/2026 5:04 pm', 'OUT', 99999)], options: ['--yard' => 'valenzuela yard']);

        $this->assertSame(1, RfidRawLog::query()->where('yard_id', $this->yard->id)->count());
    }

    public function test_the_preview_warns_when_a_late_out_comes_after_a_time_in_with_no_time_out(): void
    {
        $asset = Equipment::factory()->equipment()->create(['eqm_prc_code' => 'TH33']);
        $this->makeTag($asset, 'TAG-5');
        AssetMovement::query()->create(['asset_id' => $asset->eqm_id, 'tag_id' => 'TAG-5', 'time_in' => '2026-11-18 23:00:00']);

        file_put_contents($this->file, $this->line('TAG-5', '11/18/2026 5:04 pm')."\n");

        $this->artisan('rfid:import-reader-log', ['--file' => $this->file])
            ->expectsOutputToContain('1 unit(s) already have a time in with no time out')
            ->assertSuccessful();
    }
}
