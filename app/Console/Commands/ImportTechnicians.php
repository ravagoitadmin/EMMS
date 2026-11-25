<?php

namespace App\Console\Commands;

use App\Models\AppUser;
use App\Models\Role;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Adds technicians from a CSV file, the same way Technicians > Create does: an active EMS user in the given
 * department with the technician role. Preview by default; nothing is written without --execute.
 */
class ImportTechnicians extends Command
{
    protected $signature = 'technicians:import
        {source : CSV with the columns first_name, middle_name, last_name, email and contact_no}
        {--department= : Code or id of the department the technicians belong to (for example ELEC)}
        {--execute : Really add the technicians; without it the command only shows what would be added}
        {--force : Skip the confirmation question (with --execute)}';

    protected $description = 'Add technicians (EMS users with the technician role) from a CSV file';

    private const REQUIRED_COLUMNS = ['first_name', 'last_name', 'email'];

    public function handle(): int
    {
        $source = (string) $this->argument('source');
        $path = File::exists($source) ? $source : base_path($source);

        if (! File::exists($path)) {
            $this->error("File not found: {$source}");

            return self::FAILURE;
        }

        $department = $this->department((string) $this->option('department'));

        if ($department === null) {
            $this->error('Give the department with --department=<code or id>, for example --department=ELEC. It must exist in EMS.');

            return self::FAILURE;
        }

        $rows = $this->readRows($path);

        if (is_string($rows)) {
            $this->error($rows);

            return self::FAILURE;
        }

        $existing = AppUser::query()->get(['user_id', 'user_fname', 'user_lname', 'user_email'])
            ->keyBy(fn (AppUser $user): string => mb_strtolower(trim((string) $user->user_email)));

        $toAdd = [];
        $skipped = [];
        $seen = [];

        foreach ($rows as $line => $row) {
            $contact = $this->contactNumber($row['contact_no']);

            $reason = match (true) {
                $row['first_name'] === '' => 'no first name',
                $row['last_name'] === '' => 'no last name',
                ! $this->isEmail($row['email']) => "email \"{$row['email']}\" is not a valid address",
                $contact === false => "contact number \"{$row['contact_no']}\" is not 11 digits starting with 09",
                $existing->has($row['email']) => 'already in EMS as '.$existing[$row['email']]->full_name.' (user '.$existing[$row['email']]->user_id.')',
                isset($seen[$row['email']]) => 'email listed twice in the file',
                default => null,
            };

            if ($reason !== null) {
                $skipped[] = ['line' => $line, 'name' => trim("{$row['first_name']} {$row['last_name']}"), 'reason' => $reason];

                continue;
            }

            $seen[$row['email']] = true;
            $toAdd[] = ['contact_no' => $contact] + $row;
        }

        $this->line("Department: {$department->dep_name} ({$department->dep_code})");

        if ($toAdd !== []) {
            $this->table(['First name', 'Middle name', 'Last name', 'Email', 'Contact #'], array_map(
                fn (array $row): array => [$row['first_name'], $row['middle_name'] ?: '-', $row['last_name'], $row['email'], $row['contact_no'] ?: '-'],
                $toAdd,
            ));
        }

        foreach ($skipped as $row) {
            $this->warn("Skipped line {$row['line']} ({$row['name']}): {$row['reason']}");
        }

        $this->info(count($toAdd).' to add, '.count($skipped).' skipped.');

        if ($toAdd === []) {
            return self::SUCCESS;
        }

        if (! $this->option('execute')) {
            $this->info('Preview only. Run again with --execute to add them.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Add '.count($toAdd)." technicians to {$department->dep_name}?", false)) {
            $this->warn('Cancelled. Nothing was added.');

            return self::FAILURE;
        }

        $role = Role::firstOrCreate(['name' => 'technician', 'guard_name' => 'web'], ['display_name' => 'Technician']);

        $created = DB::transaction(fn (): array => array_map(function (array $row) use ($department, $role): int {
            $user = AppUser::query()->create([
                'user_fname' => $row['first_name'],
                'user_mname' => $row['middle_name'] ?: null,
                'user_lname' => $row['last_name'],
                'user_email' => $row['email'],
                'user_contact_no' => $row['contact_no'] ?: null,
                'user_dep_id' => $department->dep_id,
                'is_active' => true,
            ]);
            $user->assignRole($role);

            return (int) $user->getKey();
        }, $toAdd));

        activity()
            ->event('created')
            ->withProperties(['technicians' => count($created), 'user_ids' => $created, 'department' => $department->dep_code])
            ->log('Added '.count($created)." technicians to {$department->dep_name} from a file");

        $this->info('Added '.count($created).' technicians (user ids '.implode(', ', $created).').');

        return self::SUCCESS;
    }

