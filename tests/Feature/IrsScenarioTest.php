<?php

use App\Enums\FiscalDocumentType;
use App\Filament\Pages\IrsScenarioPage;
use App\Models\Account;
use App\Models\FiscalDocument;
use App\Models\Payment;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use App\Support\IrsScenario;
use App\Support\IrsSettlementEstimate;
use App\Support\Money;
use App\Support\WithholdingReview;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Livewire\Livewire;

pest()->group('fiscal');

function recordIrsScenarioRetention(): FiscalDocument
{
    $payment = Payment::factory()->create(['paid_on' => '2026-06-10', 'amount' => 100000]);
    $document = FiscalDocument::factory()->create([
        'type' => FiscalDocumentType::Receipt,
        'document_date' => '2026-06-10',
        'net_amount' => 120000,
        'vat_amount' => 0,
        'total_amount' => 120000,
    ]);
    $document->links()->create(['payment_id' => $payment->id]);
    WithholdingReview::record($document->id, 20000, 2026);

    return $document;
}

it('compares an annual documented baseline and a hypothetical retainer on the read-only page', function () {
    FiscalDocument::factory()->create([
        'document_date' => '2026-06-10',
        'net_amount' => 1200000,
        'vat_amount' => 0,
        'total_amount' => 1200000,
    ]);
    FiscalDocument::factory()->create(['document_date' => '2026-06-10', 'currency' => 'USD']);
    recordIrsScenarioRetention();
    recordIrsScenarioRetention()->update(['status' => 'voided']);
    $this->account->update(['trial_ends_at' => now()->subDay()]);
    $owner = ownerOf($this->account);
    asAWebRequest();

    $this->actingAs($owner)
        ->get(IrsScenarioPage::getUrl(panel: 'admin', tenant: $this->account))
        ->assertOk()
        ->assertSee('Cenário de IRS')
        ->assertSee('Estimativa de liquidação de IRS');

    Filament::setTenant($this->account);
    expect(CurrentAccount::readOnly())->toBeTrue();

    Livewire::test(IrsScenarioPage::class)
        ->fillForm(['year' => '2026', 'monthlyNet' => '500,25', 'months' => '6', 'scenarioRetention' => '75,50'])
        ->call('compare')
        ->assertHasNoFormErrors()
        ->assertSet('result.baselineBilling', 1200000)
        ->assertSet('result.retainerBilling', 300150)
        ->assertSet('result.scenarioBilling', 1500150)
        ->assertSet('result.baselineRetention', 20000)
        ->assertSet('result.combinedRetention', 27550)
        ->assertSee(Money::format(1500150))
        ->assertSee(Money::format(27550))
        ->assertSee('Estimativa de liquidação de IRS')
        ->assertSee('1 documento noutra moeda')
        ->assertSee('1 registo de retenção a reconfirmar');

    $this->assertDatabaseCount('fiscal_documents', 4);
    $this->assertDatabaseCount('withholding_records', 2);
    $this->assertDatabaseCount('payments', 2);
    $this->assertDatabaseEmpty('retainers');
})->group('ui');

it('keeps the increment useful when documented billing and withholding are both missing', function () {
    CurrentAccount::run(Account::factory()->create(), function () {
        FiscalDocument::factory()->create(['document_date' => '2026-06-10', 'net_amount' => 1200000]);
        recordIrsScenarioRetention();
    });

    expect(IrsScenario::compare(2026, 50025, 6, 0))->toMatchArray([
        'baselineBilling' => null,
        'retainerBilling' => 300150,
        'scenarioBilling' => null,
        'baselineRetention' => null,
        'scenarioRetention' => 0,
        'combinedRetention' => null,
    ]);
})->group('integration');

it('distinguishes omitted scenario withholding from an explicit zero in the comparison', function () {
    actingAsAdmin();
    recordIrsScenarioRetention();

    Livewire::test(IrsScenarioPage::class)
        ->fillForm(['year' => '2026', 'monthlyNet' => '1000', 'months' => '12'])
        ->call('compare')
        ->assertHasNoFormErrors()
        ->assertSet('result.baselineRetention', 20000)
        ->assertSet('result.scenarioRetention', null)
        ->assertSet('result.combinedRetention', null)
        ->assertSee('Por indicar')
        ->fillForm(['scenarioRetention' => '0,00'])
        ->call('compare')
        ->assertHasNoFormErrors()
        ->assertSet('result.scenarioRetention', 0)
        ->assertSet('result.combinedRetention', 20000)
        ->assertDontSee('Por indicar');
})->group('ui');

