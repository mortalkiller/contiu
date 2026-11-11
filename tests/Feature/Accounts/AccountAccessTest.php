<?php

use App\Exceptions\AccountIsReadOnly;
use App\Filament\Actions\RecordPaymentAction;
use App\Filament\MonthClose\Widgets\MonthExpensesTable;
use App\Filament\Pages\BankStatementImport;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\FinanceSettingsPage;
use App\Filament\Resources\Clients\Pages\ClientStatementPage;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Livewire\QuickCreateMenu;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\RecurringExpense;
use App\Models\User;
use App\Support\AccountStatus;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;

pest()->group('tenancy', 'billing');

/** A Cashier subscription row, as the webhooks would leave it (no Stripe call). */
function subscribe(Account $account, string $status, ?string $endsAt = null): void
{
    $account->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_'.Str::random(10),
        'stripe_status' => $status,
        'stripe_price' => 'price_monthly',
        'quantity' => 1,
        'ends_at' => $endsAt,
    ]);
}

/** The account's trial ended yesterday and nothing pays for it. */
function expire(Account $account): Account
{
    $account->forceFill(['trial_ends_at' => now()->subDay()])->save();
    $account->unsetRelation('subscriptions');

    return $account;
}

/** Signs in as a plain owner (no Shield permissions) inside the account, as on a panel page. */
function signInAsOwner(Account $account, ?User $owner = null): User
{
    $owner ??= tap(User::factory()->create(), fn (User $user) => $account->users()->attach($user, ['role' => 'owner']));

    test()->actingAs($owner);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($account);

    return $owner;
}

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-11-10 10:00')));

it('gives access while trialing, subscribed or exempt, and not otherwise', function (array $account, ?array $subscription, bool $access) {
    $model = Account::factory()->create($account);

    if ($subscription !== null) {
        subscribe($model, ...$subscription);
    }

    expect($model->fresh()->hasAccess())->toBe($access);
})->with([
    'trial in the future' => [['trial_ends_at' => '2026-11-20'], null, true],
    'trial over, no subscription' => [['trial_ends_at' => '2026-11-01'], null, false],
    'active' => [['trial_ends_at' => '2026-11-01'], ['active'], true],
    'trialing (subscribed during the trial)' => [['trial_ends_at' => '2026-11-01'], ['trialing'], true],
    'past due (Stripe still retrying)' => [['trial_ends_at' => '2026-11-01'], ['past_due'], true],
    'canceled, paid period not over' => [['trial_ends_at' => '2026-11-01'], ['canceled', '2026-12-01'], true],
    'canceled, paid period over' => [['trial_ends_at' => '2026-11-01'], ['canceled', '2026-11-05'], false],
    'incomplete expired' => [['trial_ends_at' => '2026-11-01'], ['incomplete_expired'], false],
    'unpaid' => [['trial_ends_at' => '2026-11-01'], ['unpaid'], false],
    'billing exempt' => [['trial_ends_at' => null, 'billing_exempt' => true], null, true],
])->group('integration');

it('starts new accounts on the trial', function () {
    expect(Account::factory()->create()->hasAccess())->toBeTrue();
})->group('integration');

it('refuses writes in a read-only account', function () {
    $client = Client::factory()->create();
    expire($this->account);

    expect(CurrentAccount::readOnly())->toBeTrue()
        ->and(fn () => Client::create(['name' => 'Nova']))->toThrow(AccountIsReadOnly::class, 'A conta está só de leitura. Subscreve para continuar a registar.')
        ->and(fn () => $client->update(['name' => 'Outro nome']))->toThrow(AccountIsReadOnly::class)
        ->and(fn () => $client->delete())->toThrow(AccountIsReadOnly::class)
        ->and($client->fresh()->name)->not->toBe('Outro nome');
})->group('integration');

it('lets a paying account write again', function () {
    expire($this->account);
    subscribe($this->account, 'active');
    $this->account->unsetRelation('subscriptions');

    expect(CurrentAccount::readOnly())->toBeFalse()
        ->and(Client::create(['name' => 'Nova'])->exists)->toBeTrue();
})->group('integration');

it('still lets a super admin write', function () {
    actingAsAdmin();
    expire($this->account);

    expect(CurrentAccount::readOnly())->toBeFalse()
        ->and(Client::create(['name' => 'Nova'])->exists)->toBeTrue();
})->group('integration');

it('keeps database notifications working in a read-only account', function () {
    $owner = signInAsOwner(expire($this->account));

    Notification::make()->title('Aviso')->sendToDatabase($owner);

    expect($owner->notifications()->count())->toBe(1);
})->group('integration');

