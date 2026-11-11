<?php

use App\Filament\Pages\FinanceSettingsPage;
use App\Models\Account;
use App\Models\FiscalProfile;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;

pest()->group('ui', 'fiscal');

function fiscalProfileInput(string $name, string $effectiveFrom): array
{
    return [
        'legal_name' => $name,
        'country_code' => 'pt',
        'vat_number' => 'PT123456789',
        'vat_regime' => 'Regime normal',
        'effective_from' => $effectiveFrom,
        'confirmed' => true,
    ];
}

it('confirms dated versions through fiscal settings and keeps the history separate from legacy settings', function () {
    $this->travelTo(Carbon::parse('2026-11-10 12:00'));
    $user = actingAsAdmin();
    app(FinanceSettings::class)->set([
        'activity_started_on' => '2020-03-01',
        'dependent_income_years' => [2025],
        'vat_exempt' => true,
        'ss_contribution_exempt' => true,
    ]);

    expect(FiscalProfile::effectiveOn(today()))->toBeNull();

    $screen = Livewire::test(FinanceSettingsPage::class)
        ->assertSee('As estimativas continuam a usar as definições abaixo.')
        ->callAction('confirmFiscalProfile', [
            ...fiscalProfileInput('Primeiro titular', '2026-01-01'),
            'activity_started_on' => '2024-04-12',
            'has_dependent_employment' => 'yes',
            'recognised_exemptions' => 'Isenção reconhecida pela Segurança Social',
        ])
        ->assertHasNoFormErrors()
        ->callAction('confirmFiscalProfile', [
            ...fiscalProfileInput('Titular corrigido', '2026-01-01'),
            'activity_started_on' => '2024-04-13',
            'has_dependent_employment' => 'no',
        ])
        ->assertHasNoFormErrors()
        ->callAction('confirmFiscalProfile', [
            ...fiscalProfileInput('Titular futuro', '2027-01-01'),
            'activity_started_on' => null,
            'has_dependent_employment' => null,
            'recognised_exemptions' => null,
        ])
        ->assertHasNoFormErrors()
        ->assertSee('Em vigor desde')
        ->assertSee('Primeiro titular')
        ->assertSee('12/04/2024')
        ->assertSee('13/04/2024')
        ->assertSee('Isenção reconhecida pela Segurança Social')
        ->assertSee('Titular futuro');

    expect(Str::before($screen->html(stripInitialData: true), 'Histórico do perfil fiscal'))
        ->toContain('Titular corrigido')
        ->not->toContain('Titular futuro');

    $effective = FiscalProfile::effectiveOn(today());
    $future = FiscalProfile::effectiveOn(Carbon::parse('2027-01-01'));
    $initial = FiscalProfile::query()->where('legal_name', 'Primeiro titular')->sole();

    expect($effective->legal_name)->toBe('Titular corrigido')
        ->and($effective->country_code)->toBe('PT')
        ->and($effective->confirmed_by)->toBe($user->id)
        ->and($effective->confirmed_at->toDateTimeString())->toBe('2026-11-10 12:00:00')
        ->and($effective->activity_started_on->toDateString())->toBe('2024-04-13')
        ->and($effective->has_dependent_employment)->toBeFalse()
        ->and($initial->has_dependent_employment)->toBeTrue()
        ->and($initial->recognised_exemptions)->toBe('Isenção reconhecida pela Segurança Social')
        ->and($future->legal_name)->toBe('Titular futuro')
        ->and($future->activity_started_on)->toBeNull()
        ->and($future->has_dependent_employment)->toBeNull()
        ->and($future->recognised_exemptions)->toBeNull()
        ->and(FiscalProfile::effectiveOn(Carbon::parse('2025-12-31')))->toBeNull()
        ->and(app(FinanceSettings::class)->vatExempt())->toBeTrue()
        ->and(app(FinanceSettings::class)->activityStartedOn()->toDateString())->toBe('2020-03-01')
        ->and(app(FinanceSettings::class)->dependentIncomeYears())->toBe([2025])
        ->and(app(FinanceSettings::class)->ssContributionExempt())->toBeTrue();

    $other = Account::factory()->create();

    CurrentAccount::run($other, function () {
        expect(FiscalProfile::query()->count())->toBe(0)
            ->and(FiscalProfile::effectiveOn(today()))->toBeNull();

        Livewire::test(FinanceSettingsPage::class)
            ->assertDontSee('Primeiro titular')
            ->assertDontSee('Titular futuro');
    });
});

it('requires explicit confirmation before adding a fiscal profile version', function () {
    actingAsAdmin();

    Livewire::test(FinanceSettingsPage::class)
        ->callAction('confirmFiscalProfile', [
            ...fiscalProfileInput('Titular', '2026-11-10'),
            'confirmed' => false,
        ])
        ->assertHasFormErrors(['confirmed' => 'accepted']);

    expect(FiscalProfile::query()->count())->toBe(0);
});

it('lets a read-only account owner see fiscal settings without the confirmation action', function () {
    $this->account->update(['billing_exempt' => false, 'trial_ends_at' => now()->subDay()]);
    $owner = User::factory()->create();
    $this->account->users()->attach($owner, ['role' => 'owner']);

    $this->actingAs($owner);

    Livewire::test(FinanceSettingsPage::class)
        ->assertActionHidden('confirmFiscalProfile');

    expect(FiscalProfile::query()->count())->toBe(0);
});

it('shows the first confirmed profile in the current section on the same response', function () {
    $this->travelTo(Carbon::parse('2026-11-10 12:00'));
    actingAsAdmin();

    $screen = Livewire::test(FinanceSettingsPage::class)
        ->assertSee('Sem versão em vigor')
        ->callAction('confirmFiscalProfile', fiscalProfileInput('Primeiro perfil em vigor', '2026-11-10'))
        ->assertHasNoFormErrors();

    expect(Str::before($screen->html(stripInitialData: true), 'Histórico do perfil fiscal'))
        ->toContain('Primeiro perfil em vigor')
        ->not->toContain('Sem versão em vigor');
});
