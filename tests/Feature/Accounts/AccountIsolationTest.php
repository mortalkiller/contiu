<?php

use App\Actions\ImportBankStatement;
use App\Actions\RecordOneOffIncome;
use App\Enums\Frequency;
use App\Filament\Resources\Retainers\Pages\CreateRetainer;
use App\Models\Account;
use App\Models\Audit;
use App\Models\BankTransaction;
use App\Models\BankTransactionLink;
use App\Models\Category;
use App\Models\Client;
use App\Models\ClientMessage;
use App\Models\Concerns\BelongsToAccount;
use App\Models\Expense;
use App\Models\OneOffIncome;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\RecurringExpense;
use App\Models\Retainer;
use App\Models\Setting;
use App\Models\SubscriptionPayment;
use App\Models\Supplier;
use App\Support\CurrentAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;

/** One record of the given business model, created in the current account. */
function oneRecordOf(string $model): Model
{
    return match ($model) {
        BankTransaction::class => BankTransaction::create(['hash' => 'h1', 'booked_on' => '2026-10-01', 'description' => 'TRF', 'amount' => 1000, 'balance' => 1000, 'import_batch' => 'b']),
        BankTransactionLink::class => BankTransactionLink::create([
            'bank_transaction_id' => BankTransaction::create(['hash' => 'h2', 'booked_on' => '2026-10-01', 'description' => 'TRF', 'amount' => 1000, 'balance' => 1000, 'import_batch' => 'b'])->id,
            'linkable_type' => Payment::class,
            'linkable_id' => Payment::factory()->create()->id,
        ]),
        ClientMessage::class => ClientMessage::create(['client_id' => Client::factory()->create()->id, 'type' => ClientMessage::STATEMENT, 'to' => 'a@b.pt', 'subject' => 'Extrato', 'body' => '…', 'sent_at' => now()]),
        Setting::class => Setting::create(['key' => 'vat_exempt', 'value' => false]),
        default => $model::factory()->create(),
    };
}

it('keeps every business model inside its account', function (string $model) {
    $a = Account::factory()->create();
    $b = Account::factory()->create();

    $recordA = CurrentAccount::run($a, fn () => oneRecordOf($model));
    $recordB = CurrentAccount::run($b, fn () => oneRecordOf($model));

    expect($recordA->account_id)->toBe($a->id)
        ->and($recordB->account_id)->toBe($b->id)
        ->and(CurrentAccount::run($a, fn () => $model::query()->pluck('id')->all()))->toBe([$recordA->id])
        ->and(CurrentAccount::run($b, fn () => $model::query()->pluck('id')->all()))->toBe([$recordB->id]);
})->with([
    Client::class,
    Supplier::class,
    Category::class,
    Project::class,
    Retainer::class,
    Receivable::class,
    Payment::class,
    Expense::class,
    RecurringExpense::class,
    OneOffIncome::class,
    BankTransaction::class,
    BankTransactionLink::class,
    ClientMessage::class,
    Setting::class,
]);

it('lets two accounts import the same bank statement', function () {
    $a = Account::factory()->create();
    $b = Account::factory()->create();
    $rows = [
        ['booked_on' => '2026-10-01', 'description' => 'TRF PRINTEE', 'amount' => 120000, 'balance' => 120000],
        ['booked_on' => '2026-10-02', 'description' => 'OVH', 'amount' => -1500, 'balance' => 118500],
    ];

    $first = CurrentAccount::run($a, fn () => app(ImportBankStatement::class)->store($rows));
    $second = CurrentAccount::run($b, fn () => app(ImportBankStatement::class)->store($rows));
    $again = CurrentAccount::run($b, fn () => app(ImportBankStatement::class)->store($rows));

    expect($first)->toBe(['created' => 2, 'skipped' => 0])
        ->and($second)->toBe(['created' => 2, 'skipped' => 0])
        ->and($again)->toBe(['created' => 0, 'skipped' => 2])
        ->and(CurrentAccount::run($a, fn () => BankTransaction::count()))->toBe(2)
        ->and(CurrentAccount::run($b, fn () => BankTransaction::count()))->toBe(2);
});

it('allows the same project and retainer code in two accounts', function () {
    $a = Account::factory()->create();
    $b = Account::factory()->create();

    foreach ([$a, $b] as $account) {
        CurrentAccount::run($account, function () {
            Project::factory()->create(['code' => 'PRINTEE OC-202611-1']);
            Retainer::factory()->create(['code' => 'MINFRA']);
        });
    }

    expect(CurrentAccount::run($a, fn () => Project::where('code', 'PRINTEE OC-202611-1')->count()))->toBe(1)
        ->and(CurrentAccount::run($b, fn () => Retainer::where('code', 'MINFRA')->count()))->toBe(1);
});

it('checks the retainer code only against the current account in the form', function () {
    actingAsAdmin();
    CurrentAccount::run(Account::factory()->create(), fn () => Retainer::factory()->create(['code' => 'MANUT']));
    $client = Client::factory()->create();
    $form = [
        'code' => 'MANUT',
        'client_id' => $client->id,
        'title' => 'Manutenção',
        'net_amount' => 50,
        'vat_rate' => '0.0000',
        'frequency' => Frequency::Monthly,
        'due_day' => 1,
        'starts_on' => '2026-02-01',
        'is_active' => true,
    ];

    Livewire::test(CreateRetainer::class)->fillForm($form)->call('create')->assertHasNoFormErrors();
    Livewire::test(CreateRetainer::class)->fillForm($form)->call('create')->assertHasFormErrors(['code' => 'unique']);
});

it('numbers one-off incomes per account', function () {
    $a = Account::factory()->create();
    $b = Account::factory()->create();
    $income = ['received_on' => '2026-10-14', 'title' => 'Donativo', 'gross_amount' => 5000];

    $codes = [
        CurrentAccount::run($a, fn () => app(RecordOneOffIncome::class)->handle($income)->code),
        CurrentAccount::run($b, fn () => app(RecordOneOffIncome::class)->handle($income)->code),
        CurrentAccount::run($b, fn () => app(RecordOneOffIncome::class)->handle($income)->code),
    ];

    expect($codes)->toBe(['RA-20261014-1', 'RA-20261014-1', 'RA-20261014-2']);
});

it('scopes every model whose table has account_id', function () {
    // Deliberately unscoped: audits are written outside any account too (logins, console);
    // subscription payments are written by Stripe's webhook and read only by super admins.
    $unscoped = [Audit::class, SubscriptionPayment::class];

    $models = collect(glob(app_path('Models/*.php')))
        ->map(fn (string $path) => 'App\\Models\\'.basename($path, '.php'))
        ->filter(fn (string $class) => is_subclass_of($class, Model::class) && ! (new ReflectionClass($class))->isAbstract())
        ->filter(fn (string $class) => Schema::hasColumn((new $class)->getTable(), 'account_id'));

    expect($models->count())->toBeGreaterThanOrEqual(14);

    $models->reject(fn (string $class) => in_array($class, $unscoped, true))
        ->each(fn (string $class) => expect(class_uses_recursive($class))->toHaveKey(BelongsToAccount::class, message: "{$class} has account_id but no BelongsToAccount"));
});

it('stamps audits with the current account', function () {
    config(['audit.console' => true]); // auditing is off for console runs, tests included

    $client = Client::factory()->create();

    expect($client->audits()->latest('id')->first())
        ->toBeInstanceOf(Audit::class)
        ->account_id->toBe(test()->account->id);
});