it('hides create buttons when read-only', function () {
    $client = Client::factory()->create();
    $owner = signInAsOwner(expire($this->account));

    expect($owner->can('create', Client::class))->toBeFalse()
        ->and($owner->can('Create:Client'))->toBeFalse()
        ->and($owner->can('update', $client))->toBeFalse()
        ->and($owner->can('delete', $client))->toBeFalse()
        ->and($owner->can('DeleteAny:Client'))->toBeFalse()
        // Seeing and exporting stay open.
        ->and($owner->can('viewAny', Client::class))->toBeTrue()
        ->and($owner->can('View:Client'))->toBeTrue();

    Livewire::test(ListClients::class)->assertActionHidden('create');
})->group('ui');

it('shows the create buttons again with access', function () {
    signInAsOwner($this->account);

    Livewire::test(ListClients::class)->assertActionVisible('create');
})->group('ui');

it('tells the user the account is read-only instead of failing', function () {
    // A write that skips the disabled button (Enter in a field, a forged Livewire call): the model
    // guard stops it and the panel shows it as a notification, not an error page.
    signInAsOwner(expire($this->account), ownerOf($this->account));

    Livewire::test(FinanceSettingsPage::class)
        ->fillForm(['account_names' => 'ANA SILVA'])
        ->call('save')
        ->assertNotified('A conta está só de leitura. Subscreve para continuar a registar.');

    expect(app(FinanceSettings::class)->accountNames())->toBe([]);
})->group('ui');

it('disables the writes no policy covers while read-only', function () {
    // "Registar recebimento", "Receita avulsa", "Marcar como paga" and saving the fiscal settings
    // are not behind a policy, so the gate cannot hide them.
    $client = Client::factory()->create();
    Receivable::factory()->for(Project::factory()->for($client), 'billable')->create(['amount' => 10000, 'due_on' => '2026-11-01']);
    $expense = Expense::factory()->unpaid()->create(['spent_on' => '2026-11-02']);
    signInAsOwner(expire($this->account), ownerOf($this->account));

    Livewire::test(ClientStatementPage::class, ['record' => $client->getRouteKey()])
        ->assertActionDisabled('recordPayment');
    Livewire::test(ManagePayments::class)
        ->assertActionDisabled('recordPayment')
        ->assertActionDisabled('oneOffIncome');
    Livewire::test(ListExpenses::class)
        ->loadTable()
        ->assertTableActionDisabled('markPaid', $expense)
        ->assertTableBulkActionDisabled('markPaid');
    Livewire::test(MonthExpensesTable::class, ['month' => '2026-11'])
        ->assertTableActionDisabled('markPaid', $expense);
    Livewire::test(FinanceSettingsPage::class)
        ->assertActionDisabled(TestAction::make('save')->schemaComponent('formActions', 'content'));

    // Call sites that set their own tooltip keep it, except while read-only.
    expect(RecordPaymentAction::make()->tooltip('Registar recebimento')->getTooltip())->toBe('A conta está só de leitura');
})->group('ui');

it('disables them in the quick create menu too', function () {
    // Menu items take a faster rendering path that never asks isDisabled(): check the HTML.
    Receivable::factory()->create(['amount' => 10000, 'due_on' => '2026-11-01']);
    signInAsOwner(expire($this->account), ownerOf($this->account));

    Livewire::test(QuickCreateMenu::class)
        ->assertSee('A conta está só de leitura')
        ->assertDontSeeHtml("wire:click=\"mountAction('oneOffIncome')\"")
        ->assertDontSeeHtml("wire:click=\"mountAction('recordPayment')\"");
})->group('ui');

it('keeps those writes enabled while the account has access', function () {
    $client = Client::factory()->create();
    Receivable::factory()->for(Project::factory()->for($client), 'billable')->create(['amount' => 10000, 'due_on' => '2026-11-01']);
    $expense = Expense::factory()->unpaid()->create(['spent_on' => '2026-11-02']);
    signInAsOwner($this->account, ownerOf($this->account));

    Livewire::test(ManagePayments::class)
        ->assertActionEnabled('recordPayment')
        ->assertActionEnabled('oneOffIncome');
    Livewire::test(ListExpenses::class)
        ->loadTable()
        ->assertTableActionEnabled('markPaid', $expense)
        ->assertTableBulkActionEnabled('markPaid');
    Livewire::test(MonthExpensesTable::class, ['month' => '2026-11'])
        ->assertTableActionEnabled('markPaid', $expense);
    Livewire::test(FinanceSettingsPage::class)
        ->assertActionEnabled(TestAction::make('save')->schemaComponent('formActions', 'content'));
    Livewire::test(QuickCreateMenu::class)
        ->assertDontSee('A conta está só de leitura')
        ->assertSeeHtml("wire:click=\"mountAction('oneOffIncome')\"");

    expect(RecordPaymentAction::make()->tooltip('Registar recebimento')->getTooltip())->toBe('Registar recebimento');
})->group('ui');

