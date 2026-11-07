<?php

namespace App\Support;

use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Reads bank statement exports — CSV, or the .xlsx/.xls some banks offer instead (Portuguese banks
 * and generic ones): finds the header row after
 * any preamble, detects the delimiter, decimal style and date format, and returns signed amounts
 * (credit +, debit −). When the columns can't be recognised, it returns the headers so the user
 * can map them by hand. Amounts and balances come out in cents.
 */
final class BankCsvParser
{
    private const DATE_HEADERS = ['data mov', 'data movimento', 'data de movimento', 'data lancamento', 'data lanc', 'data operacao', 'data', 'date', 'data valor'];

    private const DESCRIPTION_HEADERS = ['descricao', 'descritivo', 'descricao do movimento', 'movimento', 'designacao', 'description', 'detalhe'];

    private const AMOUNT_HEADERS = ['montante', 'valor', 'importancia', 'amount', 'montante eur', 'valor eur', 'montante em eur'];

    private const DEBIT_HEADERS = ['debito', 'debitos', 'debit', 'debito eur'];

    private const CREDIT_HEADERS = ['credito', 'creditos', 'credit', 'credito eur'];

    private const BALANCE_HEADERS = ['saldo', 'saldo contabilistico', 'saldo disponivel', 'saldo apos movimento', 'balance'];

    private const DATE_FORMATS = ['d-m-Y', 'd/m/Y', 'd.m.Y', 'Y-m-d', 'Y/m/d', 'd-m-y', 'd/m/y'];

    /**
     * @param  array{date?: int, description?: int, amount?: int, debit?: int, credit?: int, balance?: int}|null  $mapping
     */
    public static function parse(string $contents, ?array $mapping = null): BankCsvResult
    {
        $contents = self::toUtf8($contents);
        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];
        $delimiter = self::detectDelimiter($lines);
        $table = array_map(fn (string $line) => array_map(fn (?string $cell) => trim((string) $cell), str_getcsv($line, $delimiter, '"', '')), $lines);

