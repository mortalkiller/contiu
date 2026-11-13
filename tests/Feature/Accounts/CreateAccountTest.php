<?php

use App\Actions\CreateAccount;
use App\Actions\SeedAccountDefaults;
use App\Models\Account;
use App\Models\Category;
use App\Models\Setting;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

pest()->group('integration', 'tenancy');

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-08 10:00'));
});

function newAccountData(array $overrides = []): array
{
    return [
        'name' => 'Estúdio Rita',
        'nif' => '501964843',
        'activity_started_on' => '2026-01-15',
        'vat_exempt' => true,
        'vat_exemption_threshold' => 1500000,
        'ss_rate' => 0.214,
        'ss_relevant_income_share' => 0.70,
        'account_names' => "RITA SILVA\nCONTA RITA",
        ...$overrides,
    ];
}

it('creates the account with its owner, trial, settings and categories', function () {
    $owner = User::factory()->create();
    // Called from registration: no panel request and no current account.
    CurrentAccount::set(null);

    $account = app(CreateAccount::class)->handle($owner, newAccountData());

    expect(CurrentAccount::get())->toBeNull()
        ->and($account->name)->toBe('Estúdio Rita')
        ->and($account->nif)->toBe('501964843')
        ->and($account->slug)->toBe('estudio-rita')
        ->and($account->billing_exempt)->toBeFalse()
        ->and($account->trial_ends_at->toDateTimeString())->toBe('2026-11-22 10:00:00')
        ->and($account->owner()?->is($owner))->toBeTrue()
        ->and($owner->accounts()->first()->pivot->role)->toBe('owner');

    CurrentAccount::run($account, function () {
        $settings = app(FinanceSettings::class);

        expect($settings->activityStartedOn()?->toDateString())->toBe('2026-01-15')
            ->and($settings->vatExempt())->toBeTrue()
            ->and($settings->paymentsExemptByDefault())->toBeTrue()
            ->and($settings->vatExemptionThreshold())->toBe(1500000)
            ->and($settings->ssRate())->toBe(0.214)
            ->and($settings->ssRelevantIncomeShare())->toBe(0.70)
            ->and($settings->accountNames())->toBe(['RITA SILVA', 'CONTA RITA'])
            ->and(Category::count())->toBe(11);
    });

    // Nothing leaked into another account.
    CurrentAccount::run($this->account, function () {
        $settings = app(FinanceSettings::class);

        expect($settings->vatExempt())->toBeFalse()
            ->and($settings->paymentsExemptByDefault())->toBeFalse()
            ->and($settings->accountNames())->toBe([])
            ->and(Category::count())->toBe(0);
    });
});

it('starts payments taxed when the account is not VAT exempt', function () {
    $account = app(CreateAccount::class)->handle(User::factory()->create(), newAccountData(['vat_exempt' => false]));

    CurrentAccount::run($account, fn () => expect(app(FinanceSettings::class)->paymentsExemptByDefault())->toBeFalse());
});

it('never uses a reserved slug', function () {
    $account = app(CreateAccount::class)->handle(User::factory()->create(), newAccountData(['name' => 'Login']));

    expect($account->slug)->toBe('login-2');
});

it('never shadows a route outside the accounts, even one missing from the reserved list', function () {
    Route::get('novidades', fn () => 'novidades');

    $account = app(CreateAccount::class)->handle(User::factory()->create(), newAccountData(['name' => 'Novidades']));

    expect($account->slug)->toBe('novidades-2');
});

it('suffixes repeated names', function () {
    $create = fn (string $nif) => app(CreateAccount::class)->handle(User::factory()->create(), newAccountData(['nif' => $nif]));

    expect($create('501964843')->slug)->toBe('estudio-rita')
        ->and($create('123456789')->slug)->toBe('estudio-rita-2')
        ->and($create('111111110')->slug)->toBe('estudio-rita-3');
});

it('gives a name without letters or digits a usable slug', function () {
    $account = app(CreateAccount::class)->handle(User::factory()->create(), newAccountData(['name' => '!!!']));

    expect($account->slug)->toBe('conta');
});

it('keeps the slug of a long name short', function () {
    $account = app(CreateAccount::class)->handle(User::factory()->create(), newAccountData(['name' => str_repeat('Design e Comunicação ', 10)]));

    expect(strlen($account->slug))->toBeLessThanOrEqual(50)
        ->and($account->slug)->toStartWith('design-e-comunicacao-')
        ->not->toEndWith('-');
});

it('keeps a repeated long name within the length the account profile accepts', function () {
    $create = fn (string $nif) => app(CreateAccount::class)->handle(User::factory()->create(), newAccountData(['name' => str_repeat('Atelier ', 10), 'nif' => $nif]));
    $create('501964843');

    $second = $create('123456789');

    expect(strlen($second->slug))->toBeLessThanOrEqual(50)
        ->and($second->slug)->toEndWith('-2')
        ->not->toContain('--');
});

it('creates nothing when a step fails', function () {
    $this->mock(SeedAccountDefaults::class)->shouldReceive('handle')->andThrow(new RuntimeException('falhou'));
    $owner = User::factory()->create();

    expect(fn () => app(CreateAccount::class)->handle($owner, newAccountData()))->toThrow(RuntimeException::class, 'falhou');

    expect(Account::where('nif', '501964843')->exists())->toBeFalse()
        ->and($owner->accounts()->count())->toBe(0)
        ->and(Setting::withoutGlobalScopes()->count())->toBe(0);
});

it('reserves the first segment of every route outside the accounts', function () {
    // Livewire hashes its prefix ("livewire-1a2b3c4d"); the reserved list holds the stable name.
    $livewirePrefix = explode('/', trim(app('livewire')->getUpdateUri(), '/'))[0];

    $segments = collect(Route::getRoutes()->getRoutes())
        ->reject(fn ($route) => in_array('tenant', $route->parameterNames(), true))
        ->map(fn ($route) => CreateAccount::firstSegment($route->uri()))
        ->reject(fn (string $segment) => $segment === '' || str_starts_with($segment, '{'))
        ->map(fn (string $segment) => $segment === $livewirePrefix ? 'livewire' : $segment)
        ->unique()
        ->values();

    expect($segments)->not->toBeEmpty()
        ->and(array_diff($segments->all(), config('contiu.reserved_slugs')))->toBe([]);
});

it('holds the plan values', function () {
    expect(config('contiu.trial_days'))->toBe(14)
        ->and(config('contiu.terms_version'))->toBe('2026-11-13')
        ->and(config('contiu.prices'))->toHaveKeys(['monthly', 'yearly']);
});
