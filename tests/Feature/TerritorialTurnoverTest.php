<?php

use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Filament\Pages\TaxEstimate;
use App\Filament\Pages\TerritorialTurnoverPage;
use App\Filament\Widgets\TaxObligationsCard;
use App\Models\Account;
use App\Models\FiscalDocument;
use App\Models\FiscalProfile;
use App\Models\Payment;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use App\Support\TaxObligations;
use App\Support\TerritorialTurnover;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10'));
});

function confirmTerritorialDocument(FiscalDocument $document, string $operationOn, ?int $eligible): void
{
    $report = TerritorialTurnover::forYear(2026);
    $report->confirmDocument($document->id, $report->documents[$document->id]['revision'], $operationOn, $eligible);
}

it('uses the confirmed operation date and net cents, including only the eligible portion and deducting credit notes once', function () {
    actingAsAdmin();
    $invoice = FiscalDocument::factory()->create(['document_date' => '2025-12-31', 'net_amount' => 10001]);
    $credit = FiscalDocument::factory()->create(['type' => FiscalDocumentType::CreditNote, 'net_amount' => -501]);
    $outside = FiscalDocument::factory()->create();
    FiscalDocument::factory()->create(['type' => FiscalDocumentType::Receipt]);
    FiscalDocument::factory()->create(['status' => FiscalDocumentStatus::Voided]);
    Payment::factory()->create(['amount' => 9999999]);

    confirmTerritorialDocument($invoice, '2026-01-02', 8001);
    confirmTerritorialDocument($credit, '2026-02-01', 501);
    confirmTerritorialDocument($outside, '2026-03-01', null);

    $report = TerritorialTurnover::forYear(2026);
    expect($report->subtotal())->toBe(7500)
        ->and($report->excludedCount())->toBe(1)
        ->and($report->unclassifiedCount())->toBe(0)
        ->and($report->coverage())->toBeNull()
        ->and(TerritorialTurnover::forYear(2025)->subtotal())->toBeNull();
});

it('invalidates classifications and coverage on source changes and rejects a stale confirmation', function () {
    actingAsAdmin();
    $document = FiscalDocument::factory()->create(['operation_date' => '2026-02-01']);
    confirmTerritorialDocument($document, '2026-02-01', 10000);
    $report = TerritorialTurnover::forYear(2026);
    $report->confirmCoverage($report->revision());
    $document->update(['net_amount' => 9000]);

    $fresh = TerritorialTurnover::forYear(2026);
    expect($fresh->subtotal())->toBeNull()
        ->and($fresh->unclassifiedCount())->toBe(1)
        ->and($fresh->coverage())->toBeNull()
        ->and(fn () => $fresh->confirmDocument($document->id, $report->documents[$document->id]['revision'], '2026-02-01', 9000))
        ->toThrow(ValidationException::class)
        ->and(fn () => $fresh->confirmCoverage($fresh->revision()))->toThrow(ValidationException::class);
});

it('requires separate coverage and uses strict legal thresholds on both screens', function (int $amount, ?string $notice) {
    actingAsAdmin();
    app(FinanceSettings::class)->set(['vat_exempt' => true, 'vat_exemption_threshold' => 2000000]);
    $document = FiscalDocument::factory()->create(['net_amount' => $amount]);
    confirmTerritorialDocument($document, '2026-11-09', $amount);
    $report = TerritorialTurnover::forYear(2026);

    $estimate = Livewire::test(TaxEstimate::class)->assertSee('Subtotal confirmado')->assertDontSee('Limiar de IVA ultrapassado');
    $report->confirmCoverage($report->revision());

    $rows = array_column((new TaxObligations(app(FinanceSettings::class), CarbonImmutable::today()))->thisMonth(), null, 'key');
    $estimate->refresh()->assertSee('Cobertura confirmada');
    Livewire::test(TaxObligationsCard::class)->assertSee('Isenção de IVA · volume nacional confirmado');
    expect($rows['vat-threshold:2026']->amount)->toBe($amount);
    if ($notice === null) {
        $estimate->assertDontSee('Limiar de IVA ultrapassado');
        expect($rows['vat-threshold:2026']->detail)->not->toContain('ultrapassado');
    } else {
        $estimate->assertSee($notice);
        expect($rows['vat-threshold:2026']->detail)->toContain($notice);
    }
})->with([
    'exactly 15,000' => [1500000, null],
    'exactly 18,750' => [1875000, 'Ultrapassou 15 000 €'],
    'one cent above 18,750' => [1875001, 'Ultrapassou 18 750 €'],
]);

it('requires all documents to be classified and invalidates coverage on newly recorded operations', function () {
    actingAsAdmin();
    $report = TerritorialTurnover::forYear(2026);
    $report->confirmCoverage($report->revision());
    expect(TerritorialTurnover::forYear(2026)->coverage())->not->toBeNull();

    FiscalDocument::factory()->create(['document_date' => '2025-12-31']);
    $report = TerritorialTurnover::forYear(2026);
    expect($report->coverage())->toBeNull()
        ->and($report->unclassifiedCount())->toBe(1);
});

