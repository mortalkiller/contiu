<?php

use App\Enums\FiscalDocumentDirection;
use App\Filament\Pages\AnnualFiscalReview;
use App\Filament\Pages\IrsScenarioPage;
use App\Filament\Pages\SocialSecurity;
use App\Filament\Pages\TaxEstimate;
use App\Filament\Pages\VatReviewPage;
use App\Filament\Pages\WithholdingReviewPage;
use App\Filament\Widgets\TaxObligationsCard;
use App\Models\Expense;
use App\Models\FiscalDocument;
use App\Models\Payment;
use App\Support\FinanceSettings;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10'));
    actingAsAdmin();
    app(FinanceSettings::class)->set(['activity_started_on' => '2020-01-01', 'activity_start_kind' => 'first']);
});

it('shows the month\'s obligations on the tax estimate only for the running year', function () {
    Livewire::test(TaxEstimate::class)->assertSeeLivewire(TaxObligationsCard::class);
    Livewire::test(TaxEstimate::class, ['year' => 2025])->assertDontSeeLivewire(TaxObligationsCard::class);
});

it('sums up the closed quarter above the social security detail', function () {
    Payment::factory()->create(['paid_on' => '2026-07-01', 'amount' => 300000]);
    Payment::factory()->create(['paid_on' => '2026-09-30', 'amount' => 5000, 'tax_exempt' => true]);

    Livewire::withQueryParams(['quarter' => '2026-Q3'])->test(SocialSecurity::class)
        ->assertSeeInOrder(['Total registado', Money::format(305000), '2 recebimentos no trimestre'], false)
        ->assertSeeInOrder(['Usado na estimativa', Money::format(300000), Money::format(5000).' marcados como isentos ficam de fora'], false)
        ->assertSeeInOrder(['Declaração trimestral', 'Por registar'])
        ->assertSeeInOrder(['Contribuições confirmadas', '0 de 3']);
});

it('shows the period\'s VAT and the exemption limit while exempt', function () {
    app(FinanceSettings::class)->set(['vat_exempt' => true]);
    FiscalDocument::factory()->create(['document_date' => '2026-11-02', 'vat_amount' => 2300, 'total_amount' => 12300]);
    FiscalDocument::factory()->create(['document_date' => '2026-11-03', 'direction' => FiscalDocumentDirection::Received, 'vat_amount' => 1150, 'total_amount' => 6150]);

    Livewire::test(VatReviewPage::class, ['year' => 2026, 'month' => 11])
        ->assertSee('Tens isenção de IVA (art. 53.º)')
        ->assertSeeInOrder(['IVA nos documentos emitidos', Money::format(2300), '1 documento no período'], false)
        ->assertSeeInOrder(['IVA nos documentos recebidos', '1 documento no período'])
        ->assertSee('Limite de isenção');
});

it('leaves the exemption limit out of the VAT review in the normal regime', function () {
    app(FinanceSettings::class)->set(['vat_exempt' => false]);

    Livewire::test(VatReviewPage::class, ['year' => 2026, 'month' => 11])
        ->assertSee('IVA nos documentos emitidos')
        ->assertDontSee('Tens isenção de IVA')
        ->assertDontSee('Limite de isenção');
});

it('sums up the withholding review with nothing recorded', function () {
    Livewire::test(WithholdingReviewPage::class, ['year' => 2026])
        ->assertSeeInOrder(['Total retido com fonte conferida', 'Sem retenções em 2026'])
        ->assertSeeInOrder(['Por conferir', '0', 'Todas as fontes conferem'])
        ->assertSeeInOrder(['Recibos sem retenção registada', '0']);
});

it('shows what the year has on record above the IRS scenario', function () {
    FiscalDocument::factory()->create(['document_date' => '2026-03-01', 'net_amount' => 40000]);

    Livewire::test(IrsScenarioPage::class)
        ->assertSee('Simulação: não é um cálculo oficial')
        ->assertSeeInOrder(['Faturado documentado em 2026', Money::format(40000)], false)
        ->assertSee('Retido na fonte em 2026');
});

it('says whether the annual summary is ready for the accountant', function () {
    Livewire::test(AnnualFiscalReview::class)->assertSee('Pronto para o contabilista: nada por rever');

    Expense::factory()->create(['spent_on' => '2026-05-01', 'is_reviewed' => false]);

    Livewire::test(AnnualFiscalReview::class)
        ->assertDontSee('Pronto para o contabilista: nada por rever')
        ->assertSee('antes de entregares ao contabilista');
});
