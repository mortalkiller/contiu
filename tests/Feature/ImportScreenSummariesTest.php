<?php

use App\Filament\Pages\ExpenseDocumentImport;
use App\Filament\Pages\InvoiceXpressImport;
use App\Filament\Pages\InvoiceXpressIssuePage;
use App\Models\Expense;
use App\Models\FiscalDocument;
use App\Models\InvoiceXpressConfig;
use App\Models\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/** The page's visible text on one line, so a row of indicators can be matched in order. */
function importScreenText(Testable $page): string
{
    return preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($page->html())));
}

beforeEach(function () {
    Http::preventStrayRequests();
    $this->travelTo(now()->setDate(2026, 11, 10));
});

it('counts the InvoiceXpress documents under review by what there is to do with each', function () {
    actingAsAdmin();
    InvoiceXpressConfig::store('contiu-test', 'fake-key');
    $invoice = fn (array $overrides): array => $overrides + [
        'status' => 'final', 'type' => 'Invoice', 'date' => '02/11/2026', 'currency' => 'Euro',
        'before_taxes' => 100, 'taxes' => 23, 'total' => 123,
        'client' => ['id' => 93, 'name' => 'Cliente A', 'fiscal_id' => '501234560'],
    ];
    Http::fake(['https://contiu-test.app.invoicexpress.com/*' => Http::response([
        'invoices' => [
            $invoice(['id' => 7345, 'sequence_number' => '10/FT', 'inverted_sequence_number' => 'FT/10']),
            $invoice(['id' => 7346, 'sequence_number' => '11/FT', 'inverted_sequence_number' => 'FT/11']),
        ],
        'pagination' => ['total_entries' => 2, 'per_page' => 20, 'current_page' => 1, 'total_pages' => 1],
    ])]);

    $page = Livewire::test(InvoiceXpressImport::class)
        ->assertSee('Traz os documentos emitidos e liga-os às cobranças do Contiu.')
        ->assertDontSee('Documentos encontrados')
        ->set('from', '2026-11-01')
        ->set('to', '2026-11-30')
        ->call('preview');

    expect(importScreenText($page))->toContain('Documentos encontrados 2 Página 1 de 1 Já registados 0 Sem diferenças Por importar 2 Com conflito 0 Nenhum');

    $page->callAction('importDocument', arguments: ['index' => 0, 'review' => $page->get('rows')[0]['review_key']]);

    expect(importScreenText($page))->toContain('Já registados 1 Sem diferenças Por importar 1 Com conflito 0');
    expect(FiscalDocument::query()->count())->toBe(1);
});

it('counts the received invoices in the preview by how well they match an expense', function () {
    actingAsAdmin();
    Expense::factory()->unpaid()->create([
        'description' => 'Subscrição Demo', 'spent_on' => '2026-11-10', 'invoice_number' => 'FT DEMO/2',
        'net_amount' => 2000, 'vat_rate' => 0.23,
    ]);
    $csv = "data;referencia;liquido;iva;total\n2026-11-10;FT DEMO/2;20,00;4,60;24,60\n2026-11-09;FT DEMO/3;50,00;11,50;61,50";

    $page = Livewire::test(ExpenseDocumentImport::class)
        ->assertSee('Junta as faturas que recebeste às despesas, sem escrever os dados à mão.')
        ->assertDontSee('Faturas lidas')
        ->set('source', '123456789')
        ->set('file', UploadedFile::fake()->createWithContent('faturas.csv', $csv))
        ->call('preview')
        ->assertHasNoErrors();

    expect(importScreenText($page))->toContain(
        'Faturas lidas 2 Origem 123456789 Correspondência exata 1 Mesma data, valores e número Por confirmar 0 Nenhuma Sem candidatos 1 Cria a despesa a partir da fatura',
    );

    expect(FiscalDocument::query()->count())->toBe(0);
});

it('shows the three steps of issuing an invoice with nothing done yet', function () {
    $owner = actingAsAdmin();
    config(['services.invoicexpress.catalog_safe_accounts' => ['pilot']]);
    InvoiceXpressConfig::store('pilot', 'fake-test-credential');
    Setting::create(['key' => 'invoice_xpress_issue', 'value' => [
        'provider' => 'invoicexpress', 'user_id' => $owner->id, 'subdomain' => 'pilot',
        'item_ids' => [8], 'tax_name' => 'IVA Contiu', 'sequence_id' => 19,
    ]]);

    Livewire::test(InvoiceXpressIssuePage::class)
        ->assertSee('A fatura é emitida no InvoiceXpress e fica ligada à cobrança.')
        ->assertSeeInOrder(['1. Dados do documento', 'Por preencher', '2. Confirmar emissão', 'Depois da pré-visualização', '3. Emitida', 'Ainda sem tentativas'])
        ->assertDontSee('só se corrige com nota de crédito');

    Http::assertNothingSent();
});
