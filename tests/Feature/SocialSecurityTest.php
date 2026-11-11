<?php

use App\Exceptions\AccountIsReadOnly;
use App\Filament\Pages\SocialSecurity as SocialSecurityPage;
use App\Models\Account;
use App\Models\Payment;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use App\Support\Money;
use App\Support\SocialSecurity;
use Carbon\CarbonImmutable;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

pest()->group('fiscal');

it('only prepares closed quarters using the Lisbon calendar boundary', function () {
    actingAsAdmin();
    $this->travelTo(CarbonImmutable::parse('2026-12-31 23:59:59', 'Europe/Lisbon'));
    $security = app(SocialSecurity::class);

    expect($security->latestClosedQuarter())->toBe('2026-Q3')
        ->and($security->closedQuarter('2026-Q4'))->toBeNull()
        ->and($security->closedQuarter('2026-Q0'))->toBeNull();
    expect(fn () => $security->recordDeclaration('2026-Q4', []))->toThrow(ValidationException::class);

    $this->travelTo(CarbonImmutable::parse('2027-01-01 00:00:00', 'Europe/Lisbon'));

    expect($security->latestClosedQuarter())->toBe('2026-Q4')
        ->and($security->closedQuarter('2026-Q4')?->toDateString())->toBe('2026-10-01');
})->group('integration');

it('lists each tenant receipt once by payment date and keeps the estimator basis separate', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10'));
    app(FinanceSettings::class)->set(['activity_started_on' => '2020-01-01', 'activity_start_kind' => 'first']);
    $paid = Payment::factory()->create(['paid_on' => '2026-07-01', 'amount' => 300000]);
    $exempt = Payment::factory()->create(['paid_on' => '2026-09-30', 'amount' => 5000, 'tax_exempt' => true]);
    Payment::factory()->create(['paid_on' => '2026-10-01']);
    CurrentAccount::run(Account::factory()->create(), fn () => Payment::factory()->create(['paid_on' => '2026-07-01']));

    $details = app(SocialSecurity::class)->preparation('2026-Q3');

    expect($details['receipts']->modelKeys())->toBe([$paid->id, $exempt->id])
        ->and($details['receipts']->sum('amount'))->toBe(305000)
        ->and($details['estimateIncome'])->toBe(300000)
        ->and($details['estimatedContributions'])->toBe(['2026-10' => 14980, '2026-11' => 14980, '2026-12' => 14980]);
})->group('integration');

it('keeps declared snapshots independent from receipts and requires an explicit current revision to correct them', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10'));
    $user = actingAsAdmin();
    $payment = Payment::factory()->create(['paid_on' => '2026-07-01', 'amount' => 300000]);
    $security = app(SocialSecurity::class);
    $amounts = ['2026-07' => 95000, '2026-08' => 0, '2026-09' => 20000];

    $first = $security->recordDeclaration('2026-Q3', $amounts);
    $payment->update(['amount' => 250000]);
    $corrected = ['2026-07' => 97000, '2026-08' => 0, '2026-09' => 20000];
    $second = $security->recordDeclaration('2026-Q3', $corrected, $first->id);

    expect($security->declaration('2026-Q3')?->id)->toBe($second->id)
        ->and($security->declarationHistory('2026-Q3')->pluck('amounts')->all())->toBe([$corrected, $amounts])
        ->and($first->user_id)->toBe($user->id);
    expect(fn () => $security->recordDeclaration('2026-Q3', $amounts, $first->id))->toThrow(ValidationException::class);
    expect(fn () => $security->recordDeclaration('2026-Q3', $amounts))->toThrow(ValidationException::class);

    CurrentAccount::run(Account::factory()->create(), function () use ($security, $amounts, $second): void {
        expect($security->declaration('2026-Q3'))->toBeNull();
        expect(fn () => $security->recordDeclaration('2026-Q3', $amounts, $second->id))->toThrow(ValidationException::class);
    });
})->group('integration');

