<?php

namespace App\Console\Commands;

use App\Services\Google\EquipmentSheetSync;
use Illuminate\Console\Command;
use Throwable;

/**
 * Updates the values of the "equipments" tab of the AppSheet spreadsheet from EMS (see EquipmentSheetSync).
 * Preview by default; the scheduler runs it with --execute when EQUIPMENT_SHEET_SYNC_ENABLED is on.
 */
class SyncEquipmentSheet extends Command
{
    protected $signature = 'equipments:sync-sheet
        {--execute : Really update the sheet; without it the command only shows what would change}
        {--max-cells=10000 : Stop without writing when more cells than this would change (a safety limit)}';

    protected $description = 'Update the values of the "equipments" sheet (existing rows only) from the equipment in EMS';

    public function handle(EquipmentSheetSync $sync): int
    {
        try {
            $plan = $sync->plan();
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Could not read the sheet: '.$exception->getMessage());

            return self::FAILURE;
        }

        $counts = $plan['counts'];

        $this->table(['Result', 'Count'], [
            ['Rows in the sheet (with an eqm_id)', $counts['sheet_rows']],
            ['  found in EMS', $counts['matched']],
            ['  already up to date', $counts['unchanged']],
            ['  to update', $counts['rows_to_update']],
            ['Cells to update', $counts['cells_to_update']],
            ['Sheet rows EMS does not know (left alone)', $counts['not_in_ems']],
            ['EMS equipment not in the sheet (not added)', $counts['not_in_sheet']],
            ['eqm_id listed twice in the sheet (both updated)', $counts['duplicate_ids']],
        ]);

        if ($plan['unknown_types'] !== []) {
            $this->warn('The asset_types tab of the sheet does not list asset type id '.implode(', ', $plan['unknown_types']).', so asset_type_id was left as it is for those rows. Add the type to that tab first.');
        }

        if ($plan['cells'] !== [] && ! $this->option('execute')) {
            $this->table(['Row', 'eqm_id', 'Column', 'Sheet now', 'EMS'], array_map(
                fn (array $cell): array => [$cell['row'], $cell['id'], $cell['column'], $cell['from'] === '' ? '(empty)' : $cell['from'], $cell['to']],
                array_slice($plan['cells'], 0, 15),
            ));

            if (count($plan['cells']) > 15) {
                $this->line('... and '.(count($plan['cells']) - 15).' more.');
            }
        }

        if (! $this->option('execute')) {
            $this->info('Preview only - the sheet was not changed. Run again with --execute to update it.');

            return self::SUCCESS;
        }

        if ($counts['cells_to_update'] > (int) $this->option('max-cells')) {
            $this->error("{$counts['cells_to_update']} cells would change, more than --max-cells={$this->option('max-cells')}. Nothing was written. Check the preview, then raise --max-cells if it is right.");

            return self::FAILURE;
        }

        try {
            $written = $sync->apply($plan);
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Could not update the sheet: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($written > 0) {
            activity('Sync')
                ->event('updated')
                ->withProperties(['cells' => $written, 'rows' => $counts['rows_to_update']])
                ->log("Updated {$written} cell(s) in {$counts['rows_to_update']} row(s) of the equipments sheet");
        }

        $this->info("Updated {$written} cell(s) in {$counts['rows_to_update']} row(s) of the equipments sheet.");

        return self::SUCCESS;
    }
}