it('classifies a document through the standalone page and requires a separate affirmative coverage action', function () {
    actingAsAdmin();
    $document = FiscalDocument::factory()->create(['operation_date' => '2026-10-31']);

    $this->get(TerritorialTurnoverPage::getUrl())->assertOk();
    Livewire::test(TerritorialTurnoverPage::class)
        ->assertSee($document->number)
        ->callAction(TestAction::make('classify')->table($document), data: [
            'operation_on' => '2026-10-31',
            'classification' => 'included',
        ])
        ->assertHasNoActionErrors()
        ->assertSee('Incluído')
        ->assertSee('Cobertura por confirmar')
        ->callAction('confirmCoverage', data: ['complete' => true])
        ->assertHasNoActionErrors()
        ->assertSee('Cobertura confirmada');
});

it('scopes review records to the tenant and refuses coverage writes on read-only accounts', function () {
    actingAsAdmin();
    $foreign = CurrentAccount::run(Account::factory()->create(), fn () => FiscalDocument::factory()->create());
    $report = TerritorialTurnover::forYear(2026);
    expect($report->documents)->toBeEmpty()
        ->and(fn () => $report->confirmDocument($foreign->id, 'foreign', '2026-01-01', null))->toThrow(NotFoundHttpException::class);

    $user = User::factory()->create();
    $this->account->users()->attach($user, ['role' => 'accountant']);
    $this->actingAs($user);
    expect(fn () => $report->confirmCoverage($report->revision()))->toThrow(HttpException::class);
});

it('retains a confirmed earlier crossing after a linked credit note reduces the year-end balance', function () {
    actingAsAdmin();
    $invoice = FiscalDocument::factory()->create(['net_amount' => 1900000]);
    $credit = FiscalDocument::factory()->create(['type' => FiscalDocumentType::CreditNote, 'net_amount' => 500000]);
    $credit->links()->create(['related_fiscal_document_id' => $invoice->id]);

    confirmTerritorialDocument($invoice, '2026-07-01', 1900000);
    confirmTerritorialDocument($credit, '2026-08-01', 500000);
    $report = TerritorialTurnover::forYear(2026);
    $report->confirmCoverage($report->revision());
    $report = TerritorialTurnover::forYear(2026);

    expect($report->subtotal())->toBe(1400000)
        ->and($report->notice())->toContain('Ultrapassou 18 750 €');
});

it('flags a possible same-day crossing without asserting the order of the operations', function () {
    actingAsAdmin();
    $invoice = FiscalDocument::factory()->create(['net_amount' => 1900000]);
    $credit = FiscalDocument::factory()->create(['type' => FiscalDocumentType::CreditNote, 'net_amount' => 500000]);
    $credit->links()->create(['related_fiscal_document_id' => $invoice->id]);

    confirmTerritorialDocument($invoice, '2026-07-01', 1900000);
    confirmTerritorialDocument($credit, '2026-07-01', 500000);
    $report = TerritorialTurnover::forYear(2026);
    $report->confirmCoverage($report->revision());
    $report = TerritorialTurnover::forYear(2026);

    expect($report->notice())->toBeNull()
        ->and($report->advisory())->toContain('ordem das operações');
});

it('withholds threshold notices when a credit note lacks a verified eligible original', function () {
    actingAsAdmin();
    $invoice = FiscalDocument::factory()->create(['net_amount' => 1900000]);
    $credit = FiscalDocument::factory()->create(['type' => FiscalDocumentType::CreditNote, 'net_amount' => 1000]);

    confirmTerritorialDocument($invoice, '2026-07-01', 1900000);
    confirmTerritorialDocument($credit, '2026-08-01', 1000);
    $report = TerritorialTurnover::forYear(2026);
    $report->confirmCoverage($report->revision());
    $report = TerritorialTurnover::forYear(2026);

    expect($report->notice())->toBeNull()
        ->and($report->advisory())->toContain('fatura de origem');
});

it('uses the effective historical proxy threshold and displays the VAT regime periods', function () {
    actingAsAdmin();
    app(FinanceSettings::class)->set(['vat_exempt' => false, 'vat_exemption_threshold' => 1500000]);
    FiscalProfile::factory()->create([
        'effective_from' => '2025-07-01',
        'vat_exempt' => true,
        'vat_exemption_threshold' => 100000,
    ]);
    Payment::factory()->create(['paid_on' => '2025-08-01', 'amount' => 40000, 'tax_exempt' => false]);

    $estimate = Livewire::withQueryParams(['year' => 2025])->test(TaxEstimate::class)
        ->assertSee('40,0%')
        ->assertSee('01/01 – 30/06')
        ->assertSee('01/07 – 31/12')
        ->assertSee('Perfil fiscal em vigor desde 01/07/2025')
        ->assertSee('aproximação por recebimentos');

    $document = FiscalDocument::factory()->create();
    confirmTerritorialDocument($document, '2025-08-01', 10000);

    $estimate->refresh()
        ->assertSee('Subtotal confirmado')
        ->assertSee('Perfil fiscal em vigor desde 01/07/2025')
        ->assertDontSee('aproximação por recebimentos');
});

