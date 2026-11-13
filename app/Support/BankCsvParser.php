<?php

namespace App\Support;

use Closure;
use DomainException;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use ZipArchive;

/**
 * Reads bank statement exports — CSV, or the .xlsx/.xls some banks offer instead (Portuguese banks
 * and generic ones): finds the header row after
 * any preamble, detects the delimiter, decimal style and date format, and returns signed amounts
 * (credit +, debit −). When the columns can't be recognised, it returns the headers so the user
 * can map them by hand. Amounts and balances come out in cents.
 */
final class BankCsvParser
{
    public const int MAX_KILOBYTES = 5120;

    private const int MAX_ROWS = 20000;

    private const int MAX_COLUMNS = 64;

    private const int MAX_CELLS = 100000;

    private const int MAX_SHEETS = 8;

    private const int MAX_ZIP_ENTRIES = 256;

    private const int MAX_EXPANDED_BYTES = 12 * 1024 * 1024;

    private const int MAX_TEXT_BYTES = 6 * 1024 * 1024;

    private const int MAX_CELL_BYTES = 16384;

    public const string LIMIT_ERROR = 'The statement is too large or complex. Export a shorter period and try again.';

    public const string READ_ERROR = 'The statement could not be opened. Try exporting it again.';

    public const string FORMULA_ERROR = 'The statement contains formulas. Export it as CSV or save the spreadsheet with values only.';

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
        self::checkFileSize($contents);
        $contents = self::toUtf8($contents);
        $lines = preg_split('/\r\n|\r|\n/', $contents, self::MAX_ROWS + 2) ?: [];
        if (count($lines) > self::MAX_ROWS + 1 || (count($lines) > self::MAX_ROWS && end($lines) !== '')) {
            throw new DomainException(self::LIMIT_ERROR);
        }

        $delimiter = self::detectDelimiter($lines);
        $table = [];
        $cellCount = 0;
        foreach ($lines as $line) {
            if (strlen($line) > self::MAX_CELL_BYTES) {
                throw new DomainException(self::LIMIT_ERROR);
            }

            $cells = str_getcsv($line, $delimiter, '"', '');
            $cellCount += count($cells);
            if (count($cells) > self::MAX_COLUMNS || $cellCount > self::MAX_CELLS) {
                throw new DomainException(self::LIMIT_ERROR);
            }
            $table[] = array_map(fn (?string $cell) => trim((string) $cell), $cells);
        }

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
        self::checkFileSize($contents);
        $path = tempnam(sys_get_temp_dir(), 'bank-statement');
        if ($path === false) {
            throw new DomainException(self::READ_ERROR);
        }