it('rejects invalid inputs and clears an earlier comparison', function (string $field, string $invalid) {
    actingAsAdmin();

    Livewire::test(IrsScenarioPage::class)
        ->fillForm(['year' => '2026', 'monthlyNet' => '1 000,05', 'months' => '12'])
        ->call('compare')
        ->assertHasNoFormErrors()
        ->assertSet('result.retainerBilling', 1200060)
        ->fillForm([$field => $invalid])
        ->call('compare')
        ->assertHasFormErrors([$field])
        ->assertSet('result', null);
})->with([
    'invalid year' => ['year', '0'],
    'too many months' => ['months', '13'],
    'zero months' => ['months', '0'],
    'zero monthly amount' => ['monthlyNet', '0,00'],
    'more than two decimal places' => ['monthlyNet', '1000,001'],
    'negative retention' => ['scenarioRetention', '-5'],
])->group('ui');

it('returns 404 when an owner opens another account scenario page', function () {
    $owner = ownerOf($this->account);
    $foreign = Account::factory()->create();
    asAWebRequest();

    $this->actingAs($owner)
        ->get(IrsScenarioPage::getUrl(panel: 'admin', tenant: $foreign))
        ->assertNotFound();
})->group('integration');

it('estimates the 2026 IRS settlement for both scenarios from explicitly entered taxable amounts and credits', function () {
    actingAsAdmin();

    Livewire::test(IrsScenarioPage::class)
        ->fillForm([
            'year' => '2026', 'monthlyNet' => '500', 'months' => '6', 'estimateSettlement' => true,
            'baselineTaxable' => '10000', 'baselineDeductions' => '200', 'baselineTaxWithholding' => '700', 'baselineAdvancePayments' => '100',
            'combinedTaxable' => '20000', 'combinedDeductions' => '400', 'combinedTaxWithholding' => '3600', 'combinedAdvancePayments' => '100',
        ])
        ->call('compare')
        ->assertHasNoFormErrors()
        ->assertSet('result.settlement.baseline.grossTax', 126974)
        ->assertSet('result.settlement.baseline.payable', 26974)
        ->assertSet('result.settlement.combined.grossTax', 326014)
        ->assertSet('result.settlement.combined.refund', 83986)
        ->assertSee('IRS estimado a pagar')
        ->assertSee(Money::format(83986));
})->group('ui');

it('respects the settlement minimums and the solidarity bracket', function () {
    $calculator = IrsSettlementEstimate::class;
    expect($calculator::calculate(2026, 1000000, 0, 124475, 0)['payable'])->toBe(0)
        ->and($calculator::calculate(2026, 1000000, 0, 124474, 0)['payable'])->toBe(2500)
        ->and($calculator::calculate(2026, 0, 50000, 999, 0)['refund'])->toBe(0)
        ->and($calculator::calculate(2026, 0, 50000, 1000, 0)['refund'])->toBe(1000)
        ->and($calculator::calculate(2026, 8100000, 0, 0, 0)['solidarity'])->toBe(2500)
        ->and(fn () => $calculator::calculate(2027, 1000000, 0, 0, 0))->toThrow(InvalidArgumentException::class);
})->group('integration');

it('requires all manually checked inputs for an estimate and leaves the billing comparison available in other years', function () {
    actingAsAdmin();
    Livewire::test(IrsScenarioPage::class)
        ->fillForm(['year' => '2026', 'monthlyNet' => '500', 'months' => '6', 'estimateSettlement' => true])
        ->call('compare')
        ->assertHasFormErrors(['baselineTaxable', 'combinedTaxWithholding'])
        ->assertSet('result', null)
        ->fillForm(['year' => '2027', 'estimateSettlement' => false])
        ->call('compare')
        ->assertHasNoFormErrors()
        ->assertSet('result.year', 2027)
        ->assertSet('result.settlement', null);
})->group('ui');

describe('Annual scenario summary', function () {
    beforeEach(function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-10'));
        actingAsAdmin();
        app(FinanceSettings::class)->set(['activity_started_on' => '2020-01-01', 'activity_start_kind' => 'first']);
    });

    it('shows what the year has on record above the IRS scenario', function () {
        FiscalDocument::factory()->create(['document_date' => '2026-03-01', 'net_amount' => 40000]);

        Livewire::test(IrsScenarioPage::class)
            ->assertSee('Simulação: não é um cálculo oficial')
            ->assertSeeInOrder(['Faturado documentado em 2026', Money::format(40000)], false)
            ->assertSee('Retido na fonte em 2026');
    })->group('ui');
});