it('requires year-end coverage before using a prior-year threshold in the current obligations', function () {
    actingAsAdmin();
    $this->travelTo(CarbonImmutable::parse('2025-11-10'));
    app(FinanceSettings::class)->set(['vat_exempt' => true]);
    $document = FiscalDocument::factory()->create(['net_amount' => 1500001]);
    $report = TerritorialTurnover::forYear(2025);
    $report->confirmDocument($document->id, $report->documents[$document->id]['revision'], '2025-11-08', 1500001);
    $report = TerritorialTurnover::forYear(2025);
    $report->confirmCoverage($report->revision());

    $this->travelTo(CarbonImmutable::parse('2026-01-02'));
    $report = TerritorialTurnover::forYear(2025);
    expect($report->coverage())->toBeNull();
    $report->confirmCoverage($report->revision());

    $rows = array_column((new TaxObligations(app(FinanceSettings::class), CarbonImmutable::today()))->thisMonth(), null, 'key');
    expect($rows['vat-threshold-previous:2025']->detail)->toContain('1 de janeiro de 2026')
        ->and($rows['vat-threshold-previous:2025']->status)->toBe('info');
});

it('does not use credit amounts above the linked original eligible total for definitive notices', function () {
    actingAsAdmin();
    $invoice = FiscalDocument::factory()->create(['net_amount' => 500000]);
    $other = FiscalDocument::factory()->create(['net_amount' => 2000000]);
    confirmTerritorialDocument($invoice, '2026-02-01', 500000);
    confirmTerritorialDocument($other, '2026-02-01', 2000000);

    foreach (FiscalDocument::factory()->count(2)->create(['type' => FiscalDocumentType::CreditNote, 'net_amount' => 300000]) as $credit) {
        $credit->links()->create(['related_fiscal_document_id' => $invoice->id]);
        confirmTerritorialDocument($credit, '2026-03-01', 300000);
    }

    $report = TerritorialTurnover::forYear(2026);
    $report->confirmCoverage($report->revision());
    $report = TerritorialTurnover::forYear(2026);
    expect($report->notice())->toBeNull()
        ->and($report->advisory())->toContain('fatura de origem');
});

it('disallows coverage confirmation without the affirmative check and allows withdrawing it', function () {
    actingAsAdmin();
    Livewire::test(TerritorialTurnoverPage::class)
        ->callAction('confirmCoverage', data: ['complete' => false])
        ->assertHasActionErrors(['complete' => 'accepted'])
        ->unmountAction()
        ->callAction('confirmCoverage', data: ['complete' => true])
        ->assertSee('Cobertura confirmada')
        ->callAction('revokeCoverage')
        ->assertSee('Cobertura por confirmar');

    expect(TerritorialTurnover::forYear(2026)->coverage())->toBeNull();
});

it('requires a confirmed euro value to include a non-euro document', function () {
    actingAsAdmin();
    $document = FiscalDocument::factory()->create(['currency' => 'USD']);
    $action = TestAction::make('classify')->table($document);
    $input = ['operation_on' => '2026-10-31', 'classification' => 'included'];

    Livewire::test(TerritorialTurnoverPage::class)
        ->callAction($action, data: $input)
        ->assertHasActionErrors(['eligible_amount' => 'required'])
        ->unmountAction()
        ->callAction($action, data: [...$input, 'eligible_amount' => '85,01'])
        ->assertHasNoActionErrors();

    expect(TerritorialTurnover::forYear(2026)->subtotal())->toBe(8501);
});

it('shows zero confirmed contribution for an excluded operation while coverage remains unconfirmed', function () {
    actingAsAdmin();
    $document = FiscalDocument::factory()->create();
    confirmTerritorialDocument($document, '2026-10-31', null);

    $report = TerritorialTurnover::forYear(2026);
    expect($report->subtotal())->toBe(0)
        ->and($report->coverage())->toBeNull();
});

it('lets a read-only owner view the review without classification or coverage actions', function () {
    $document = FiscalDocument::factory()->create();
    $owner = ownerOf($this->account);
    $this->account->update(['trial_ends_at' => now()->subDay()]);
    asAWebRequest();

    $this->actingAs($owner)
        ->get(TerritorialTurnoverPage::getUrl(panel: 'admin', tenant: $this->account))
        ->assertOk()
        ->assertSee($document->number)
        ->assertDontSee('Confirmar cobertura')
        ->assertDontSee('Classificar');
});
