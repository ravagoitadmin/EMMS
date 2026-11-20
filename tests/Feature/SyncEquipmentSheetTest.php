<?php

namespace Tests\Feature;

use App\Models\Equipment;
use App\Services\Google\GoogleSheetWriter;
use Illuminate\Console\Scheduling\Schedule;
use Mockery;
use Mockery\MockInterface;

class SyncEquipmentSheetTest extends AssetMovementTestCase
{
    private const HEADER = ['eqm_id', 'asset_type_id', 'eqm_name', 'eqm_prc_code', 'eqm_is_active'];

    private MockInterface $sheet;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.google.equipment_sheet.tab' => 'equipments']);
        $this->sheet = Mockery::mock(GoogleSheetWriter::class);
        $this->app->instance(GoogleSheetWriter::class, $this->sheet);
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function sheetHas(array $rows, array $header = self::HEADER, array $types = [1, 2, 3]): void
    {
        $this->sheet->shouldReceive('values')->with('equipments')->andReturn([$header, ...$rows]);
        $this->sheet->shouldReceive('values')->with('asset_types')->andReturn([['id', 'name'], ...array_map(fn (int $id): array => [(string) $id, 'Type '.$id], $types)]);
    }

    private function unit(array $attributes = []): Equipment
    {
        return Equipment::factory()->equipment()->create($attributes);
    }

    public function test_the_preview_shows_what_would_change_and_writes_nothing(): void
    {
        $unit = $this->unit(['eqm_name' => 'Crane 1', 'eqm_prc_code' => 'CR1', 'eqm_is_active' => true]);
        $this->sheetHas([[(string) $unit->eqm_id, '1', 'Old name', 'CR1', '0']]);
        $this->sheet->shouldNotReceive('writeCells');

        $this->artisan('equipments:sync-sheet')
            ->expectsOutputToContain('Preview only')
            ->assertSuccessful();
    }

    public function test_only_the_values_that_differ_are_written_to_their_own_cells(): void
    {
        $unit = $this->unit(['eqm_name' => 'Crane 1', 'eqm_prc_code' => 'CR1', 'eqm_is_active' => true]);
        $same = $this->unit(['eqm_name' => 'Crane 2', 'eqm_prc_code' => 'CR2', 'eqm_is_active' => false]);
        $this->sheetHas([
            [(string) $unit->eqm_id, (string) $unit->asset_type_id, 'Old name', 'CR1', '0'],
            [(string) $same->eqm_id, (string) $same->asset_type_id, 'Crane 2', 'CR2', 'FALSE'],
        ]);

        $this->sheet->shouldReceive('writeCells')->once()->with('equipments', [
            ['cell' => 'C2', 'value' => 'Crane 1'],
            ['cell' => 'E2', 'value' => 1],
        ]);

        $this->artisan('equipments:sync-sheet', ['--execute' => true])
            ->expectsOutputToContain('Updated 2 cell(s) in 1 row(s)')
            ->assertSuccessful();
    }

    public function test_units_that_are_not_in_the_sheet_are_added_below_the_last_row_and_unknown_sheet_rows_are_left_alone(): void
    {
        $known = $this->unit(['eqm_name' => 'Crane 3', 'eqm_prc_code' => 'CR3', 'eqm_is_active' => true]);
        $missing = $this->unit(['eqm_name' => 'Not in the sheet', 'eqm_prc_code' => 'NIS', 'eqm_is_active' => false]);
        $this->sheetHas([
            ['99999', '', 'RAVAGO RENTALS', '1010', '0'],
            [(string) $known->eqm_id, (string) $known->asset_type_id, 'Crane 3', 'CR3', '1'],
        ]);
        $this->sheet->shouldNotReceive('writeCells');
        $this->sheet->shouldReceive('appendRows')->once()->with('equipments', 4, [
            [$missing->eqm_id, (int) $missing->asset_type_id, 'Not in the sheet', 'NIS', 0],
        ]);

        $this->artisan('equipments:sync-sheet', ['--execute' => true])
            ->expectsOutputToContain('added 1 row(s)')
            ->assertSuccessful();
    }

    public function test_a_new_row_follows_the_column_order_of_the_sheet_and_leaves_an_unlisted_type_empty(): void
    {
        $missing = $this->unit(['eqm_name' => 'Crane 7', 'eqm_prc_code' => 'CR7', 'eqm_is_active' => true]);
        $other = (int) $missing->asset_type_id === 1 ? 2 : 1;
        $known = $this->unit(['eqm_name' => 'Known', 'eqm_prc_code' => 'KN7', 'eqm_is_active' => true]);
        $this->sheetHas([['Known', '', 'KN7', '1', (string) $known->eqm_id]], ['eqm_name', 'asset_type_id', 'eqm_prc_code', 'eqm_is_active', 'eqm_id'], types: [$other]);
        $this->sheet->shouldReceive('writeCells')->never();
        $this->sheet->shouldReceive('appendRows')->once()->with('equipments', 3, [
            ['Crane 7', '', 'CR7', 1, $missing->eqm_id],
        ]);

        $this->artisan('equipments:sync-sheet', ['--execute' => true])->assertSuccessful();
    }

