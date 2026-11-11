<?php

use App\Filament\Pages\AccountSubscription;
use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Resources\Accounts\RelationManagers\SubscriptionPaymentsRelationManager;
use App\Filament\Widgets\AccountantAccessWidget;
use App\Mail\SubscriptionPaid;
use App\Models\Account;
use App\Models\SubscriptionPayment;
use App\Models\User;
use App\Support\AccountStatus;
use App\Support\Money;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

pest()->group('tenancy', 'billing');

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-10 10:00'));
    config(['contiu.prices.monthly' => 'price_monthly', 'contiu.prices.yearly' => 'price_yearly']);
});

/** A Cashier subscription row, as the webhooks would leave it (no Stripe call). */
function subscriptionFor(Account $account, string $status = 'active', string $price = 'price_monthly', ?string $endsAt = null): void
{
    $account->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_'.Str::random(10),
        'stripe_status' => $status,
        'stripe_price' => $price,
        'quantity' => 1,
        'ends_at' => $endsAt,
    ]);
    $account->unsetRelation('subscriptions');
}

/** Signs in inside the account, as on a panel page. */
function openAccountAs(Account $account, User $user): User
{
    test()->actingAs($user);
    Filament::setCurrentPanel('admin');
    Filament::setTenant($account);

    return $user;
}

function plainOwnerOf(Account $account): User
{
    return tap(User::factory()->create(), fn (User $user) => $account->users()->attach($user, ['role' => 'owner']));
}

/** An `invoice.paid` event as Stripe sends it (only the fields the app reads). */
function invoicePaidEvent(Account $account, int $amountPaid = 500, string $invoiceId = 'in_1'): array
{
    return [
        'id' => 'evt_'.Str::random(10),
        'type' => 'invoice.paid',
        'data' => ['object' => [
            'id' => $invoiceId,
            'object' => 'invoice',
            'customer' => $account->stripe_id,
            'amount_paid' => $amountPaid,
            'currency' => 'eur',
            'status_transitions' => ['paid_at' => Carbon::parse('2026-11-24 10:05')->timestamp],
            'customer_address' => ['line1' => 'Rua das Flores, 12', 'line2' => '2.º esq.', 'postal_code' => '4050-262', 'city' => 'Porto', 'state' => null, 'country' => 'PT'],
            'lines' => ['data' => [[
                'period' => ['start' => Carbon::parse('2026-11-24 10:00')->timestamp, 'end' => Carbon::parse('2026-12-24 10:00')->timestamp],
            ]]],
        ]],
    ];
}

function subscriptionCreatedEvent(Account $account): array
{
    return [
        'id' => 'evt_'.Str::random(10),
        'type' => 'customer.subscription.created',
        'data' => ['object' => [
            'id' => 'sub_forged',
            'customer' => $account->stripe_id,
            'status' => 'active',
            'metadata' => ['type' => 'default'],
            'items' => ['data' => [['id' => 'si_1', 'quantity' => 1, 'price' => ['id' => 'price_monthly', 'product' => 'prod_1']]]],
        ]],
    ];
}

it('keeps the remaining trial days when subscribing during the trial', function () {
    $this->account->update(['trial_ends_at' => '2026-11-24 10:00']);

    $options = AccountSubscription::checkoutOptions($this->account, 'monthly');

    expect($options)->toMatchArray([
        'success_url' => AccountSubscription::getUrl(['checkout' => 'success'], panel: 'admin', tenant: $this->account),
        'cancel_url' => AccountSubscription::getUrl(panel: 'admin', tenant: $this->account),
        'billing_address_collection' => 'required',
        // Checkout only saves the address on the customer (and so on each invoice) when asked to.
        'customer_update' => ['address' => 'auto'],
        'subscription_data' => ['trial_end' => Carbon::parse('2026-11-24 10:00')->timestamp],
    ]);
})->group('integration');

