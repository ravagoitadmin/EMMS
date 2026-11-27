<?php

namespace App\Services\Google;

use Google\Client as GoogleClient;
use Google\Service\Sheets;
use Google\Service\Sheets\BatchUpdateSpreadsheetRequest;
use Google\Service\Sheets\BatchUpdateValuesRequest;
use Google\Service\Sheets\Request;
use Google\Service\Sheets\ValueRange;
use RuntimeException;

/**
 * Reads a tab of the AppSheet spreadsheet and changes it, through the service account in
 * storage/google-sheets-credentials.json (it needs Editor access on the spreadsheet): single cells, rows added
 * below the last one, and whole rows deleted. Nothing else is touched.
 */
class GoogleSheetWriter
{
    private const BATCH = 500;

    private ?Sheets $service = null;

    /**
     * The raw values of a tab as shown in the sheet, header row first.
     *
     * @return list<list<mixed>>
     */
    public function values(string $tab): array
    {
        return $this->service()->spreadsheets_values->get($this->spreadsheetId(), $this->range($tab, 'A1:ZZ'))->getValues() ?? [];
    }

    /**
     * Writes values as typed (RAW), so text such as "=SUM(A1)" stays text and is never read as a formula.
     *
     * @param  list<array{cell: string, value: string|int|float}>  $cells  cell in A1 notation (for example C5) and its new value
     */
    public function writeCells(string $tab, array $cells): void
    {
        foreach (array_chunk($cells, self::BATCH) as $chunk) {
            $request = new BatchUpdateValuesRequest;
            $request->setValueInputOption('RAW');
            $request->setData(array_map(function (array $cell) use ($tab): ValueRange {
                $range = new ValueRange;
                $range->setRange($this->range($tab, $cell['cell']));
                $range->setValues([[$cell['value']]]);

                return $range;
            }, $chunk));

            $this->service()->spreadsheets_values->batchUpdate($this->spreadsheetId(), $request);
        }
    }

    /**
     * Adds rows below the last used row ($firstFreeRow is the row after it), written as typed (RAW). The rows
     * below that row must be empty; nothing that is already in the sheet is moved or overwritten.
     *
     * @param  list<list<string|int|float>>  $rows
     */
    public function appendRows(string $tab, int $firstFreeRow, array $rows): void
    {
        foreach (array_chunk($rows, self::BATCH) as $chunk) {
            $range = new ValueRange;
            $range->setValues($chunk);

            $this->service()->spreadsheets_values->append(
                $this->spreadsheetId(),
                $this->range($tab, 'A'.$firstFreeRow),
                $range,
                ['valueInputOption' => 'RAW', 'insertDataOption' => 'INSERT_ROWS'],
            );

            $firstFreeRow += count($chunk);
        }
    }

    /**
     * Deletes whole rows (1-based row numbers) from a tab. Neighbouring rows are deleted together, and the bottom
     * rows go first so the numbers of the rows still to delete do not shift.
     *
     * @param  list<int>  $rowNumbers
     */
    public function deleteRows(string $tab, array $rowNumbers): void
    {
        $ranges = self::deletionRanges($rowNumbers);

        if ($ranges === []) {
            return;
        }

        $sheetId = $this->sheetId($tab);

        foreach (array_chunk($ranges, self::BATCH) as $chunk) {
            $request = new BatchUpdateSpreadsheetRequest;
            $request->setRequests(array_map(fn (array $range): Request => new Request([
                'deleteDimension' => [
                    'range' => [
                        'sheetId' => $sheetId,
                        'dimension' => 'ROWS',
                        'startIndex' => $range[0] - 1,
                        'endIndex' => $range[1],
                    ],
                ],
            ]), $chunk));

            $this->service()->spreadsheets->batchUpdate($this->spreadsheetId(), $request);
        }
    }

    /**
     * Rows to delete as [first, last] ranges of neighbouring rows, bottom range first. The header row (1) is never
     * deleted.
     *
     * @param  list<int>  $rowNumbers
     * @return list<array{0: int, 1: int}>
     */
    public static function deletionRanges(array $rowNumbers): array
    {
        $rowNumbers = array_values(array_unique(array_filter($rowNumbers, fn (int $row): bool => $row > 1)));
        rsort($rowNumbers);
        $ranges = [];

        foreach ($rowNumbers as $row) {
            $last = array_key_last($ranges);

            if ($last !== null && $ranges[$last][0] === $row + 1) {
                $ranges[$last][0] = $row;
            } else {
                $ranges[] = [$row, $row];
            }
        }

        return $ranges;
    }

    /** The numeric id of a tab (the gid in its URL), needed to delete rows. */
    private function sheetId(string $tab): int
    {
        foreach ($this->service()->spreadsheets->get($this->spreadsheetId(), ['fields' => 'sheets.properties'])->getSheets() as $sheet) {
            if ($sheet->getProperties()->getTitle() === $tab) {
                return (int) $sheet->getProperties()->getSheetId();
            }
        }

        throw new RuntimeException("The \"{$tab}\" tab does not exist.");
    }

    private function range(string $tab, string $cells): string
    {
        return "'".str_replace("'", "''", $tab)."'!".$cells;
    }

    private function service(): Sheets
    {
        if ($this->service !== null) {
            return $this->service;
        }

        $credentialsPath = storage_path('google-sheets-credentials.json');

        if (! is_file($credentialsPath)) {
            throw new RuntimeException('Google Sheets credentials file not found at storage/google-sheets-credentials.json');
        }

        $client = new GoogleClient;
        $client->setApplicationName('EMS Equipment Sheet Sync');
        $client->setScopes([Sheets::SPREADSHEETS]);
        $client->setAuthConfig($credentialsPath);

        return $this->service = new Sheets($client);
    }

    private function spreadsheetId(): string
    {
        $spreadsheetId = config('services.google.spreadsheet_id');

        if (! is_string($spreadsheetId) || trim($spreadsheetId) === '') {
            throw new RuntimeException('GOOGLE_SPREADSHEET_ID is not configured.');
        }

        return $spreadsheetId;
    }
}
