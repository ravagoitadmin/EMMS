<?php

namespace App\Services\Google;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Brings the values of the "equipments" tab of the AppSheet spreadsheet up to date with the equipment in EMS.
 *
 * After a run the sheet has every unit of equipment_units: rows are matched by eqm_id, the values of rows that are
 * already there are updated (asset_type_id, eqm_name, eqm_prc_code, lifecycle_status_id; eqm_id never), and units that are
 * not there yet are added below the last row. Rows are never moved or deleted, a sheet row EMS does not know is left
 * alone, and an empty value in EMS never blanks a cell in the sheet. An asset_type_id is only written when the
 * sheet's asset_types tab lists that id (it is a reference to that tab).
 */
class EquipmentSheetSync
{
    public const ID_COLUMN = 'eqm_id';

    /** @var list<string> the columns that follow EMS */
    public const VALUE_COLUMNS = ['asset_type_id', 'eqm_name', 'eqm_prc_code', 'lifecycle_status_id'];

    public function __construct(private readonly GoogleSheetWriter $sheet) {}

    /**
     * What a run would change.
     *
     * @return array{
     *     cells: list<array{cell: string, value: string|int, row: int, id: int, column: string, from: string, to: string}>,
     *     counts: array{sheet_rows: int, matched: int, unchanged: int, rows_to_update: int, cells_to_update: int, not_in_ems: int, rows_to_add: int, cells_to_add: int, duplicate_ids: int},
     *     new_rows: list<list<string|int>>,
     *     first_free_row: int,
     *     unknown_types: list<int>
     * }
     */
    public function plan(): array
    {
        $values = $this->sheet->values($this->tab());

        if ($values === []) {
            throw new RuntimeException("The \"{$this->tab()}\" tab is empty or does not exist.");
        }

        $columns = $this->columnIndexes($values[0]);
        $sheetRows = [];
        $duplicates = 0;

        foreach (array_slice($values, 1) as $offset => $row) {
            $id = trim((string) ($row[$columns[self::ID_COLUMN]] ?? ''));

            if (! ctype_digit($id)) {
                continue;
            }

            if (isset($sheetRows[(int) $id])) {
                $duplicates++;
            }

            $sheetRows[(int) $id][] = ['row' => $offset + 2, 'values' => $row];
        }

        $equipment = $this->equipment(array_keys($sheetRows));
        $types = $this->typesInSheet();

        if ($sheetRows !== [] && $equipment === []) {
            throw new RuntimeException('None of the eqm_id values in the sheet exist in EMS. Is GOOGLE_SPREADSHEET_ID the right spreadsheet?');
        }

        $cells = [];
        $changedRows = [];
        $matched = 0;
        $notInEms = 0;
        $unknownTypes = [];

        foreach ($sheetRows as $id => $rows) {
            if (! isset($equipment[$id])) {
                $notInEms++;

                continue;
            }

            $matched++;

            foreach ($rows as ['row' => $number, 'values' => $row]) {
                foreach (self::VALUE_COLUMNS as $column) {
                    $wanted = $this->wanted($column, $equipment[$id]->{$column});

                    if ($wanted === null) {
                        continue;
                    }

                    // asset_type_id points at the asset_types tab: never write a type that tab does not list.
                    if ($column === 'asset_type_id' && ! isset($types[(int) $wanted])) {
                        $unknownTypes[(int) $wanted] = true;

                        continue;
                    }

                    $current = trim((string) ($row[$columns[$column]] ?? ''));

                    if ($this->same($current, (string) $wanted)) {
                        continue;
                    }

                    $cells[] = [
                        'cell' => $this->letter($columns[$column]).$number,
                        'value' => $wanted,
                        'row' => $number,
                        'id' => $id,
                        'column' => $column,
                        'from' => $current,
                        'to' => (string) $wanted,
                    ];
                    $changedRows[$number] = true;
                }
            }
        }

        $newRows = $this->newRows(array_keys($sheetRows), $columns, $types);

        return [
            'cells' => $cells,
            'counts' => [
                'sheet_rows' => array_sum(array_map('count', $sheetRows)),
                'matched' => $matched,
                'unchanged' => $matched - count(array_unique(array_column($cells, 'id'))),
                'rows_to_update' => count($changedRows),
                'cells_to_update' => count($cells),
                'not_in_ems' => $notInEms,
                'rows_to_add' => count($newRows),
                'cells_to_add' => count($newRows) * (count(self::VALUE_COLUMNS) + 1),
                'duplicate_ids' => $duplicates,
            ],
            'new_rows' => $newRows,
            'first_free_row' => count($values) + 1,
            'unknown_types' => array_keys($unknownTypes),
        ];
    }

