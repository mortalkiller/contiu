<?php

use App\Actions\RecordAllocatedPayment;
use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Enums\PaymentMethod;
use App\Exceptions\NoCurrentAccount;
use App\Filament\Pages\TaxEstimate;
use App\Filament\Resources\FiscalDocuments\Pages\ListFiscalDocuments;
use App\Models\Account;
use App\Models\FiscalDocument;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Support\CurrentAccount;
use App\Support\DocumentedTurnover;
use App\Support\FinanceSettings;
use App\Support\Money;
use Filament\Forms\Components\Select;
use Livewire\Livewire;

it('opens a filtered list with just the euro documents behind the billed figure', function () {
    actingAsAdmin();
    $included = FiscalDocument::factory()->create(['document_date' => '2026-01-15']);
    $excluded = [
        FiscalDocument::factory()->create(['document_date' => '2025-12-31']),
        FiscalDocument::factory()->create(['document_date' => '2026-01-15', 'currency' => 'USD']),
        FiscalDocument::factory()->create(['document_date' => '2026-01-15', 'type' => FiscalDocumentType::Receipt]),
        FiscalDocument::factory()->create(['document_date' => '2026-01-15', 'status' => FiscalDocumentStatus::Voided]),
    ];

    Livewire::withQueryParams(['year' => 2026])->test(TaxEstimate::class)->assertSee('Ver documentos incluídos');
    Livewire::test(ListFiscalDocuments::class)->filterTable('billed_year', 2026)
        ->assertCanSeeTableRecords([$included])
        ->assertCanNotSeeTableRecords($excluded);
});

it('sums net issued documents by issue date and subtracts each credit note once', function () {
    FiscalDocument::factory()->count(9)->sequence(
        ['document_date' => '2026-01-01', 'operation_date' => '2025-12-30', 'net_amount' => 10001],
        ['document_date' => '2026-12-31', 'type' => FiscalDocumentType::InvoiceReceipt, 'net_amount' => 20003],
        ['document_date' => '2026-02-01', 'type' => FiscalDocumentType::CreditNote, 'net_amount' => 1001],
        ['document_date' => '2026-02-01', 'type' => FiscalDocumentType::CreditNote, 'net_amount' => -2002],
        ['document_date' => '2026-02-01', 'type' => FiscalDocumentType::Receipt],
        ['document_date' => '2026-02-01', 'status' => FiscalDocumentStatus::Voided],
        ['document_date' => '2026-02-01', 'direction' => FiscalDocumentDirection::Received],
        ['document_date' => '2026-02-01', 'currency' => 'USD'],
        ['document_date' => '2027-01-01', 'operation_date' => '2026-12-31'],
    )->create();

    $report = DocumentedTurnover::forYear(2026);

    expect($report->billedNet)->toBe(27001)
        ->and($report->documentCount)->toBe(5)
        ->and($report->nonEuroDocumentCount)->toBe(1);
});

it('counts each registered payment once in its payment year, independently of documents or allocations', function () {
    $project = Project::factory()->create();
    $first = Receivable::factory()->for($project, 'billable')->create(['amount' => 50000]);
    $second = Receivable::factory()->for($project, 'billable')->create(['amount' => 50000]);
    $split = app(RecordAllocatedPayment::class)->handle([
        'paid_on' => '2026-01-01', 'amount' => 60003, 'method' => PaymentMethod::Transfer,
    ], [$first->id => 40001, $second->id => 20002]);

    $invoiceReceipt = FiscalDocument::factory()->create([
        'document_date' => '2025-12-31', 'type' => FiscalDocumentType::InvoiceReceipt,
    ]);
    $invoiceReceipt->links()->create(['payment_id' => $split->id, 'receivable_id' => $first->id]);

    $receipt = FiscalDocument::factory()->create([
        'document_date' => '2026-01-05', 'type' => FiscalDocumentType::Receipt,
    ]);
    $receipt->links()->create(['payment_id' => $split->id]);
    Payment::factory()->create(['paid_on' => '2026-12-31', 'amount' => 5004, 'tax_exempt' => true]);
    Payment::factory()->create(['paid_on' => '2027-01-01', 'amount' => 5005]);

    $previousYear = DocumentedTurnover::forYear(2025);
    $report = DocumentedTurnover::forYear(2026);

    expect($previousYear->billedNet)->toBe(10000)
        ->and($previousYear->received)->toBe(0)
        ->and($report->billedNet)->toBeNull()
        ->and($report->received)->toBe(65007);
});

it('isolates both totals and the available issue years by account', function () {
    CurrentAccount::run(Account::factory()->create(), function () {
        FiscalDocument::factory()->create(['document_date' => '2026-06-01']);
        FiscalDocument::factory()->create(['document_date' => '2024-06-01']);
        Payment::factory()->create(['paid_on' => '2026-06-01']);
    });

    $report = DocumentedTurnover::forYear(2026);

    expect($report->billedNet)->toBeNull()
        ->and($report->received)->toBe(0)
        ->and(DocumentedTurnover::issueYears())->toBe([]);

    CurrentAccount::set(null);

    expect(fn () => DocumentedTurnover::forYear(2026))->toThrow(NoCurrentAccount::class);
});

it('shows the operational figures separately and discloses unavailable territorial coverage', function () {
    actingAsAdmin();
    FiscalDocument::factory()->create(['document_date' => '2024-06-10', 'net_amount' => 9876, 'operation_date' => '2024-06-01', 'party_tax_id' => 'PT123456789']);
    FiscalDocument::factory()->create(['document_date' => '2024-06-12', 'currency' => 'USD']);

    Livewire::withQueryParams(['year' => 2026])->test(TaxEstimate::class)
        ->assertSchemaComponentExists('year', 'content', fn (Select $select): bool => isset($select->getOptions()[2024]))
        ->assertSee('Sem documentos em EUR')
        ->set('year', 2024)
        ->assertSeeHtmlInOrder(['Faturado líquido documentado', Money::format(9876), 'Recebimentos registados', Money::format(0)])
        ->assertSee('Documentos noutras moedas excluídos do total em EUR (1).')
        ->assertSee('Volume de negócios em território nacional')
        ->assertSee('Cobertura indisponível');
});

it('keeps the cash threshold warning indicative when the receipts pass the upper trigger', function () {
    actingAsAdmin();
    app(FinanceSettings::class)->set(['vat_exempt' => true, 'vat_exemption_threshold' => 1500000]);
    Payment::factory()->create(['paid_on' => '2026-06-01', 'amount' => 1875000]);

    Livewire::withQueryParams(['year' => 2026])->test(TaxEstimate::class)
        ->assertSee('125,0%')
        ->assertSee('Limite de isenção de IVA · aproximação por recebimentos')
        ->assertSee('Alerta indicativo de IVA')
        ->assertDontSee('tens 15 dias úteis');
});

it('allows a read-only owner to open the turnover figures in their account', function () {
    FiscalDocument::factory()->create(['document_date' => '2026-06-01', 'net_amount' => 5312]);
    $owner = ownerOf($this->account);
    $this->account->update(['trial_ends_at' => now()->subDay()]);
    asAWebRequest();

    $this->actingAs($owner)
        ->get(TaxEstimate::getUrl(['year' => 2026], panel: 'admin', tenant: $this->account))
        ->assertOk()
        ->assertSee('Faturado líquido documentado')
        ->assertSee(Money::format(5312));
});