it('charges at once when the trial is over', function () {
    $this->account->update(['trial_ends_at' => '2026-11-01 10:00']);

    expect(AccountSubscription::checkoutOptions($this->account, 'yearly'))
        ->toHaveKeys(['success_url', 'cancel_url', 'billing_address_collection'])
        ->not->toHaveKey('subscription_data');
})->group('integration');

it('moves a trial ending within 48 hours to the minimum Stripe accepts', function () {
    // Stripe refuses a Checkout trial_end less than 48 hours away; the last two days of the trial
    // would otherwise fail exactly when the banner asks to subscribe.
    $this->account->update(['trial_ends_at' => '2026-11-11 09:00']);

    $trialEnd = AccountSubscription::checkoutOptions($this->account, 'monthly')['subscription_data']['trial_end'];

    expect($trialEnd)->toBeGreaterThanOrEqual(now()->addHours(48)->timestamp)
        ->toBeLessThan(now()->addHours(49)->timestamp);
})->group('integration');

it('maps each plan to its Stripe price', function () {
    expect(AccountSubscription::priceFor('monthly'))->toBe('price_monthly')
        ->and(AccountSubscription::priceFor('yearly'))->toBe('price_yearly')
        ->and(fn () => AccountSubscription::priceFor('weekly'))->toThrow(InvalidArgumentException::class);
})->group('integration');

it('names the Stripe customer after the account and its owner', function () {
    $owner = plainOwnerOf($this->account);

    expect($this->account->stripeName())->toBe($this->account->name)
        ->and($this->account->stripeEmail())->toBe($owner->email);
})->group('integration');

it('opens the subscription page to owners, read-only ones included, and super admins', function (string $who) {
    if ($who === 'read-only owner') {
        $this->account->update(['trial_ends_at' => now()->subDay()]);
    }

    $user = $who === 'super admin' ? superAdminOf($this->account) : plainOwnerOf($this->account);
    $this->actingAs($user);
    asAWebRequest();

    $this->get(AccountSubscription::getUrl(panel: 'admin', tenant: $this->account))
        ->assertOk()
        ->assertSee('Assinatura')
        ->assertSee('Subscrever mensal')
        ->assertSee('Subscrever anual');
})->with(['owner', 'read-only owner', 'super admin'])->group('ui');