    /**
     * Writes the updated cells and adds the new rows of a plan.
     *
     * @param  array{cells: list<array{cell: string, value: string|int}>, new_rows: list<list<string|int>>, first_free_row: int}  $plan
     * @return array{updated: int, added: int}
     */
    public function apply(array $plan): array
    {
        if ($plan['cells'] !== []) {
            $this->sheet->writeCells($this->tab(), array_map(fn (array $cell): array => ['cell' => $cell['cell'], 'value' => $cell['value']], $plan['cells']));
        }

        if ($plan['new_rows'] !== []) {
            $this->sheet->appendRows($this->tab(), $plan['first_free_row'], $plan['new_rows']);
        }

        return ['updated' => count($plan['cells']), 'added' => count($plan['new_rows'])];
    }

    /**
     * The units that are not in the sheet yet, as rows laid out like the sheet's columns, in eqm_id order.
     *
     * @param  list<int>  $idsInSheet
     * @param  array<string, int>  $columns
     * @param  array<int, true>  $types
     * @return list<list<string|int>>
     */
    private function newRows(array $idsInSheet, array $columns, array $types): array
    {
        $inSheet = array_flip($idsInSheet);
        $width = max($columns) + 1;
        $rows = [];

        foreach (DB::table('equipment_units')->orderBy('eqm_id')->get(['eqm_id', ...self::VALUE_COLUMNS]) as $unit) {
            if (isset($inSheet[(int) $unit->eqm_id])) {
                continue;
            }

            $row = array_fill(0, $width, '');
            $row[$columns[self::ID_COLUMN]] = (int) $unit->eqm_id;

            foreach (self::VALUE_COLUMNS as $column) {
                $wanted = $this->wanted($column, $unit->{$column});

                if ($column === 'asset_type_id' && ($wanted === null || ! isset($types[(int) $wanted]))) {
                    $wanted = null;
                }

                $row[$columns[$column]] = $wanted ?? '';
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private function tab(): string
    {
        return (string) config('services.google.equipment_sheet.tab');
    }

    /**
     * The ids listed in the asset_types tab (the sheet's list of Equipment, Accessory, Service Vehicle, ...).
     *
     * @return array<int, true>
     */
    private function typesInSheet(): array
    {
        $tab = (string) config('services.google.equipment_sheet.types_tab');
        $values = $this->sheet->values($tab);
        $column = array_search('id', array_map(fn (mixed $name): string => strtolower(trim((string) $name)), $values[0] ?? []), true);

        if ($column === false) {
            throw new RuntimeException("The \"{$tab}\" tab has no column named id, so the asset types cannot be checked.");
        }

        $types = [];

        foreach (array_slice($values, 1) as $row) {
            $id = trim((string) ($row[$column] ?? ''));

            if (ctype_digit($id)) {
                $types[(int) $id] = true;
            }
        }

        return $types;
    }

    /**
     * @param  list<mixed>  $header
     * @return array<string, int> column name => 0-based position
     */
    private function columnIndexes(array $header): array
    {
        $lookup = [];

        foreach ($header as $index => $name) {
            $lookup[strtolower(trim((string) $name))] = $index;
        }

        $missing = array_values(array_filter([self::ID_COLUMN, ...self::VALUE_COLUMNS], fn (string $column): bool => ! array_key_exists($column, $lookup)));

        if ($missing !== []) {
            throw new RuntimeException("The \"{$this->tab()}\" tab has no column named ".implode(', ', $missing).'.');
        }

        return array_intersect_key($lookup, array_flip([self::ID_COLUMN, ...self::VALUE_COLUMNS]));
    }

    /**
     * @param  list<int>  $ids
     * @return array<int, object>
     */
    private function equipment(array $ids): array
    {
        $found = [];

        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (DB::table('equipment_units')->whereIn('eqm_id', $chunk)->get(['eqm_id', ...self::VALUE_COLUMNS]) as $unit) {
                $found[(int) $unit->eqm_id] = $unit;
            }
        }

        return $found;
    }

    /**
     * The value to write, or null when EMS has nothing for that column (an empty value never blanks a cell).
     */
    private function wanted(string $column, mixed $value): string|int|null
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return $column === 'asset_type_id' ? (int) $value : $value;
    }

    private function same(string $current, string $wanted): bool
    {
        return $current === $wanted;
    }

    /** 0 => A, 25 => Z, 26 => AA */
    private function letter(int $index): string
    {
        $letters = '';

        for ($number = $index + 1; $number > 0; $number = intdiv($number - 1, 26)) {
            $letters = chr(65 + (($number - 1) % 26)).$letters;
        }

        return $letters;
    }
}