        try {
            if (file_put_contents($path, $contents) !== strlen($contents)) {
                throw new DomainException(self::READ_ERROR);
            }

            return self::readWorkbook($path, str_starts_with($contents, "PK\x03\x04"), $mapping);
        } finally {
            unlink($path);
        }
    }

    /** @param array<string, int>|null $mapping */
    public static function parseFile(string $path, ?array $mapping = null): BankCsvResult
    {
        $size = filesize($path);
        if ($size === false) {
            throw new DomainException(self::READ_ERROR);
        }
        if ($size > self::MAX_KILOBYTES * 1024) {
            throw new DomainException(self::LIMIT_ERROR);
        }

        $signature = file_get_contents($path, length: 8);
        if ($signature === false) {
            throw new DomainException(self::READ_ERROR);
        }
        if (str_starts_with($signature, "PK\x03\x04")) {
            return self::readWorkbook($path, true, $mapping);
        }
        if ($signature === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            return self::readWorkbook($path, false, $mapping);
        }

        $contents = file_get_contents($path, length: self::MAX_KILOBYTES * 1024 + 1);

        return self::parse($contents === false ? throw new DomainException(self::READ_ERROR) : $contents, $mapping);
    }

    private static function readWorkbook(string $path, bool $isXlsx, ?array $mapping): BankCsvResult
    {
        if ($isXlsx) {
            self::checkArchive($path);
        }

        $reader = IOFactory::createReader($isXlsx ? IOFactory::READER_XLSX : IOFactory::READER_XLS);
        $reader->setReadEmptyCells(false);
        $reader->setReadFilter(new class(self::checkCellBudget(...)) implements IReadFilter
        {
            private int $cells = 0;

            public function __construct(private readonly Closure $checkBudget) {}

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                ($this->checkBudget)($row, Coordinate::columnIndexFromString($columnAddress), ++$this->cells);

                return true;
            }
        });

        $calendar = ExcelDate::getExcelCalendar();
        try {
            $sheets = $reader->listWorksheetInfo($path);
            if ($sheets === [] || count($sheets) > self::MAX_SHEETS) {
                throw new DomainException(self::LIMIT_ERROR);
            }
            foreach ($sheets as $sheetInfo) {
                self::checkCellBudget($sheetInfo['totalRows'], $sheetInfo['totalColumns'], 0);
            }

            $book = $reader->load($path);
            $sheet = $book->getActiveSheet();
            ExcelDate::setExcelCalendar($book->getExcelCalendar());
            $table = [];
            $count = 0;
            $textBytes = 0;

            foreach ($sheet->getCoordinates() as $coordinate) {
                [$column, $row] = Coordinate::indexesFromString($coordinate);
                self::checkCellBudget($row, $column, ++$count);
                $cell = $sheet->getCell($coordinate);
                // Older XLS readers may expose a previously saved result as a scalar.
                if ($cell->getDataType() === DataType::TYPE_FORMULA) {
                    throw new DomainException(self::FORMULA_ERROR);
                }

                $value = $cell->getValue();
                $text = match (true) {
                    (is_int($value) || is_float($value)) && ExcelDate::isDateTime($cell, $value) => ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d'),
                    is_int($value) || is_float($value) => (string) round((float) $value, 2),
                    default => trim((string) $value),
                };
                $bytes = strlen($text);
                $textBytes += $bytes;
                if ($bytes > self::MAX_CELL_BYTES || $textBytes > self::MAX_TEXT_BYTES) {
                    throw new DomainException(self::LIMIT_ERROR);
                }
                $table[$row][$column - 1] = $text;
            }

            return self::parseTable($table, $mapping, delimiter: '');
        } finally {
            if (isset($book)) {
                $book->disconnectWorksheets();
            }
            ExcelDate::setExcelCalendar($calendar);
        }
    }

    private static function checkCellBudget(int $row, int $column, int $count): void
    {
        if ($row > self::MAX_ROWS || $column > self::MAX_COLUMNS || $count > self::MAX_CELLS) {
            throw new DomainException(self::LIMIT_ERROR);
        }
    }

    private static function checkArchive(string $path): void
    {
        $archive = new ZipArchive;
        if ($archive->open($path, ZipArchive::RDONLY) !== true) {
            throw new DomainException(self::READ_ERROR);
        }

        try {
            if ($archive->numFiles > self::MAX_ZIP_ENTRIES) {
                throw new DomainException(self::LIMIT_ERROR);
            }

            $expandedBytes = 0;
            for ($index = 0; $index < $archive->numFiles; $index++) {
                $entry = $archive->statIndex($index);
                if ($entry === false || $entry['size'] > self::MAX_EXPANDED_BYTES - $expandedBytes) {
                    throw new DomainException(self::LIMIT_ERROR);
                }
                $expandedBytes += $entry['size'];
            }
        } finally {
            $archive->close();
        }
    }

    private static function checkFileSize(string $contents): void
    {
        if (strlen($contents) > self::MAX_KILOBYTES * 1024) {
            throw new DomainException(self::LIMIT_ERROR);
        }
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
            if ($cells !== [] && max(array_keys($cells)) >= 1) {
                return array_replace(array_fill(0, max(array_keys($cells)) + 1, ''), $cells);
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