it('rejects missing, out-of-period or non-integer declared values', function (array $amounts) {
    $this->travelTo(CarbonImmutable::parse('2026-11-10'));
    $security = app(SocialSecurity::class);

    expect(fn () => $security->recordDeclaration('2026-Q3', $amounts))->toThrow(ValidationException::class);
    expect($security->declaration('2026-Q3'))->toBeNull();
})->with([
    'missing month' => [['2026-07' => 100, '2026-08' => 100]],
    'outside quarter' => [['2026-07' => 100, '2026-08' => 100, '2026-10' => 100]],
    'fractional cent' => [['2026-07' => 100.1, '2026-08' => 100, '2026-09' => 0]],
    'negative' => [['2026-07' => -1, '2026-08' => 100, '2026-09' => 0]],
])->group('integration');

it('maps the latest separately confirmed contribution to its payment month across the year boundary', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10'));
    $security = app(SocialSecurity::class);

    $november = $security->confirmContribution('2026-Q3', '2026-11', 0);
    $december = $security->confirmContribution('2026-Q3', '2026-12', 12345);
    $security->confirmContribution('2026-Q3', '2026-12', 14567, $december->id);

    expect($security->declaration('2026-Q3'))->toBeNull()
        ->and($security->confirmedPaymentInMonth(CarbonImmutable::parse('2026-11-30')))->toBeNull()
        ->and($security->confirmedPaymentInMonth(CarbonImmutable::parse('2026-12-01')))->toBe(0)
        ->and($security->confirmedPaymentInMonth(CarbonImmutable::parse('2027-01-20')))->toBe(14567)
        ->and($security->confirmedContributionForMonth(CarbonImmutable::parse('2026-12-20')))->toBe(14567)
        ->and($security->contributionHistory('2026-Q3')->pluck('amount')->all())->toBe([14567, 12345, 0]);

    expect(fn () => $security->confirmContribution('2026-Q3', '2027-01', 100))->toThrow(ValidationException::class);
    expect(fn () => $security->confirmContribution('2026-Q3', '2026-12', 100, $november->id))->toThrow(ValidationException::class);
    expect(fn () => $security->confirmContribution('2026-Q4', '2027-01', 100))->toThrow(ValidationException::class);
    CurrentAccount::run(Account::factory()->create(), fn () => expect($security->confirmedPaymentInMonth(CarbonImmutable::parse('2027-01-20')))->toBeNull());
})->group('integration');

it('keeps both write paths closed for a read-only account', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10'));
    $this->account->update(['trial_ends_at' => now()->subDay()]);
    $this->actingAs(ownerOf($this->account));
    $security = app(SocialSecurity::class);

    expect(fn () => $security->recordDeclaration('2026-Q3', ['2026-07' => 0, '2026-08' => 0, '2026-09' => 0]))->toThrow(AccountIsReadOnly::class);
    expect(fn () => $security->confirmContribution('2026-Q3', '2026-10', 2000))->toThrow(AccountIsReadOnly::class);
    expect($security->declaration('2026-Q3'))->toBeNull()
        ->and($security->contributionHistory('2026-Q3'))->toBeEmpty();
})->group('integration');

it('opens a closed quarter from its tenant URL and rejects an open one', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10'));
    $owner = ownerOf($this->account);
    $otherAccount = Account::factory()->create();
    asAWebRequest();
    $url = fn (string $quarter, Account $account): string => SocialSecurityPage::getUrl(['quarter' => $quarter], panel: 'admin', tenant: $account);

    $this->actingAs($owner)->get($url('2026-Q2', $this->account))
        ->assertOk()->assertSee('Recebimentos registados')->assertSee('Rendimentos declarados');
    $this->get($url('2026-Q4', $this->account))->assertNotFound();
    $this->get($url('2026-Q2', $this->account).'&quarter[]=2026-Q2')->assertNotFound();
    $this->get($url('2026-Q2', $otherAccount))->assertNotFound();
})->group('ui');

