<?php

use App\Enums\Seat;
use App\Filament\Pages\Dashboard;
use App\Filament\Widgets\TaxObligationsCard;
use App\Models\Expense;
use App\Models\Supplier;
use App\Models\TaxObligationCompletion;
use App\Models\User;
use App\Support\FinanceSettings;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

/**
 * The card as seen on $today by an account that exists since $since (default: the 1st of $today's
 * month, so no earlier month is overdue unless the test says so) and whose SS started long ago.
 */
function cardOn(string $today, array $settings = [], ?string $since = null): void
{
    test()->travelTo(Carbon::parse($today));
    test()->account->forceFill(['created_at' => $since ?? CarbonImmutable::parse($today)->startOfMonth(), 'billing_exempt' => true])->save();
    app(FinanceSettings::class)->set(['activity_started_on' => '2024-01-01', 'activity_start_kind' => 'first', ...$settings]);
}

/** A plain owner (never read-only like a super admin) inside an account whose trial is over. */
function cardReadOnlyOwner(): User
{
    test()->account->forceFill(['trial_ends_at' => now()->subDay(), 'billing_exempt' => false])->save();
    $owner = User::factory()->create();
    test()->account->users()->attach($owner, ['role' => 'owner']);

    test()->actingAs($owner);
    Filament::setCurrentPanel('admin');
    Filament::setTenant(test()->account);

    return $owner;
}

function cardServiceBoughtAbroad(string $on, int $net): Expense
{
    return Expense::factory()->for(Supplier::factory()->create(['seat' => Seat::EuropeanUnion]), 'supplier')
        ->create(['spent_on' => $on, 'is_goods' => false, 'vat_rate' => 0, 'net_amount' => $net]);
}

it('shows this month obligations on the dashboard', function () {
    cardOn('2026-11-09');
    cardServiceBoughtAbroad('2026-10-15', 1000);
    actingAsAdmin();

    Livewire::test(TaxObligationsCard::class)
        ->assertSee('Este mês · novembro 2026')
        ->assertSee('2 por fazer · '.Money::format(2230), escape: false)
        ->assertSeeInOrder(['Segurança Social', Money::format(2000), 'Pagar entre 10 e 20 de novembro', 'Faltam 11 dias', 'Marcar como pago'])
        ->assertSeeInOrder(['IVA das compras ao estrangeiro', Money::format(230), 'Faltam 21 dias', 'Ver compras', 'Marcar como pago'])
        ->assertSee('A seguir:')
        ->assertSee('Declaração trimestral da Segurança Social, até 31 jan')
        ->assertDontSee('Nada a pagar este mês');

    $this->get(Dashboard::getUrl())->assertSeeLivewire(TaxObligationsCard::class);
});

it('flags overdue rows and shows the VAT exemption threshold', function () {
    cardOn('2026-11-25', ['vat_exempt' => true], since: '2026-10-01');
    actingAsAdmin();

    Livewire::test(TaxObligationsCard::class)
        ->assertSeeInOrder(['Segurança Social', 'Em atraso há 36 dias', 'Declaração trimestral', 'Em atraso há 25 dias', 'Segurança Social', 'Em atraso há 5 dias'])
        ->assertSee('3 por fazer · '.Money::format(4000), escape: false)
        ->assertSee('Limite da isenção de IVA')
        ->assertSee('0,0 %');
});

it('marks an obligation as done and hides it', function () {
    cardOn('2026-11-09');
    $admin = actingAsAdmin();

    $card = Livewire::test(TaxObligationsCard::class)
        ->call('complete', 'ss:2026-11')
        ->assertDontSee('Marcar como pago')
        ->assertSee('Nada a pagar este mês')
        ->assertSee('Ver feitas (1)')
        ->assertDontSee('Desfazer');

    expect(TaxObligationCompletion::query()->sole()->only(['key', 'user_id']))->toBe(['key' => 'ss:2026-11', 'user_id' => $admin->id]);

    $card->call('toggleDone')
        ->assertSee('Desfazer')
        ->call('reopen', 'ss:2026-11')
        ->assertSee('Marcar como pago');

    expect(TaxObligationCompletion::query()->count())->toBe(0);

    // Only what the card lists can be marked.
    $card->call('complete', 'ss:2020-01');

    expect(TaxObligationCompletion::query()->count())->toBe(0);
});

it('does not mark in a read-only account', function () {
    cardOn('2026-11-09', since: '2026-10-01');
    TaxObligationCompletion::query()->create(['key' => 'ss-declaration:2026-Q3', 'completed_at' => now()]);
    cardReadOnlyOwner();

    Livewire::test(TaxObligationsCard::class)
        ->assertSee('Segurança Social')
        // The buttons stay, disabled and without the Livewire call.
        ->assertSee('Marcar como pago')
        ->assertDontSeeHtml('wire:click="complete(')
        ->call('toggleDone')
        ->assertSee('Desfazer')
        ->assertDontSeeHtml('wire:click="reopen(')
        ->call('complete', 'ss:2026-11')
        ->assertNotified('A conta está só de leitura. Subscreve para continuar a registar.')
        ->call('reopen', 'ss-declaration:2026-Q3');

    expect(TaxObligationCompletion::query()->pluck('key')->all())->toBe(['ss-declaration:2026-Q3']);
});

it('shows the empty state', function () {
    cardOn('2026-11-09', ['ss_contribution_exempt' => true]);
    actingAsAdmin();

    Livewire::test(TaxObligationsCard::class)
        ->assertSee('Nada a pagar este mês')
        ->assertSee('Isento (art. 157.º)')
        ->assertDontSee('por fazer')
        ->assertDontSee('Marcar como');
});