    private function department(string $codeOrId): ?object
    {
        $codeOrId = trim($codeOrId);

        if ($codeOrId === '') {
            return null;
        }

        return DB::table('departments')
            ->when(
                ctype_digit($codeOrId),
                fn ($query) => $query->where('dep_id', (int) $codeOrId),
                fn ($query) => $query->whereRaw('lower(dep_code) = ?', [mb_strtolower($codeOrId)]),
            )
            ->first(['dep_id', 'dep_code', 'dep_name']);
    }

    /**
     * The rows of the file by line number, tidied: names in Title Case, the email in lower case. A message when a
     * column is missing.
     *
     * @return array<int, array{first_name: string, middle_name: string, last_name: string, email: string, contact_no: string}>|string
     */
    private function readRows(string $path): array|string
    {
        $handle = fopen($path, 'r');
        $header = array_map(
            fn ($name): string => mb_strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $name))),
            fgetcsv($handle, 0, ',', '"', '') ?: [],
        );
        $missing = array_diff(self::REQUIRED_COLUMNS, $header);

        if ($missing !== []) {
            fclose($handle);

            return 'The file has no column named '.implode(', ', $missing).'. The first line must be: first_name,middle_name,last_name,email,contact_no';
        }

        $columns = array_flip($header);
        $value = fn (array $row, string $column): string => isset($columns[$column]) ? $this->tidy((string) ($row[$columns[$column]] ?? '')) : '';
        $rows = [];
        $line = 1;

        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $line++;

            if (implode('', array_map(fn ($cell): string => trim((string) $cell), $row)) === '') {
                continue;
            }

            $rows[$line] = [
                'first_name' => $this->name($value($row, 'first_name')),
                'middle_name' => $this->name($value($row, 'middle_name')),
                'last_name' => $this->name($value($row, 'last_name')),
                'email' => mb_strtolower($value($row, 'email')),
                'contact_no' => $value($row, 'contact_no'),
            ];
        }

        fclose($handle);

        return $rows;
    }

    private function tidy(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /** MARK DAVE => Mark Dave, DEL ROSARIO => Del Rosario, MEDALLE JR => Medalle Jr. */
    private function name(string $value): string
    {
        $value = mb_convert_case(mb_strtolower($value), MB_CASE_TITLE);

        return (string) preg_replace('/\b(Jr|Sr)\b\.?/u', '$1.', $value);
    }

    /** A real address: something@domain.tld (so "name@gmail" without .com is refused). */
    private function isEmail(string $email): bool
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            && str_contains(substr($email, (int) strrpos($email, '@')), '.');
    }

    /**
     * A Philippine mobile number as 09XXXXXXXXX ('' when the file has none), or false when it is not one.
     * Spaces and dashes are ignored, and +639... / 639... are turned into 09....
     */
    private function contactNumber(string $value): string|false
    {
        $digits = (string) preg_replace('/[\s\-()]/', '', $value);

        if ($digits === '') {
            return '';
        }

        $digits = (string) preg_replace('/^\+?63(?=9)/', '0', $digits);

        return preg_match('/^09\d{9}$/', $digits) === 1 ? $digits : false;
    }
}