it('records manually entered euro amounts and displays local corrections through the page', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10'));
    actingAsAdmin();
    Payment::factory()->create(['paid_on' => '2026-09-01', 'amount' => 500000]);

    Livewire::test(SocialSecurityPage::class)
        ->mountAction('recordDeclaration')
        ->assertSchemaStateSet(['month0' => null, 'month1' => null, 'month2' => null])
        ->fillForm(['month0' => '1 234,56', 'month1' => '0', 'month2' => '50,12'])
        ->callMountedAction()
        ->assertHasNoActionErrors()
        ->assertSee(Money::format(128468))
        ->assertSee('Corrigir registo da declaração')
        ->callAction(TestAction::make('recordContribution')->arguments(['month' => '2026-10']), data: ['amount' => '0,00'])
        ->assertHasNoActionErrors()
        ->assertSee('Corrigir valor confirmado');

    $security = app(SocialSecurity::class);
    expect($security->declaration('2026-Q3')?->amounts)->toBe(['2026-07' => 123456, '2026-08' => 0, '2026-09' => 5012])
        ->and($security->confirmedPaymentInMonth(CarbonImmutable::parse('2026-11-01')))->toBe(0);
})->group('ui');

it('shows a read-only account the quarter and hides the write actions', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10'));
    $this->account->update(['trial_ends_at' => now()->subDay()]);
    $this->actingAs(ownerOf($this->account));
    Filament::setCurrentPanel('admin');
    Filament::setTenant($this->account);

    Livewire::test(SocialSecurityPage::class)
        ->assertSee('Recebimentos registados')
        ->assertSee('Rendimentos declarados')
        ->assertActionHidden('recordDeclaration')
        ->assertActionHidden(TestAction::make('recordContribution')->arguments(['month' => '2026-10']));
})->group('ui');

it('preserves the newest declaration when a second tab submits an older revision', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10'));
    actingAsAdmin();
    $security = app(SocialSecurity::class);
    $original = $security->recordDeclaration('2026-Q3', ['2026-07' => 0, '2026-08' => 0, '2026-09' => 0]);
    $page = Livewire::test(SocialSecurityPage::class)
        ->mountAction('recordDeclaration')
        ->assertSchemaStateSet(['month0' => '0,00', 'month1' => '0,00', 'month2' => '0,00']);
    $newest = $security->recordDeclaration('2026-Q3', ['2026-07' => 1000, '2026-08' => 0, '2026-09' => 0], $original->id);

    $page->fillForm(['month0' => '20,00'])
        ->callMountedAction()
        ->assertNotified('O registo não foi guardado')
        ->assertSee('Histórico da declaração');

    expect($security->declarationHistory('2026-Q3')->modelKeys())->toBe([$newest->id, $original->id]);
})->group('ui');

describe('Closed quarter summary', function () {
    beforeEach(function () {
        $this->travelTo(CarbonImmutable::parse('2026-11-10'));
        actingAsAdmin();
        app(FinanceSettings::class)->set(['activity_started_on' => '2020-01-01', 'activity_start_kind' => 'first']);
    });

    it('walks through the closed quarter\'s cycle above the social security detail', function () {
        Payment::factory()->create(['paid_on' => '2026-07-01', 'amount' => 300000]);
        Payment::factory()->create(['paid_on' => '2026-09-30', 'amount' => 5000, 'tax_exempt' => true]);

        Livewire::withQueryParams(['quarter' => '2026-Q3'])->test(SocialSecurityPage::class)
            ->assertSee('Segurança Social, por trimestre fechado.')
            ->assertSeeInOrder(['O ciclo deste trimestre', '1. Recebimentos', Money::format(305000).' registados'], false)
            // 31 October and 20 December 2026 fall on a weekend: both deadlines move to the Monday.
            ->assertSeeInOrder(['2. Declaração', 'Atrasada 8 dias'])
            ->assertSeeInOrder(['3. Outubro', 'Por confirmar · até 20 nov.', '4. Novembro', 'Por confirmar · até 21 dez.', '5. Dezembro', 'Estimativa:'])
            ->assertSeeInOrder(['Por declarar', 'A declaração trimestral era até 02/11/2026', 'Valor a declarar, pelos registos', Money::format(300000)], false);
    })->group('integration');
});
