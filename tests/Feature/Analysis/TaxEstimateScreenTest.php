<?php

use App\Filament\Analysis\Widgets\TaxQuartersTable;
use App\Filament\Pages\TaxEstimate;
use App\Filament\Widgets\UpcomingTaxPayments;
use App\Models\Payment;
use App\Support\FinanceSettings;
use App\Support\Money;
use App\Support\TaxEstimator;
use App\Support\TaxObligations;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

pest()->group('ui', 'fiscal');

describe('Tax estimate and contribution detail', function () {
    beforeEach(function () {
        $this->travelTo(Carbon::parse('2026-11-06'));
        actingAsAdmin();
    });

    it('estimates taxes for the year with the exemptions from the settings', function () {
        app(FinanceSettings::class)->set(['vat_exempt' => true]);

        Livewire::test(TaxEstimate::class)
            ->assertSet('year', 2026)
            ->assertDontSee('Isento (definição)')
            ->assertDontSee('coeficiente de 75%')
            ->assertSee('Limite de isenção de IVA');

        Livewire::test(TaxQuartersTable::class, ['year' => 2026])
            ->assertSeeInOrder(['Jan – Mar', 'Abr – Jun', 'Jul – Set', 'Out – Dez']);
    });

    it('notes the first-enrolment months and the minimum contribution per quarter', function () {
        // A first start in August 2025: contributions are owed from August 2026 (an estimate).
        app(FinanceSettings::class)->set(['activity_started_on' => '2025-08-07', 'activity_start_kind' => 'first']);

        Livewire::test(TaxQuartersTable::class, ['year' => 2026])
            ->assertSeeInOrder([
                'Jan – Mar', 'Isento (1.º enquadramento)',
                'Abr – Jun', '1 mês isento (1.º enquadramento) · Contribuição mínima',
                'Jul – Set', 'Contribuição mínima',
            ]);

        // The first contribution is August's, paid in September.
        Livewire::test(TaxEstimate::class)
            ->assertSee('Isento (1.º enquadramento) até julho de 2026 (estimativa). A primeira contribuição, de agosto, paga-se em setembro.');
    });

    it('says in which months each quarter is paid', function () {
        app(FinanceSettings::class)->set(['activity_started_on' => '2024-01-01', 'activity_start_kind' => 'first']);

        Livewire::test(TaxQuartersTable::class, ['year' => 2026])
            ->assertSee('a de cada mês paga-se entre 10 e 20 do mês seguinte')
            ->assertSeeInOrder(['Jan – Mar', 'Mai – Jul 2026', 'Abr – Jun', 'Ago – Out 2026', 'Jul – Set', 'Nov 2026 – Jan 2027', 'Out – Dez', 'Fev – Abr 2027']);
    });

    it('notes a recognised contribution exemption', function () {
        app(FinanceSettings::class)->set(['activity_started_on' => '2024-01-01', 'activity_start_kind' => 'first', 'ss_contribution_exempt' => true]);

        Livewire::test(TaxQuartersTable::class, ['year' => 2026])
            ->assertSee('Isento (art. 157.º)')
            ->assertDontSee('Contribuição mínima');

        Livewire::test(TaxEstimate::class)
            ->assertSee('Isento (art. 157.º).');
    });

    it('shows each step of the IRS estimate', function () {
        app(FinanceSettings::class)->set([
            'activity_started_on' => '2025-08-07', 'activity_start_kind' => 'first',
            'irs_jovem' => true, 'irs_jovem_year' => ['year' => 2026, 'number' => 2],
        ]);
        Payment::factory()->create(['paid_on' => '2026-03-01', 'amount' => 2000000]);

        Livewire::test(TaxEstimate::class)
            ->assertSeeInOrder([
                'Rendimento tributável',
                'Rendimento bruto', Money::format(2000000),
                'Isento pelo IRS Jovem', Money::format(1500000),
                '0,5625', 'redução de 25 % — 2.º ano',
            ])
            ->assertSee(Money::format(281250))
            ->assertSee('sem informação vinculativa da AT')
            ->assertDontSee('Isento (definição)');
    });

    it('says why the early-years IRS reduction does not apply', function (array $settings, string $reason) {
        app(FinanceSettings::class)->set(['activity_started_on' => '2025-08-07', ...$settings]);

        Livewire::test(TaxEstimate::class)
            ->assertSee('Coeficiente 0,75 (sem redução: '.$reason.')');
    })->with([
        'salary this year' => [['activity_start_kind' => 'first', 'dependent_income_years' => [2026]], 'ano com salário ou pensão'],
        'recent restart' => [['activity_start_kind' => 'restart', 'previous_activity_ended_on' => '2023-01-31'], 'reinício a menos de 5 anos da cessação'],
    ]);

    it('gives no reduction reason once the early years are over', function () {
        app(FinanceSettings::class)->set(['activity_started_on' => '2020-08-07', 'activity_start_kind' => 'first', 'dependent_income_years' => [2026]]);

        Livewire::test(TaxEstimate::class)
            ->assertSee('Coeficiente 0,75')
            ->assertDontSee('sem redução');
    });

    it('warns on the estimate while the fiscal settings are incomplete', function () {
        Livewire::test(TaxEstimate::class)
            ->assertSee('Definições fiscais incompletas: valores aproximados');

        app(FinanceSettings::class)->set(['activity_started_on' => '2024-01-01', 'activity_start_kind' => 'first']);

        Livewire::test(TaxEstimate::class)
            ->assertDontSee('Definições fiscais incompletas');
    });

    it('leaves the monthly contribution blank in a quarter without charged months', function () {
        app(FinanceSettings::class)->set(['activity_started_on' => '2024-01-01', 'activity_start_kind' => 'first', 'ss_contribution_exempt' => true]);

        Livewire::test(TaxQuartersTable::class, ['year' => 2026])
            ->assertSee('Isento (art. 157.º)')
            ->assertDontSee(Money::format(TaxEstimator::SS_MINIMUM_CONTRIBUTION));
    });
});