it('shows a super admin outside the account its state but not the buttons to pay', function () {
    $superAdmin = User::factory()->create();
    $superAdmin->assignRole(Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']));
    $this->actingAs($superAdmin);
    asAWebRequest();

    $this->get(AccountSubscription::getUrl(panel: 'admin', tenant: $this->account))
        ->assertOk()
        ->assertSee('Estás na experiência até')
        ->assertDontSee('Subscrever mensal')
        ->assertDontSee('Subscrever anual');

    openAccountAs($this->account, $superAdmin);

    Livewire::test(AccountSubscription::class)
        ->assertActionHidden('subscribeMonthly')
        ->assertActionHidden('subscribeYearly');

    subscriptionFor($this->account);

    Livewire::test(AccountSubscription::class)
        ->assertSee('Assinatura mensal ativa.')
        ->assertActionHidden('manage');
})->group('ui');

it('hides the subscription page from exempt accounts', function () {
    $this->account->update(['billing_exempt' => true, 'trial_ends_at' => null]);
    $this->actingAs(plainOwnerOf($this->account));
    asAWebRequest();

    $this->get(AccountSubscription::getUrl(panel: 'admin', tenant: $this->account))->assertForbidden();
})->group('ui');

it('offers both plans until the account subscribes, then the billing portal', function () {
    openAccountAs($this->account, plainOwnerOf($this->account));

    Livewire::test(AccountSubscription::class)
        ->assertActionVisible('subscribeMonthly')
        ->assertActionVisible('subscribeYearly')
        ->assertActionHidden('manage');

    subscriptionFor($this->account);

    Livewire::test(AccountSubscription::class)
        ->assertActionHidden('subscribeMonthly')
        ->assertActionHidden('subscribeYearly')
        ->assertActionVisible('manage');
})->group('ui');

it('lets a read-only account subscribe', function () {
    $this->account->update(['trial_ends_at' => now()->subDay()]);
    openAccountAs($this->account, plainOwnerOf($this->account));

    Livewire::test(AccountSubscription::class)
        ->assertActionVisible('subscribeMonthly')
        ->assertActionEnabled('subscribeMonthly');
})->group('ui');

it('explains a missing price instead of failing', function () {
    config(['contiu.prices.monthly' => null]);
    openAccountAs($this->account, plainOwnerOf($this->account));

    Livewire::test(AccountSubscription::class)
        ->callAction('subscribeMonthly')
        ->assertNotified('Não é possível subscrever agora')
        ->assertNoRedirect();

    expect($this->account->subscriptions()->count())->toBe(0);
})->group('ui');

it('links the banner to the subscription page', function () {
    openAccountAs($this->account, plainOwnerOf($this->account));

    expect(AccountStatus::banner()['subscribeUrl'])->toBe(AccountSubscription::getUrl(tenant: $this->account));

    asAWebRequest();
    $this->get(Dashboard::getUrl(tenant: $this->account))
        ->assertOk()
        ->assertSee(AccountSubscription::getUrl(panel: 'admin', tenant: $this->account));
})->group('ui');

it('records an invoice.paid webhook and emails super admins', function () {
    Mail::fake();
    $this->account->update(['stripe_id' => 'cus_123', 'nif' => '123456789']);
    $owner = plainOwnerOf($this->account);
    $admin = superAdminOf(Account::factory()->create());
    $otherAdmin = superAdminOf(Account::factory()->create());

    $this->postJson(route('cashier.webhook'), invoicePaidEvent($this->account))->assertOk();

    $payment = SubscriptionPayment::sole();
    expect($payment)
        ->account_id->toBe($this->account->id)
        ->stripe_invoice_id->toBe('in_1')
        ->amount->toBe(500)
        ->invoiced_at->toBeNull()
        ->and($payment->paid_at->toDateTimeString())->toBe('2026-11-24 10:05:00')
        ->and($payment->period_start->toDateString())->toBe('2026-11-24')
        ->and($payment->period_end->toDateString())->toBe('2026-12-24')
        ->and($payment->billing_address['city'])->toBe('Porto');

    Mail::assertQueued(SubscriptionPaid::class, 2);
    Mail::assertQueued(SubscriptionPaid::class, fn (SubscriptionPaid $mail) => $mail->hasTo($admin->email));
    Mail::assertQueued(SubscriptionPaid::class, fn (SubscriptionPaid $mail) => $mail->hasTo($otherAdmin->email));
    Mail::assertNotQueued(SubscriptionPaid::class, fn (SubscriptionPaid $mail) => $mail->hasTo($owner->email));
})->group('ui');

it('records a payment once when Stripe repeats the webhook', function () {
    Mail::fake();
    $this->account->update(['stripe_id' => 'cus_123']);
    superAdminOf(Account::factory()->create());

    $this->postJson(route('cashier.webhook'), invoicePaidEvent($this->account))->assertOk();
    $this->postJson(route('cashier.webhook'), invoicePaidEvent($this->account))->assertOk();

    expect(SubscriptionPayment::count())->toBe(1);
    Mail::assertQueued(SubscriptionPaid::class, 1);
})->group('ui');

it('keeps the payment when the email to the super admins fails', function () {
    // Stripe stops retrying once it gets a 2xx: the row is what tells Pedro to invoice, so a mail
    // server that is down must never lose it.
    Exceptions::fake();
    Mail::extend('broken', fn () => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('Servidor de email em baixo');
        }

        public function __toString(): string
        {
            return 'broken';
        }
    });
    config(['mail.mailers.broken' => ['transport' => 'broken'], 'mail.default' => 'broken']);
    $this->account->update(['stripe_id' => 'cus_123']);
    superAdminOf(Account::factory()->create());

    $this->postJson(route('cashier.webhook'), invoicePaidEvent($this->account))->assertOk();

    expect(SubscriptionPayment::count())->toBe(1);
    Exceptions::assertReported(TransportException::class);
})->group('ui');

