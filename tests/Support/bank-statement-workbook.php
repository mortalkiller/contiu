<?php

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Bank statement export in the layout of Pedro's bank (.xlsx with a preamble, text cells,
 * "Data Lanc." / "Data Valor" and "1,500.00"-style amounts). Synthetic data, not his file.
 *
 * @param  array<int, array<int, mixed>>|null  $movements
 */
function bankStatementWorkbook(?array $movements = null, string $format = 'xlsx'): string
{
    $rows = [
        ['HISTÓRICO DE CONTA NÚMERO 12345678901'],
        ['Moeda:', 'EUR'],
        ['', ''],
        ['Tipo:', 'Todos'],
        ['Data de:', '01/06/2026'],
        ['Data até:', '09/11/2026'],
        [null],
        ['Data Lanc.', 'Data Valor', 'Descrição', 'Valor', 'Saldo'],
        ...($movements ?? [
            ['10/07/2026', '09/07/2026', 'TRF DE GONCALO TEIXEIRA UNIPESSOAL', '1,500.00', '1,500.00'],
            ['06/10/2026', '06/10/2026', 'TRF P/ Ordenado', '-151.00', '1,349.00'],
        ]),
    ];

    $book = new Spreadsheet;
    $sheet = $book->getActiveSheet();

    foreach ($rows as $rowIndex => $cells) {
        foreach ($cells as $columnIndex => $value) {
            $coordinate = [$columnIndex + 1, $rowIndex + 1];

            if ($value instanceof DateTimeInterface) {
                $sheet->setCellValue($coordinate, ExcelDate::PHPToExcel($value));
                $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
            } elseif (is_string($value)) {
                $sheet->setCellValueExplicit($coordinate, $value, DataType::TYPE_STRING);
            } else {
                $sheet->setCellValue($coordinate, $value);
            }
        }
    }

    $base = (string) tempnam(sys_get_temp_dir(), 'bank');
    $path = $base.'.'.$format;
    ($format === 'xls' ? new Xls($book) : new Xlsx($book))->save($path);
    $contents = (string) file_get_contents($path);
    unlink($path);
    unlink($base);

    return $contents;
}
