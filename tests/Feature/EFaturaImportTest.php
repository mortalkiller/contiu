<?php

use App\Filament\Pages\ExpenseDocumentImport;
use App\Models\Expense;
use App\Models\FiscalDocument;
use App\Models\Supplier;
use App\Support\EFaturaCsv;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;

pest()->group('fiscal');

function eFaturaExport(): string
{
    return implode("\n", [
        'Setor;Emitente;Nº Fatura / ATCUD;Tipo;Data Emissão;Total;IVA;Base Tributável;Situação',
        'Outros;509999990 - LOJA DEMO LDA;FT A/2291 / ABCD1234-2291;Fatura;10/11/2026;61,50 €;11,50 €;50,00 €;Registado',
        'Outros;501234560 - CLOUD DEMO SA;FR 88213 / WXYZ9876-88213;Fatura-recibo;25/02/2026;200,00 €;0,00 €;200,00 €;Registado',
        'Outros;509999990 - LOJA DEMO LDA;NC A/12 / ABCD1234-12;Nota de crédito;11/11/2026;10,00 €;0,00 €;10,00 €;Registado',
        'Outros;501234560 - CLOUD DEMO SA;FT 9 / WXYZ9876-9;Fatura;26/02/2026;12,30 €;2,30 €;10,00 €;Anulado',
    ]);
}

it('reads every supplier\'s invoices from the e-Fatura export and leaves other documents out', function () {
    ['rows' => $rows, 'skipped' => $skipped] = app(EFaturaCsv::class)->parse(mb_convert_encoding(eFaturaExport(), 'Windows-1252', 'UTF-8'));

    expect($skipped)->toBe(2)
        ->and($rows)->toHaveCount(2)
        ->and($rows[0])->toMatchArray([
            'document_date' => '2026-11-10', 'number' => 'FT A/2291', 'atcud' => 'ABCD1234-2291',
            'net_amount' => 5000, 'vat_amount' => 1150, 'total_amount' => 6150,
            'party_tax_id' => '509999990', 'party_name' => 'LOJA DEMO LDA', 'type' => 'invoice',
        ])
        ->and($rows[1])->toMatchArray(['number' => 'FR 88213', 'party_tax_id' => '501234560', 'type' => 'invoice_receipt']);
})->group('integration');

it('refuses a file that is not an e-Fatura export', function () {
    app(EFaturaCsv::class)->parse("data;referencia;liquido;iva;total\n2026-11-10;FT 1;20,00;4,60;24,60");
})->throws(DomainException::class, 'Isto não parece uma exportação do e-Fatura')->group('integration');

it('previews and imports invoices from two suppliers in one go', function () {
    actingAsAdmin();
    $shop = Supplier::factory()->create(['name' => 'Loja Demo', 'vat_number' => '509999990']);
    $expense = Expense::factory()->for($shop)->unpaid()->create([
        'spent_on' => '2026-11-10', 'invoice_number' => 'FT A/2291', 'net_amount' => 5000, 'vat_rate' => '0.23',
    ]);

    $page = Livewire::test(ExpenseDocumentImport::class)
        ->assertSet('origin', 'efatura')
        ->set('file', UploadedFile::fake()->createWithContent('efatura_2026_11.csv', eFaturaExport()))
        ->call('preview')
        ->assertHasNoErrors();

    expect(importScreenText($page))
        ->toContain('Faturas lidas 2 2 outros documentos ficaram de fora')
        ->toMatch('/FT A\/2291 .* LOJA DEMO LDA .* Exata .* FR 88213 .* CLOUD DEMO SA .* Sem candidatos/u');

    $page->set('choices.0.expense', (string) $expense->id)
        ->set('choices.1.expense', 'unlinked')
        ->call('confirm')
        ->assertHasNoErrors();

    expect(FiscalDocument::query()->pluck('party_tax_id')->sort()->values()->all())->toBe(['501234560', '509999990'])
        ->and(FiscalDocument::query()->firstWhere('number', 'FT A/2291')->links->expense_id)->toBe($expense->id);
})->group('ui');

/** As the portal exports it: UTF-8 with a BOM, every field quoted, the issuer as a bare tax ID. */
function eFaturaPurchaserExport(): string
{
    return "\xEF\xBB\xBF".implode("\n", [
        '"Nº Documento / ATCUD";"NIF Emitente";"Data Emissão";"Tipo do Documento";"Total";"IVA";"Base Tributável";"Situação"',
        '"FT A/2291 / ABCD1234-2291";"509999990";"2026-11-10";"Fatura";"61,50 €";"11,50 €";"50,00 €";"Registado"',
    ]);
}

it('reads the purchaser export as the portal writes it', function () {
    ['rows' => $rows] = app(EFaturaCsv::class)->parse(eFaturaPurchaserExport());

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray([
            'number' => 'FT A/2291', 'atcud' => 'ABCD1234-2291', 'document_date' => '2026-11-10',
            'party_tax_id' => '509999990', 'party_name' => null, 'total_amount' => 6150, 'vat_amount' => 1150,
        ]);
})->group('integration');

it('says so when the file is the export of the invoices issued, not received', function () {
    app(EFaturaCsv::class)->parse("\xEF\xBB\xBF".implode("\n", [
        '"Nº Documento / ATCUD";"NIF Adquirente";"Data Emissão";"Tipo do Documento";"Total";"IVA";"Base Tributável";"Situação"',
        '"FT ATSIRE01FT/1 / J6SHX6KD-1";"513973125";"2026-07-29";"Fatura";"255,50 €";"0,00 €";"255,50 €";"Registado"',
    ]));
})->throws(DomainException::class, 'faturas que emitiste')->group('integration');

it('names the supplier from the records when the export gives only its tax ID', function () {
    actingAsAdmin();
    Supplier::factory()->create(['name' => 'Loja Demo', 'vat_number' => '509999990']);

    $page = Livewire::test(ExpenseDocumentImport::class)
        ->set('file', UploadedFile::fake()->createWithContent('efatura.csv', eFaturaPurchaserExport()))
        ->call('preview')
        ->assertHasNoErrors();

    expect(importScreenText($page))->toMatch('/FT A\/2291 .* Loja Demo/u');
})->group('ui');
