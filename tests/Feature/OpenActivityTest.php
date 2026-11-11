<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\OpenActivity;
use App\Models\FiscalProfile;
use App\Support\FinanceSettings;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/*
 * "Já abri atividade": an account created before opening the activity answers the fiscal
 * questions the sign-up left for then.
 */

pest()->group('integration', 'fiscal');

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-11 10:00'));
    actingAsAdmin();
    $this->account->forceFill(['nif' => '501964843'])->save();
});

/** The answers of a designer who opened the activity in September, only self-employed. */
function openActivityAnswers(array $overrides = []): array
{
    return [
        'activity_started_on' => '2026-09-01',
        'activity_start_kind' => 'first',
        'accounting_regime' => 'simplified',
        'situation' => ['self_only'],
        'birth_date' => '1985-02-01',
        'work_kind' => 'listed',
        'expected_turnover' => 4000,
        'vat_answer' => 'unknown',
        ...$overrides,
    ];
}

it('shows an account on hold the notice with “Já abri atividade” and the first steps', function () {
    app(FinanceSettings::class)->set(['activity_opened' => false]);

    Livewire::test(Dashboard::class)
        ->assertSee('Ainda não abriste atividade')
        ->assertSee('Já abri atividade')
        ->assertSee(OpenActivity::getUrl(), escape: false)
        ->assertSee('Primeiros passos')
        ->assertSee('Indica se tens conta bancária só para a atividade');
});

it('shows neither once the activity is open', function () {
    Livewire::test(Dashboard::class)
        ->assertDontSee('Já abri atividade')
        ->assertDontSee('Primeiros passos');
});

it('saves the answers as the tax settings and opens the activity', function () {
    app(FinanceSettings::class)->set(['activity_opened' => false]);

    Livewire::test(OpenActivity::class)
        ->fillForm(openActivityAnswers())
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertRedirect(Dashboard::getUrl());

    $settings = new FinanceSettings;

    expect($settings->activityOpened())->toBeTrue()
        ->and($settings->estimatesTaxes())->toBeTrue()
        ->and($settings->activityStartedOn()?->toDateString())->toBe('2026-09-01')
        ->and($settings->irsCoefficient())->toBe(0.75)
        ->and($settings->ssContributionExempt())->toBeFalse()
        ->and($settings->vatExempt())->toBeTrue()
        ->and($settings->paymentsExemptByDefault())->toBeTrue()
        ->and(FiscalProfile::query()->count())->toBe(0);
});

it('confirms the tax profile when the user has checked the exemption', function () {
    app(FinanceSettings::class)->set(['activity_opened' => false]);

    Livewire::test(OpenActivity::class)
        ->fillForm(openActivityAnswers(['situation' => ['pension'], 'exemption_confirmed' => true]))
        ->call('save')
        ->assertHasNoFormErrors();

    expect(FiscalProfile::query()->sole())
        ->ss_contribution_exempt->toBeTrue()
        ->vat_number->toBe('501964843')
        ->confirmed_at->not->toBeNull();
});

it('only asks the VAT with organised accounting', function () {
    app(FinanceSettings::class)->set(['activity_opened' => false]);

    Livewire::test(OpenActivity::class)
        ->fillForm(openActivityAnswers(['accounting_regime' => 'organised', 'organised_vat_answer' => 'normal', 'situation' => null, 'work_kind' => null]))
        ->call('save')
        ->assertHasNoFormErrors();

    $settings = new FinanceSettings;

    expect($settings->activityOpened())->toBeTrue()
        ->and($settings->accountingRegime())->toBe('organised')
        ->and($settings->vatExempt())->toBeFalse();
});

it('is closed once the activity is open', function () {
    $this->get(OpenActivity::getUrl())->assertForbidden();
});
