<?php

use App\Enums\Seat;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Widgets\TaxObligationsCard;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\Supplier;
use App\Models\TaxObligationCompletion;
use App\Models\User;
use App\Support\FinanceSettings;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

pest()->group('fiscal');

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

/** A payment received on $on, not marked tax-exempt. */
function cardPaid(string $on, int $amount): Payment
{
    return Payment::factory()->create(['paid_on' => $on, 'amount' => $amount, 'tax_exempt' => false]);
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
        ->assertSeeInOrder(['Ao Estado em novembro', Money::format(2230), 'a pagar · 2 tarefas'])
        ->assertSeeInOrder(['Segurança Social', 'até 20 nov', Money::format(2000), 'Pago'])
        ->assertSeeInOrder(['IVA das compras ao estrangeiro', 'até 30 nov', Money::format(230), 'Pago'])
        ->assertSee('A seguir: Declaração trimestral da Segurança Social · até 31 jan')
        ->assertDontSee('Nada a pagar este mês');

    $this->get(Dashboard::getUrl())->assertSeeLivewire(TaxObligationsCard::class);
})->group('ui');

it('puts what needs doing right under the heading and its period, before the figures', function () {
    cardOn('2026-11-09');
    actingAsAdmin();

    $html = $this->get(Dashboard::getUrl())->assertOk()->getContent();

    // Widgets render lazily: the name of each component marks where it sits on the page.
    $card = strpos($html, 'wire:name="App\\Filament\\Widgets\\TaxObligationsCard"');
    $filters = strpos($html, 'filters.year');
    $stats = strpos($html, 'wire:name="App\\Filament\\Widgets\\FinanceStatsOverview"');

    expect($card)->not->toBeFalse()
        ->and($filters)->not->toBeFalse()
        ->and($filters)->toBeLessThan($card)
        ->and($card)->toBeLessThan($stats);
})->group('ui');

it('opens the expenses of the month from the reverse-charge row', function () {
    cardOn('2026-11-09');
    cardServiceBoughtAbroad('2026-10-15', 1000);
    actingAsAdmin();

    $url = ExpenseResource::getUrl('index', ['tableFilters' => ['year' => ['value' => 2026], 'month' => ['value' => 10]]]);

    Livewire::test(TaxObligationsCard::class)->assertSee($url);
})->group('ui');

it('flags overdue rows and shows the VAT exemption threshold', function () {
    cardOn('2026-11-25', ['vat_exempt' => true], since: '2026-10-01');
    actingAsAdmin();

    Livewire::test(TaxObligationsCard::class)
        ->assertSeeInOrder(['Segurança Social', 'atrasado 36 dias', 'Declaração trimestral', 'atrasado 25 dias', 'Segurança Social', 'atrasado 5 dias'])
        ->assertSeeInOrder([Money::format(4000), 'a pagar · 3 tarefas'])
        ->assertSeeInOrder(['Isenção de IVA · aproximação', '0,0 %'])
        ->assertSee('Aproximação por recebimentos');
})->group('ui');

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
})->group('ui');

it('does not mark in a read-only account', function () {
    cardOn('2026-11-09', since: '2026-10-01');
    TaxObligationCompletion::query()->create(['key' => 'ss-declaration:2026-Q3', 'completed_at' => now()]);
    cardReadOnlyOwner();

    Livewire::test(TaxObligationsCard::class)
        ->assertSee('Segurança Social')
        // The buttons stay, disabled and without the Livewire call.
        ->assertSee('Pago')
        ->assertSee('A conta está só de leitura')
        ->assertDontSeeHtml('wire:click="complete(')
        ->call('toggleDone')
        ->assertSee('Desfazer')
        ->assertDontSeeHtml('wire:click="reopen(')
        ->call('complete', 'ss:2026-11')
        ->assertNotified('A conta está só de leitura. Subscreve para continuar a registar.')
        ->call('reopen', 'ss-declaration:2026-Q3');

    expect(TaxObligationCompletion::query()->pluck('key')->all())->toBe(['ss-declaration:2026-Q3']);
})->group('ui');

it('shows the empty state', function () {
    cardOn('2026-11-09', ['ss_contribution_exempt' => true]);
    actingAsAdmin();

    Livewire::test(TaxObligationsCard::class)
        ->assertSee('Nada a pagar este mês')
        ->assertSee('tudo em dia')
        ->assertSee('Segurança Social: isento')
        ->assertDontSee('por fazer')
        ->assertDontSee('Marcar como');
})->group('ui');