describe('Current obligations', function () {
    beforeEach(function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-10'));
        actingAsAdmin();
        app(FinanceSettings::class)->set(['activity_started_on' => '2020-01-01', 'activity_start_kind' => 'first']);
    });

    it('shows the month\'s obligations on the tax estimate only for the running year', function () {
        Livewire::test(TaxEstimate::class)->assertSeeLivewire(UpcomingTaxPayments::class);
        Livewire::test(TaxEstimate::class, ['year' => 2025])->assertDontSeeLivewire(UpcomingTaxPayments::class);
    });
});

describe('Upcoming payments and declarations', function () {
    beforeEach(function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-10'));
        actingAsAdmin();
        app(FinanceSettings::class)->set(['activity_started_on' => '2020-01-01', 'activity_start_kind' => 'first', 'obligations_tracked_from' => '2026-10-01']);
    });

    it('lists what is late and lets the late declaration be marked filed', function () {
        Livewire::test(UpcomingTaxPayments::class)
            ->assertSee('Próximos pagamentos')
            ->assertSee('Era até')
            ->assertSee('Marcar como entregue')
            ->call('markFiled', 'ss-declaration:2026-Q3')
            ->assertDontSee('Marcar como entregue');

        expect(collect((new TaxObligations(app(FinanceSettings::class), CarbonImmutable::today()))->pending())->pluck('key'))
            ->not->toContain('ss-declaration:2026-Q3');
    });

    it('says whether each closed quarter\'s declaration is on record', function () {
        Livewire::test(TaxQuartersTable::class, ['year' => 2026])
            ->assertSee('Declaração')
            ->assertSee('Atrasada 10 dias')
            ->assertSee('Abrir Segurança Social');
    });
});