it('ignores zero-amount invoices', function () {
    // The invoice that opens a subscription during the trial is 0 €: nothing to invoice.
    Mail::fake();
    $this->account->update(['stripe_id' => 'cus_123']);
    superAdminOf(Account::factory()->create());

    $this->postJson(route('cashier.webhook'), invoicePaidEvent($this->account, amountPaid: 0))->assertOk();

    expect(SubscriptionPayment::count())->toBe(0);
    Mail::assertNothingOutgoing();
})->group('ui');

it('ignores invoices of an unknown customer', function () {
    Mail::fake();
    superAdminOf(Account::factory()->create());
    $stranger = Account::factory()->make(['stripe_id' => 'cus_unknown']);

    $this->postJson(route('cashier.webhook'), invoicePaidEvent($stranger))->assertOk();

    expect(SubscriptionPayment::count())->toBe(0);
    Mail::assertNothingOutgoing();
})->group('ui');

it('tells the super admins what to invoice', function () {
    $this->account->update(['stripe_id' => 'cus_123', 'nif' => '123456789', 'name' => 'Ana Silva Design']);
    $payment = SubscriptionPayment::create([
        'account_id' => $this->account->id,
        'stripe_invoice_id' => 'in_1',
        'amount' => 5400,
        'paid_at' => '2026-11-24 10:05',
        'period_start' => '2026-11-24 10:00',
        'period_end' => '2027-11-24 10:00',
        'billing_address' => ['line1' => 'Rua das Flores, 12', 'line2' => null, 'postal_code' => '4050-262', 'city' => 'Porto', 'country' => 'PT'],
    ]);

    $mail = new SubscriptionPaid($payment);

    $mail->assertHasSubject('Pagamento recebido: Ana Silva Design ('.Money::format(5400).')');
    $mail->assertSeeInHtml('Ana Silva Design')
        ->assertSeeInHtml('123456789')
        ->assertSeeInHtml('Rua das Flores, 12, 4050-262 Porto, PT')
        ->assertSeeInHtml('54,00')
        ->assertSeeInHtml('24/11/2026')
        ->assertSeeInHtml('24/11/2026 a 24/11/2027');
})->group('integration');

it('refuses unsigned webhooks outside local and testing when no secret is set', function () {
    // A forged customer.subscription.created would otherwise give the account access.
    $this->account->update(['stripe_id' => 'cus_123']);
    config(['cashier.webhook.secret' => null]);
    $this->app['env'] = 'production';

    $this->postJson(route('cashier.webhook'), subscriptionCreatedEvent($this->account))->assertForbidden();

    expect($this->account->subscriptions()->count())->toBe(0);
})->group('integration');

it('leaves the signature check to Cashier when a secret is set', function () {
    $this->account->update(['stripe_id' => 'cus_123']);
    config(['cashier.webhook.secret' => 'whsec_test']);
    $this->app['env'] = 'production';
    $payload = json_encode(subscriptionCreatedEvent($this->account));
    $post = fn (string $signature) => $this->call('POST', route('cashier.webhook'), server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $signature,
    ], content: $payload);

    $post('t=1,v1=forged')->assertForbidden();
    expect($this->account->subscriptions()->count())->toBe(0);

    $timestamp = time();
    $post("t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$payload}", 'whsec_test'))->assertOk();
    expect($this->account->subscriptions()->count())->toBe(1);
})->group('ui');