it('shows the accumulation exemption limit of the Segurança Social for an exempt account', function () {
    cardOn('2026-11-09', ['ss_contribution_exempt' => true]);
    cardPaid('2026-08-10', 25550);
    actingAsAdmin();

    Livewire::test(TaxObligationsCard::class)
        ->assertSeeInOrder(['Isenção da SS', '2,8 %'])
        // The figures sit in the tooltip.
        ->assertSee('59,62')
        ->assertSee('Segurança Social: isento');
})->group('ui');

it('warns when the relevant income passes the accumulation limit', function () {
    cardOn('2026-11-09', ['ss_contribution_exempt' => true]);
    cardPaid('2026-08-10', 1000000); // 10 000 € in jul–set → 2 333,33 €/mês of relevant income
    actingAsAdmin();

    Livewire::test(TaxObligationsCard::class)
        ->assertSeeInOrder(['Isenção da SS', '108,6 %'])
        ->assertSeeHtml('text-danger-600');
})->group('ui');

it('lays out the month total, the next deadline and the tasks', function () {
    cardOn('2026-11-09');
    cardServiceBoughtAbroad('2026-10-15', 1000);
    actingAsAdmin();

    Livewire::test(TaxObligationsCard::class)
        ->assertSeeInOrder(['Ao Estado em novembro', Money::format(2230), 'Próximo:', '20 nov · 11 dias', 'Segurança Social: '.Money::format(2000).' até dia 20', 'Ver calendário'])
        ->assertSeeInOrder(['Segurança Social', 'IVA das compras ao estrangeiro', 'A seguir: Declaração trimestral da Segurança Social']);
})->group('ui');

it('loads with a skeleton shaped like the card', function () {
    actingAsAdmin();

    $html = (new TaxObligationsCard)->placeholder()->render();

    expect($html)->toContain('fsl-skeleton')
        ->toContain('fsl-bone')
        ->toContain('md:grid-cols-[15rem_1fr]');
})->group('integration');

it('records the VAT paid as an expense to the tax authority when asked', function () {
    cardOn('2026-11-09');
    cardServiceBoughtAbroad('2026-10-15', 1000);
    actingAsAdmin();
    $before = Expense::query()->count();

    Livewire::test(TaxObligationsCard::class)
        ->assertSee('Criar a despesa')
        ->call('complete', 'vat-reverse:2026-10', true);

    $expense = Expense::query()->latest('id')->first();

    expect(Expense::query()->count())->toBe($before + 1)
        ->and($expense->supplier->name)->toBe('Autoridade Tributária e Aduaneira')
        ->and($expense->category->name)->toBe('Impostos e contribuições')
        ->and($expense->only(['net_amount', 'is_paid', 'description']))->toBe(['net_amount' => 230, 'is_paid' => true, 'description' => 'IVA autoliquidado · compras de outubro 2026'])
        ->and((float) $expense->vat_rate)->toBe(0.0)
        ->and($expense->paid_on->toDateString())->toBe('2026-11-09')
        ->and(TaxObligationCompletion::query()->pluck('key')->all())->toBe(['vat-reverse:2026-10']);
})->group('ui');

it('only marks the obligation when the expense is not wanted', function () {
    cardOn('2026-11-09');
    cardServiceBoughtAbroad('2026-10-15', 1000);
    actingAsAdmin();
    $before = Expense::query()->count();

    Livewire::test(TaxObligationsCard::class)->call('complete', 'vat-reverse:2026-10', false);

    expect(Expense::query()->count())->toBe($before)
        ->and(TaxObligationCompletion::query()->pluck('key')->all())->toBe(['vat-reverse:2026-10']);
})->group('ui');

it('records the Segurança Social paid as an expense for the month it contributes', function () {
    cardOn('2026-11-09');
    actingAsAdmin();

    Livewire::test(TaxObligationsCard::class)->call('complete', 'ss:2026-11', true);

    $expense = Expense::query()->latest('id')->first();

    expect($expense->supplier->name)->toBe('Segurança Social')
        ->and($expense->net_amount)->toBe(2000)
        ->and($expense->description)->toBe('Segurança Social · contribuição de outubro 2026');
})->group('ui');

