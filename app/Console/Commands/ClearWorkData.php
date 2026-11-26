<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Empties the work data for a clean start: every work order with its logs, updates, notes, assignments, reports and
 * attached files; every inspection (Maintenance Inspections and the old-module inspections, not AIMS inspections);
 * and every maintenance task with its logs and the schedules that create them.
 *
 * Users, departments, equipment, the task definitions, the checklist templates, AIMS inspections and the activity
 * log are kept. Preview by default; nothing is deleted without --execute.
 */
class ClearWorkData extends Command
{
    protected $signature = 'work-data:clear
        {--execute : Really delete; without it the command only counts what would be deleted}
        {--release= : The release this clean start is for (for example 1.0.0), written to the activity log}
        {--force : Skip the typed confirmation (with --execute)}';

    protected $description = 'Delete all work orders, inspections (not AIMS) and maintenance tasks with their related data and files';

    public const CONFIRMATION = 'DELETE';

    /**
     * Children before parents, so no foreign key blocks a delete.
     *
     * @var array<string, string> table => label
     */
    private const TABLES = [
        'worker_reports' => 'Worker reports',
        'report_submissions' => 'Report submissions',
        'work_order_notes' => 'Work order notes',
        'work_order_assignments' => 'Work order assignments',
        'work_order_log_updates' => 'Work order updates (replies)',
        'work_order_logs' => 'Work order logs',
        'inspection_item_logs' => 'Old-module inspection item logs',
        'maintenance_task_logs' => 'Maintenance task logs',
        'work_orders' => 'Work orders',
        'inspection_items' => 'Old-module inspection items',
        'inspections' => 'Old-module inspections',
        'maintenance_inspection_logs' => 'Maintenance inspection logs',
        'maintenance_inspection_items' => 'Maintenance inspection items',
        'maintenance_inspections' => 'Maintenance inspections',
        'maintenance_tasks' => 'Maintenance tasks',
        'equipment_tasks_schedules' => 'Maintenance schedules',
    ];

    /**
     * Columns that list attached files (paths on the local disk).
     *
     * @var array<string, string> table => column
     */
    private const FILE_COLUMNS = [
        'work_orders' => 'wo_attachments',
        'work_order_log_updates' => 'wolu_attachments',
        'work_order_notes' => 'won_attachments',
    ];

    public function handle(): int
    {
        $tables = array_filter(self::TABLES, fn (string $label, string $table): bool => Schema::hasTable($table), ARRAY_FILTER_USE_BOTH);
        $counts = array_map(fn (string $table): int => DB::table($table)->count(), array_combine(array_keys($tables), array_keys($tables)));
        $files = $this->attachedFiles();
        $existingFiles = array_values(array_filter($files, fn (string $path): bool => Storage::disk('local')->exists($path)));

        $this->table(['Data', 'Rows'], array_map(
            fn (string $table): array => [$tables[$table], $counts[$table]],
            array_keys($tables),
        ));
        $this->line('Attached files: '.count($existingFiles).' found on disk ('.count($files).' listed).');
        $this->line('Kept: users, departments, equipment, task definitions, checklist templates, AIMS inspections and the activity log.');

        if (array_sum($counts) === 0 && $existingFiles === []) {
            $this->info('Nothing to delete.');

            return self::SUCCESS;
        }

        if (! $this->option('execute')) {
            $this->info('Preview only. Back up the database, then run again with --execute to delete.');

            return self::SUCCESS;
        }

        if (! $this->option('force')) {
            $this->warn('This permanently deletes the rows above and the attached files. Make sure the database is backed up.');

            if ($this->ask('Type '.self::CONFIRMATION.' to continue') !== self::CONFIRMATION) {
                $this->warn('Cancelled. Nothing was deleted.');

                return self::FAILURE;
            }
        }

        $deleted = DB::transaction(fn (): array => array_map(
            fn (string $table): int => DB::table($table)->delete(),
            array_combine(array_keys($tables), array_keys($tables)),
        ));

        // Files go only after the rows are gone for good, so a failed delete never leaves rows pointing at missing files.
        $deletedFiles = count(array_filter($existingFiles, fn (string $path): bool => Storage::disk('local')->delete($path)));

        $release = trim((string) $this->option('release'));

        activity()
            ->event('deleted')
            ->withProperties(['rows' => $deleted, 'files' => $deletedFiles, 'release' => $release !== '' ? $release : null])
            ->log('Cleared all work orders, inspections and maintenance data'.($release !== '' ? " for release {$release}" : ''));

        $this->info('Deleted '.array_sum($deleted).' rows and '.$deletedFiles.' files.');

        if (($deleted['maintenance_inspections'] ?? 0) > 0) {
            $this->warn('Maintenance Inspections are copied from AppSheet every day: closed ones still in AppSheet come back at the next sync.');
        }

        return self::SUCCESS;
    }

    /**
     * Every file path listed in the attachment columns.
     *
     * @return list<string>
     */
    private function attachedFiles(): array
    {
        $files = [];

        foreach (self::FILE_COLUMNS as $table => $column) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            foreach (DB::table($table)->whereNotNull($column)->pluck($column) as $value) {
                $decoded = json_decode((string) $value, true);

                $paths = match (true) {
                    is_array($decoded) => $decoded,
                    is_string($decoded) => [$decoded],
                    default => [$value],
                };

                foreach ($paths as $path) {
                    if (is_string($path) && trim($path) !== '') {
                        $files[] = trim($path);
                    }
                }
            }
        }

        return array_values(array_unique($files));
    }
}
