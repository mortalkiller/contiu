<?php

use App\Support\BankCsvParser;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

pest()->group('banking');

it('parses a CGD-like export with ; and separate debit and credit columns', function () {
    $csv = <<<'CSV'
Consultar saldos e movimentos à ordem
Conta;0123 456789 001
Data de início;01-10-2026
Data de fim;31-10-2026

Data mov.;Data valor;Descrição;Débito;Crédito;Saldo contabilístico
23-10-2026;23-10-2026;TRF GONCALO TEIXEIRA UNIPESSOAL;;730,00;2.150,40
27-10-2026;27-10-2026;COMPRA OPENAI CHATGPT;83,74;;2.066,66
06-10-2026;06-10-2026;TRF INPI REGISTO MARCA;1.151,98;;1.420,40
;;Total;1.235,72;730,00;
CSV;

    $rows = BankCsvParser::parse($csv)->rows;

    expect($rows)->toHaveCount(3)
        ->and($rows[0])->toMatchArray(['booked_on' => '2026-10-23', 'description' => 'TRF GONCALO TEIXEIRA UNIPESSOAL', 'amount' => 73000, 'balance' => 215040])
        ->and($rows[1])->toMatchArray(['booked_on' => '2026-10-27', 'amount' => -8374])
        ->and($rows[2])->toMatchArray(['amount' => -115198]);
});

it('parses a Millennium-like export with a signed amount column, comma decimals and preamble', function () {
    $csv = <<<'CSV'
Millennium bcp
Movimentos da conta 45289012345

Data Movimento;Data Valor;Descritivo;Montante;Saldo
04/11/2026;04/11/2026;MBWAY MARIANA SOUSA;20,00;1 520,10
01/11/2026;01/11/2026;DD OVH SAS;-27,47;1 500,10
CSV;

    $rows = BankCsvParser::parse($csv)->rows;

    expect($rows)->toHaveCount(2)
        ->and($rows[0])->toMatchArray(['booked_on' => '2026-11-04', 'description' => 'MBWAY MARIANA SOUSA', 'amount' => 2000, 'balance' => 152010])
        ->and($rows[1])->toMatchArray(['booked_on' => '2026-11-01', 'amount' => -2747]);
});

it('parses a generic comma export with dot decimals and ISO dates', function () {
    $csv = <<<'CSV'
Date,Description,Amount
2026-09-25,"Transfer from Gonçalo Teixeira, Unipessoal",905.00
2026-09-27,OpenAI ChatGPT,-83.74
CSV;

    $result = BankCsvParser::parse($csv);

    expect($result->rows)->toHaveCount(2)
        ->and($result->rows[0])->toMatchArray(['booked_on' => '2026-09-25', 'description' => 'Transfer from Gonçalo Teixeira, Unipessoal', 'amount' => 90500, 'balance' => null])
        ->and($result->rows[1]['amount'])->toBe(-8374);
});

it('reports when it cannot find the columns, and parses with a manual mapping', function () {
    $csv = "x;y;z\n05.10.2026;PAGAMENTO XPTO;-12,50\n06.10.2026;RECEBIMENTO;100,00\n";

    $result = BankCsvParser::parse($csv);

    expect($result->needsMapping())->toBeTrue()
        ->and($result->headers)->toBe(['x', 'y', 'z']);

    $mapped = BankCsvParser::parse($csv, mapping: ['date' => 0, 'description' => 1, 'amount' => 2]);

    expect($mapped->needsMapping())->toBeFalse()
        ->and($mapped->rows)->toHaveCount(2)
        ->and($mapped->rows[0])->toMatchArray(['booked_on' => '2026-10-05', 'amount' => -1250]);
});

it('reads Windows-1252 files with Portuguese headers', function () {
    $csv = mb_convert_encoding("Data;Descrição;Valor\n10/10/2026;Pagamento serviço;-5,00\n", 'Windows-1252', 'UTF-8');

    $rows = BankCsvParser::parse($csv)->rows;

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['description'])->toBe('Pagamento serviço');
});

it('accepts an ordinary CSV at the row limit and rejects a longer statement', function () {
    $csv = "Date;Description;Amount\n".str_repeat("2026-10-10;Bank transfer;5.00\n", 19999);

    expect(BankCsvParser::parse($csv)->rows)->toHaveCount(19999)
        ->and(fn () => BankCsvParser::parse($csv."2026-10-11;Bank transfer;5.00\n"))
        ->toThrow(DomainException::class, BankCsvParser::LIMIT_ERROR);
});

it('uses the booking date when the export also has a value date', function () {
    $csv = "Data Lanc.;Data Valor;Descrição;Valor;Saldo\n10/07/2026;09/07/2026;TRF DE GONCALO TEIXEIRA;1,500.00;1,500.00\n";

    expect(BankCsvParser::parse($csv)->rows[0])->toMatchArray(['booked_on' => '2026-07-10', 'amount' => 150000, 'balance' => 150000]);
});

it('reads an .xlsx export with a preamble and text cells', function () {
    $result = BankCsvParser::parseSpreadsheet(bankStatementWorkbook());

    expect($result->needsMapping())->toBeFalse()
        ->and($result->rows)->toHaveCount(2)
        ->and($result->rows[0])->toMatchArray(['booked_on' => '2026-07-10', 'description' => 'TRF DE GONCALO TEIXEIRA UNIPESSOAL', 'amount' => 150000, 'balance' => 150000])
        ->and($result->rows[1])->toMatchArray(['booked_on' => '2026-10-06', 'description' => 'TRF P/ Ordenado', 'amount' => -15100, 'balance' => 134900]);
});

it('reads real date and number cells from a spreadsheet', function () {
    $result = BankCsvParser::parseSpreadsheet(bankStatementWorkbook([
        [new DateTime('2026-11-05'), new DateTime('2026-11-04'), 'TRF DE CLIENTE', 400, 3918.82],
        [new DateTime('2026-11-06'), new DateTime('2026-11-06'), 'DD OVH SAS', -27.47, 3891.35],
    ]));

    expect($result->rows)->toHaveCount(2)
        ->and($result->rows[0])->toMatchArray(['booked_on' => '2026-11-05', 'amount' => 40000, 'balance' => 391882])
        ->and($result->rows[1])->toMatchArray(['booked_on' => '2026-11-06', 'amount' => -2747]);
});

it('reads an old .xls export too', function () {
    expect(BankCsvParser::parseSpreadsheet(bankStatementWorkbook(format: 'xls'))->rows)->toHaveCount(2);
});

it('asks for values only when a workbook contains a spreadsheet formula', function () {
    $workbook = new Spreadsheet;
    $sheet = $workbook->getActiveSheet();
    $sheet->fromArray([['Date', 'Description', 'Amount'], ['2026-10-01', 'Transfer', null]]);
    $sheet->setCellValueExplicit('C2', '=1+1', DataType::TYPE_FORMULA);
    $path = tempnam(sys_get_temp_dir(), 'bank');

    try {
        (new Xlsx($workbook))->setPreCalculateFormulas(false)->save($path);

        expect(fn () => BankCsvParser::parseFile($path))
            ->toThrow(DomainException::class, BankCsvParser::FORMULA_ERROR);
    } finally {
        unlink($path);
        $workbook->disconnectWorksheets();
    }
});

it('parses a spreadsheet with a manual mapping', function () {
    $result = BankCsvParser::parseSpreadsheet(bankStatementWorkbook(), mapping: ['date' => 0, 'description' => 2, 'amount' => 3]);

    expect($result->rows)->toHaveCount(2)
        ->and($result->rows[1]['amount'])->toBe(-15100);
});