it('hides the create button even from a user given Shield permissions', function () {
    // Filament asks the policy ability ("create"), which no Spatie permission answers first.
    signInAsOwner(expire($this->account), ownerOf($this->account));

    Livewire::test(ListClients::class)->assertActionHidden('create');
})->group('ui');

it('stops client emails while read-only', function () {
    $client = Client::factory()->create(['email' => 'cliente@example.pt']);
    Receivable::factory()->for(Project::factory()->for($client), 'billable')->create(['amount' => 10000, 'due_on' => '2026-11-01']);
    signInAsOwner(expire($this->account));

    Livewire::test(ViewClient::class, ['record' => $client->getRouteKey()])
        ->assertActionDisabled('sendPaymentReminder');

    Livewire::test(ClientStatementPage::class, ['record' => $client->getRouteKey()])
        ->assertActionDisabled('sendPaymentReminder')
        ->assertActionDisabled('sendStatement');
})->group('ui');

it('stops bank statement imports and reconciliation while read-only', function () {
    $line = BankTransaction::create(['hash' => 'r1', 'booked_on' => '2026-11-02', 'description' => 'TRF', 'amount' => 10000, 'import_batch' => 'x']);
    signInAsOwner(expire($this->account));

    Livewire::test(BankStatementImport::class)
        ->assertActionDisabled('upload')
        ->assertActionDisabled('autoReconcile')
        ->loadTable()
        ->assertTableActionDisabled('link', $line)
        ->assertTableActionDisabled('ignore', $line)
        ->assertTableBulkActionDisabled('acceptSuggestions');
})->group('ui');

it('skips read-only accounts in scheduled commands', function () {
    $other = Account::factory()->create();
    ownerOf($other);
    CurrentAccount::run($other, function () {
        RecurringExpense::factory()->create(['starts_on' => '2026-11-01', 'day' => 1]);
        Receivable::factory()->create(['amount' => 10000, 'due_on' => '2026-11-01']);
    });
    expire($other);

    $this->artisan('finance:generate-recurring')->expectsOutputToContain('Despesas criadas: 0')->assertSuccessful();
    $this->artisan('finance:send-reminders')->expectsOutputToContain('Avisos enviados: 0')->assertSuccessful();

    expect(CurrentAccount::run($other, fn () => Expense::count()))->toBe(0);
})->group('integration');

it('shows the trial banner with days left', function () {
    $this->account->update(['trial_ends_at' => '2026-11-24 10:00']);
    signInAsOwner($this->account);
    asAWebRequest();

    $this->get(Dashboard::getUrl(tenant: $this->account))
        ->assertOk()
        ->assertSee('Experiência: faltam 14 dias');
})->group('ui');

it('counts the trial days left', function (string $endsAt, string $text) {
    $this->account->update(['trial_ends_at' => $endsAt]);
    signInAsOwner($this->account);

    expect(AccountStatus::banner())->toMatchArray(['kind' => 'trial', 'message' => $text]);
})->with([
    ['2026-11-24 10:00', 'Experiência: faltam 14 dias'],
    ['2026-11-12 09:00', 'Experiência: faltam 2 dias'],
    ['2026-11-11 09:00', 'Experiência: falta 1 dia'],
    ['2026-11-10 23:00', 'Experiência: último dia'],
])->group('integration');

it('shows the read-only notice when nothing pays for the account', function () {
    signInAsOwner(expire($this->account));
    asAWebRequest();

    $this->get(Dashboard::getUrl(tenant: $this->account))
        ->assertOk()
        ->assertSee('Esta conta está só de leitura');
})->group('ui');

it('shows no banner to exempt or paying accounts, nor to super admins', function (string $who) {
    match ($who) {
        'exempt' => $this->account->update(['billing_exempt' => true, 'trial_ends_at' => null]),
        'paying' => subscribe(expire($this->account), 'active'),
        'super admin' => expire($this->account),
    };
    $this->account->unsetRelation('subscriptions');

    $who === 'super admin' ? actingAsAdmin() : signInAsOwner($this->account);

    expect(AccountStatus::banner())->toBeNull();
})->with(['exempt', 'paying', 'super admin'])->group('integration');