        return self::parseTable($table, $mapping, $delimiter);
    }

    /**
     * Same as parse(), for the .xlsx/.xls export. Date cells become Y-m-d and number cells plain
     * "1234.56" strings, so the rest of the reading is shared with the CSV.
     *
     * @param  array{date?: int, description?: int, amount?: int, debit?: int, credit?: int, balance?: int}|null  $mapping
     */
    public static function parseSpreadsheet(string $contents, ?array $mapping = null): BankCsvResult
    {
        $path = tempnam(sys_get_temp_dir(), 'bank-statement');
        file_put_contents($path, $contents);

        try {
            $sheet = IOFactory::load($path, 0, [IOFactory::READER_XLSX, IOFactory::READER_XLS])->getActiveSheet();
        } finally {
            unlink($path);
        }

        $table = [];
        $lastColumn = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        for ($row = 1, $lastRow = $sheet->getHighestDataRow(); $row <= $lastRow; $row++) {
            $cells = [];

            for ($column = 1; $column <= $lastColumn; $column++) {
                $cell = $sheet->getCell([$column, $row]);
                $value = $cell->getCalculatedValue();

                $cells[] = match (true) {
                    is_numeric($value) && ! is_string($value) && ExcelDate::isDateTime($cell) => ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d'),
                    is_int($value) || is_float($value) => (string) round((float) $value, 2),
                    default => trim((string) $value),
                };
            }

            $table[] = $cells;
        }

        return self::parseTable($table, $mapping, delimiter: '');
    }

    /**
     * @param  array<int, array<int, string>>  $table
     * @param  array{date?: int, description?: int, amount?: int, debit?: int, credit?: int, balance?: int}|null  $mapping
     */
    private static function parseTable(array $table, ?array $mapping, string $delimiter): BankCsvResult
    {
        $table = array_values(array_filter($table, fn (array $cells) => count(array_filter($cells, fn ($cell) => $cell !== '')) > 0));

        if ($mapping !== null) {
            return self::readRows($table, 0, $mapping, $delimiter, self::firstHeaderLike($table));
        }

        foreach ($table as $index => $cells) {
            $columns = self::detectColumns($cells);

            if ($columns !== null) {
                return self::readRows($table, $index + 1, $columns, $delimiter, $cells);
            }
        }

        return new BankCsvResult([], self::firstHeaderLike($table), $delimiter, mappingNeeded: true);
    }

    /**
     * @param  array<int, array<int, string>>  $table
     * @param  array<string, int>  $columns
     * @param  array<int, string>  $headers
     */
    private static function readRows(array $table, int $from, array $columns, string $delimiter, array $headers): BankCsvResult
    {
        $data = array_slice($table, $from);
        $decimalComma = self::usesDecimalComma($data, $columns);
        $rows = [];

        foreach ($data as $cells) {
            $date = self::parseDate($cells[$columns['date']] ?? '');

            if ($date === null) {
                continue; // footer totals, blank or repeated header lines
            }

            if (isset($columns['amount'])) {
                $amount = self::parseNumber($cells[$columns['amount']] ?? '', $decimalComma);
            } else {
                $debit = self::parseNumber($cells[$columns['debit']] ?? '', $decimalComma);
                $credit = self::parseNumber($cells[$columns['credit']] ?? '', $decimalComma);
                $amount = ($credit === null && $debit === null) ? null : abs($credit ?? 0) - abs($debit ?? 0);
            }

            if ($amount === null || $amount === 0) {
                continue;
            }

            $rows[] = [
                'booked_on' => $date,
                'description' => preg_replace('/\s+/u', ' ', $cells[$columns['description']] ?? '') ?? '',
                'amount' => $amount,
                'balance' => isset($columns['balance']) ? self::parseNumber($cells[$columns['balance']] ?? '', $decimalComma) : null,
            ];
        }

        return new BankCsvResult($rows, $headers, $delimiter, mappingNeeded: false);
    }

    /**
     * @param  array<int, string>  $cells
     * @return array<string, int>|null
     */
    private static function detectColumns(array $cells): ?array
    {
        $normalised = array_map(self::normalise(...), $cells);
        $find = function (array $names) use ($normalised): ?int {
            foreach ($names as $name) {
                $index = array_search($name, $normalised, true);

                if ($index !== false) {
                    return $index;
                }
            }

            return null;
        };

        $columns = array_filter([
            'date' => $find(self::DATE_HEADERS),
            'description' => $find(self::DESCRIPTION_HEADERS),
            'amount' => $find(self::AMOUNT_HEADERS),
            'debit' => $find(self::DEBIT_HEADERS),
            'credit' => $find(self::CREDIT_HEADERS),
            'balance' => $find(self::BALANCE_HEADERS),
        ], fn ($index) => $index !== null);

        $hasAmount = isset($columns['amount']) || (isset($columns['debit'], $columns['credit']));

        if (! isset($columns['date'], $columns['description']) || ! $hasAmount) {
            return null;
        }

        if (isset($columns['debit'], $columns['credit'])) {
            unset($columns['amount']);
        }

        return $columns;
    }

    private static function normalise(string $header): string
    {
        $header = Str::lower(Str::ascii($header));
        $header = preg_replace('/\(.*?\)|[^a-z0-9 ]/', ' ', $header) ?? '';

        return trim(preg_replace('/\s+/', ' ', $header) ?? '');
    }

    /** @param array<int, string> $lines */
    private static function detectDelimiter(array $lines): string
    {
        $best = ';';
        $bestScore = -1;

        foreach ([';', "\t", ','] as $delimiter) {
            $score = count(array_filter($lines, fn (string $line) => substr_count($line, $delimiter) >= 2));

            if ($score > $bestScore) {
                [$best, $bestScore] = [$delimiter, $score];
            }
        }

        return $best;
    }

    /**
     * @param  array<int, array<int, string>>  $data
     * @param  array<string, int>  $columns
     */
    private static function usesDecimalComma(array $data, array $columns): bool
    {
        $indexes = array_values(array_intersect_key($columns, array_flip(['amount', 'debit', 'credit', 'balance'])));

        foreach ($data as $cells) {
            foreach ($indexes as $index) {
                $value = preg_replace('/[\s\x{00A0}\x{202F}€]/u', '', $cells[$index] ?? '') ?? '';

                if (preg_match('/,\d{1,2}$/', $value)) {
                    return true;
                }

                if (preg_match('/\.\d{1,2}$/', $value)) {
                    return false;
                }
            }
        }

        return false;
    }

    /** The cell as cents (bank amounts are always to the cent). */
    private static function parseNumber(string $value, bool $decimalComma): ?int
    {
        $value = preg_replace('/[\s\x{00A0}\x{202F}€]|EUR/u', '', $value) ?? '';

        if ($value === '' || $value === '-') {
            return null;
        }

        $negative = str_starts_with($value, '-') || str_ends_with($value, '-') || (str_starts_with($value, '(') && str_ends_with($value, ')'));
        $value = trim($value, '-+()');
        $value = $decimalComma ? str_replace(['.', ','], ['', '.'], $value) : str_replace(',', '', $value);

        if (! is_numeric($value)) {
            return null;
        }

        return ($negative ? -1 : 1) * Money::toCents($value);
    }

    private static function parseDate(string $value): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        foreach (self::DATE_FORMATS as $format) {
            $date = \DateTimeImmutable::createFromFormat('!'.$format, $value);

            if ($date !== false && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<int, string>>  $table
     * @return array<int, string>
     */
    private static function firstHeaderLike(array $table): array
    {
        foreach ($table as $cells) {
            if (count($cells) >= 2) {
                return $cells;
            }
        }

        return [];
    }

    private static function toUtf8(string $contents): string
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        return mb_check_encoding($contents, 'UTF-8') ? $contents : mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
    }
}