it('lists accounts and marks a payment as invoiced', function () {
    $this->account->update(['nif' => '123456789']);
    $owner = plainOwnerOf($this->account);
    $other = Account::factory()->create();
    $payment = SubscriptionPayment::create([
        'account_id' => $this->account->id,
        'stripe_invoice_id' => 'in_1',
        'amount' => 500,
        'paid_at' => '2026-11-24 10:05',
    ]);

    // Owners never see the list.
    $this->actingAs($owner);
    asAWebRequest();
    $this->get(AccountResource::getUrl(tenant: $this->account))->assertForbidden();
    $this->get(AccountResource::getUrl('view', ['record' => $this->account], tenant: $this->account))->assertForbidden();

    openAccountAs($this->account, superAdminOf($this->account));

    Livewire::test(ListAccounts::class)
        ->loadTable()
        ->assertCanSeeTableRecords([$this->account, $other])
        ->assertSee('123456789')
        ->assertSee($owner->email)
        ->assertTableActionHasUrl('enter', Dashboard::getUrl(tenant: $other), $other);

    Livewire::test(SubscriptionPaymentsRelationManager::class, ['ownerRecord' => $this->account, 'pageClass' => ViewAccount::class])
        ->loadTable()
        ->assertCanSeeTableRecords([$payment])
        ->callTableAction('markInvoiced', $payment)
        ->assertTableActionHidden('markInvoiced', $payment->fresh());

    expect($payment->fresh()->invoiced_at)->not->toBeNull();
})->group('ui');

it('shows each account\'s billing state', function (string $state, string $label) {
    match ($state) {
        'trial' => $this->account->update(['trial_ends_at' => '2026-11-24 10:00']),
        'monthly' => subscriptionFor($this->account, 'active', 'price_monthly'),
        'yearly' => subscriptionFor($this->account, 'trialing', 'price_yearly'),
        'read only' => $this->account->update(['trial_ends_at' => '2026-11-01 10:00']),
        'exempt' => $this->account->update(['billing_exempt' => true, 'trial_ends_at' => null]),
    };

    if (in_array($state, ['monthly', 'yearly'], true)) {
        $this->account->update(['trial_ends_at' => null]);
    }

    expect(AccountStatus::label($this->account->fresh()))->toBe($label);
})->with([
    ['trial', 'Experiência até 24/11/2026'],
    ['monthly', 'Mensal'],
    ['yearly', 'Anual'],
    ['read only', 'Só leitura'],
    ['exempt', 'Isenta'],
])->group('integration');

it('keeps the accounts list and payments out of an owner\'s reach', function () {
    $owner = openAccountAs($this->account, plainOwnerOf($this->account));

    expect($owner->can('viewAny', Account::class))->toBeFalse()
        ->and($owner->can('ViewAny:SubscriptionPayment'))->toBeFalse()
        ->and(AccountResource::isScopedToTenant())->toBeFalse()
        ->and(AccountResource::canGloballySearch())->toBeFalse();
})->group('integration');

describe('Trial progress', function () {
    beforeEach(function () {
        $this->travelTo(Carbon::parse('2026-11-10 12:00'));
        actingAsAdmin();
    });

    it('shows how much of the free trial is used', function () {
        $account = Filament::getTenant();
        $account->forceFill(['created_at' => '2026-10-30 12:00', 'trial_ends_at' => '2026-11-13 12:00', 'billing_exempt' => false])->save();

        $page = Livewire::test(AccountSubscription::class);

        expect($page->instance()->trialProgress())->toBe(['percent' => 79, 'label' => 'Faltam 3 dias de experiência · 11 de 14 usados']);
        $page->assertSee('Faltam 3 dias de experiência');

        $account->forceFill(['trial_ends_at' => '2026-11-01 12:00'])->save();

        expect(Livewire::test(AccountSubscription::class)->instance()->trialProgress())->toBeNull();
    })->group('ui');
});

it('shows the trial, the two plans and who has accountant access on one page', function () {
    $this->account->update(['trial_ends_at' => now()->addDays(3)]);
    openAccountAs($this->account, plainOwnerOf($this->account));

    Livewire::test(AccountSubscription::class)
        ->assertSee('Faltam 3 dias de experiência.')
        ->assertSee('Em experiência')
        ->assertSee('Poupas '.Money::format(600))
        ->assertSee('Sem IVA. Equivale a '.Money::format(450).' por mês.')
        ->assertSeeLivewire(AccountantAccessWidget::class);
})->group('ui');