it('lists statement lines to reconcile and amounts clients owe after the tax obligations', function () {
    cardOn('2026-11-09', ['has_business_account' => true]);
    cardServiceBoughtAbroad('2026-10-15', 1000);
    actingAsAdmin();
    BankTransaction::create(['hash' => 'p1', 'booked_on' => '2026-11-03', 'description' => 'TRF', 'amount' => -2000, 'balance' => 93000, 'status' => 'pending', 'import_batch' => 'b']);
    $job = Project::factory()->for(Client::factory()->create(['name' => 'Estúdio Atlântico']))->create(['quoted_on' => '2026-10-01']);
    Receivable::factory()->for($job, 'billable')->create(['amount' => 15000, 'due_on' => '2026-11-04']);

    Livewire::test(TaxObligationsCard::class)
        ->assertSeeInOrder(['Segurança Social', 'IVA das compras ao estrangeiro', '1 linha do extrato por reconciliar'])
        ->assertSee('Ver mais 1')
        ->assertDontSee('Estúdio Atlântico tem um valor em aberto')
        ->call('toggleAll')
        ->assertSet('showAll', true);

    Livewire::test(TaxObligationsCard::class, ['showAll' => true])
        ->assertSeeInOrder(['1 linha do extrato por reconciliar', 'Ver extrato', 'Estúdio Atlântico tem um valor em aberto', 'atrasado 5 dias', Money::format(15000), 'Receber'])
        ->assertSee('Ver menos');
})->group('ui');

it('leaves out amounts that only fall due later, and the statement of an account without a business account', function () {
    cardOn('2026-11-09', ['has_business_account' => false]);
    actingAsAdmin();
    BankTransaction::create(['hash' => 'p2', 'booked_on' => '2026-11-03', 'description' => 'TRF', 'amount' => -2000, 'balance' => 93000, 'status' => 'pending', 'import_batch' => 'b']);
    $job = Project::factory()->for(Client::factory()->create(['name' => 'Estúdio Brisa']))->create(['quoted_on' => '2026-10-01']);
    Receivable::factory()->for($job, 'billable')->create(['amount' => 15000, 'due_on' => '2026-12-20']);

    Livewire::test(TaxObligationsCard::class)
        ->assertDontSee('do extrato por reconciliar')
        ->assertDontSee('Estúdio Brisa tem um valor em aberto')
        ->assertDontSee('Ver mais');
})->group('ui');

it('records what a client paid from its row', function () {
    cardOn('2026-11-09', ['ss_contribution_exempt' => true]);
    actingAsAdmin();
    $job = Project::factory()->for(Client::factory()->create(['name' => 'Estúdio Atlântico']))->create(['quoted_on' => '2026-10-01']);
    $receivable = Receivable::factory()->for($job, 'billable')->create(['amount' => 15000, 'due_on' => '2026-11-04']);

    Livewire::test(TaxObligationsCard::class)
        ->assertSee('Estúdio Atlântico tem um valor em aberto')
        ->callAction('receive', arguments: ['receivable' => $receivable->getKey()])
        ->assertDontSee('Estúdio Atlântico tem um valor em aberto');

    expect($receivable->refresh()->outstanding)->toBe(0);
})->group('ui');

it('counts what needs doing on the Today menu item, in amber while nothing is late', function () {
    cardOn('2026-11-09', ['ss_contribution_exempt' => true]);
    actingAsAdmin();

    $job = Project::factory()->for(Client::factory()->create())->create(['quoted_on' => '2026-10-01']);
    Receivable::factory()->for($job, 'billable')->create(['amount' => 15000, 'due_on' => '2026-11-12']);

    expect(Dashboard::getNavigationBadge())->toBe('1')
        ->and(Dashboard::getNavigationBadgeColor())->toBe('warning');
})->group('integration');

it('turns the Today count red when one of the things to do is late', function () {
    cardOn('2026-11-09', ['ss_contribution_exempt' => true]);
    actingAsAdmin();
    $job = Project::factory()->for(Client::factory()->create())->create(['quoted_on' => '2026-10-01']);
    Receivable::factory()->for($job, 'billable')->create(['amount' => 15000, 'due_on' => '2026-11-04']);

    expect(Dashboard::getNavigationBadge())->toBe('1')
        ->and(Dashboard::getNavigationBadgeColor())->toBe('danger');
})->group('integration');

it('shows no count on the Today menu item when there is nothing to do', function () {
    cardOn('2026-11-09', ['ss_contribution_exempt' => true]);
    actingAsAdmin();

    expect(Dashboard::getNavigationBadge())->toBeNull();
})->group('integration');