    public function test_the_preview_adds_nothing_and_the_rows_count_toward_the_safety_limit(): void
    {
        $known = $this->unit(['eqm_name' => 'Known', 'eqm_prc_code' => 'KN8', 'eqm_is_active' => true]);
        $this->unit(['eqm_name' => 'Crane 8', 'eqm_prc_code' => 'CR8']);
        $this->sheetHas([[(string) $known->eqm_id, (string) $known->asset_type_id, 'Known', 'KN8', '1']]);
        $this->sheet->shouldNotReceive('writeCells');
        $this->sheet->shouldNotReceive('appendRows');

        $this->artisan('equipments:sync-sheet')->expectsOutputToContain('Preview only')->assertSuccessful();

        $this->sheet->shouldNotReceive('appendRows');
        $this->artisan('equipments:sync-sheet', ['--execute' => true, '--max-cells' => 4])
            ->expectsOutputToContain('Nothing was written')
            ->assertFailed();
    }

    public function test_an_empty_value_in_ems_never_blanks_a_cell_in_the_sheet(): void
    {
        $unit = $this->unit(['eqm_name' => 'Crane 4', 'eqm_prc_code' => null, 'eqm_is_active' => true]);
        $this->sheetHas([[(string) $unit->eqm_id, (string) $unit->asset_type_id, 'Crane 4', 'KEEP-ME', '1']]);
        $this->sheet->shouldNotReceive('writeCells');

        $this->artisan('equipments:sync-sheet', ['--execute' => true])->assertSuccessful();
    }

    public function test_a_sheet_with_the_columns_in_another_order_is_written_to_the_right_cells(): void
    {
        $unit = $this->unit(['eqm_name' => 'Crane 5', 'eqm_prc_code' => 'CR5', 'eqm_is_active' => true]);
        $this->sheetHas(
            [['stale', (string) $unit->asset_type_id, 'OLD', '1', (string) $unit->eqm_id]],
            ['eqm_name', 'asset_type_id', 'eqm_prc_code', 'eqm_is_active', 'eqm_id'],
        );

        $this->sheet->shouldReceive('writeCells')->once()->with('equipments', [
            ['cell' => 'A2', 'value' => 'Crane 5'],
            ['cell' => 'C2', 'value' => 'CR5'],
        ]);

        $this->artisan('equipments:sync-sheet', ['--execute' => true])->assertSuccessful();
    }

    public function test_a_missing_column_or_a_sheet_with_no_matching_ids_stops_without_writing(): void
    {
        $this->unit();
        $this->sheet->shouldNotReceive('writeCells');

        $this->sheetHas([['1', 'x']], ['eqm_id', 'eqm_name']);
        $this->artisan('equipments:sync-sheet', ['--execute' => true])
            ->expectsOutputToContain('has no column named asset_type_id, eqm_prc_code, eqm_is_active')
            ->assertFailed();
    }

    public function test_none_of_the_ids_matching_stops_without_writing(): void
    {
        $this->unit();
        $this->sheet->shouldNotReceive('writeCells');
        $this->sheetHas([['88888', '1', 'Other', 'X', '1']]);

        $this->artisan('equipments:sync-sheet', ['--execute' => true])
            ->expectsOutputToContain('None of the eqm_id values in the sheet exist in EMS')
            ->assertFailed();
    }

    public function test_too_many_changes_stop_without_writing(): void
    {
        $unit = $this->unit(['eqm_name' => 'Crane 6', 'eqm_prc_code' => 'CR6', 'eqm_is_active' => true]);
        $this->sheetHas([[(string) $unit->eqm_id, '', 'OLD', 'OLD', '0']]);
        $this->sheet->shouldNotReceive('writeCells');

        $this->artisan('equipments:sync-sheet', ['--execute' => true, '--max-cells' => 2])
            ->expectsOutputToContain('Nothing was written')
            ->assertFailed();
    }

    public function test_an_asset_type_is_only_written_when_the_asset_types_tab_lists_it(): void
    {
        $unit = $this->unit(['eqm_name' => 'Van 1', 'eqm_prc_code' => 'VAN1', 'eqm_is_active' => true]);
        $typeId = (int) $unit->asset_type_id;
        $other = $typeId === 1 ? 2 : 1;
        $this->sheetHas([[(string) $unit->eqm_id, (string) $other, 'Van 1', 'VAN1', '1']], types: [$other]);
        $this->sheet->shouldNotReceive('writeCells');

        $this->artisan('equipments:sync-sheet', ['--execute' => true])
            ->expectsOutputToContain("does not list asset type id {$typeId}")
            ->assertSuccessful();
    }

    public function test_a_listed_asset_type_is_written(): void
    {
        $unit = $this->unit(['eqm_name' => 'Van 2', 'eqm_prc_code' => 'VAN2', 'eqm_is_active' => true]);
        $this->sheetHas([[(string) $unit->eqm_id, '', 'Van 2', 'VAN2', '1']], types: [(int) $unit->asset_type_id]);
        $this->sheet->shouldReceive('writeCells')->once()->with('equipments', [['cell' => 'B2', 'value' => (int) $unit->asset_type_id]]);

        $this->artisan('equipments:sync-sheet', ['--execute' => true])->assertSuccessful();
    }

    public function test_the_schedule_runs_only_when_it_is_switched_on(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'equipments:sync-sheet'));

        $this->assertNotNull($event);
        $this->assertStringContainsString('--execute', $event->command);

        config(['services.google.equipment_sheet.enabled' => false]);
        $this->assertFalse($event->filtersPass($this->app));

        config(['services.google.equipment_sheet.enabled' => true]);
        $this->assertTrue($event->filtersPass($this->app));
    }
}
