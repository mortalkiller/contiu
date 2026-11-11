<?php

use App\Actions\ApplyBankTransaction;
use App\Actions\ImportBankStatement;
use App\Enums\BankTransactionStatus;
use App\Enums\FiscalDocumentDirection;
use App\Exceptions\AccountIsReadOnly;
use App\Filament\Pages\BankStatementImport;
use App\Models\Account;
use App\Models\BankTransaction;
use App\Models\Expense;
use App\Models\FiscalDocument;
use App\Models\OneOffIncome;
use App\Models\Payment;
use App\Models\PlatformPayout;
use App\Models\Supplier;
use App\Support\CurrentAccount;
use App\Support\Money;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

pest()->group('banking');

function platformBankLine(int $amount = 90000): BankTransaction
{
    return BankTransaction::create([
        'hash' => 'platform-line', 'booked_on' => '2026-11-10',
        'description' => 'Transferência da plataforma', 'amount' => $amount, 'import_batch' => 'platform-test',
    ]);
}

function platformPayoutData(Supplier $platform, array $overrides = []): array
{
    return array_replace([
        'platform_id' => $platform->id,
        'title' => 'Receita da plataforma',
        'reference' => 'DEP-42',
        'currency' => 'EUR',
        'original_gross' => 100000,
        'original_commission' => 10000,
    ], $overrides);
}

it('reconciles a platform deposit from the bank page and retains its evidence and history on undo', function () {
    actingAsAdmin();
    app(ImportBankStatement::class)->store([
        ['booked_on' => '2026-11-10', 'description' => 'Transferência da plataforma', 'amount' => 90000, 'balance' => null],
    ]);
    $line = BankTransaction::sole();
    $platform = Supplier::factory()->create(['name' => 'Plataforma de teste']);
    $evidence = FiscalDocument::factory()->create(['direction' => FiscalDocumentDirection::Received, 'number' => 'COM-42']);

    $page = Livewire::test(BankStatementImport::class)->loadTable()
        ->mountTableAction('platformPayout', $line)
        ->setTableActionData(['payouts' => [[
            ...platformPayoutData($platform, ['original_gross' => '1000,00', 'original_commission' => '100,00']),
            'commission_document_id' => $evidence->id,
        ]]])
        ->assertMountedActionModalSee('Diferença face ao banco: '.Money::format(0))
        ->callMountedTableAction()
        ->assertHasNoActionErrors()
        ->assertNotified('Linha do extrato tratada');

    $payout = PlatformPayout::active()->sole();
    expect($line->fresh()->status)->toBe(BankTransactionStatus::Matched)
        ->and($line->difference())->toBe(0)
        ->and(Payment::sole()->amount)->toBe(100000)
        ->and(Expense::sole()->total)->toBe(10000)
        ->and($payout->platform_name)->toBe('Plataforma de teste')
        ->and($payout->commissionDocument->id)->toBe($evidence->id)
        ->and($payout->income->commissionExpense->total)->toBe(10000);

    $page->set('activeTab', 'done')->mountTableAction('details', $line)
        ->assertMountedActionModalSee(['DEP-42', 'COM-42', 'Diferença: '.Money::format(0)])
        ->unmountAction()
        ->callTableAction('undo', $line)
        ->assertNotified('A linha voltou a "Por tratar"');

    expect($line->fresh()->status)->toBe(BankTransactionStatus::Pending)
        ->and($line->links()->count())->toBe(0)
        ->and(OneOffIncome::count())->toBe(0)
        ->and(Expense::count())->toBe(0)
        ->and(Payment::count())->toBe(0)
        ->and($payout->fresh()->undone_at)->not->toBeNull()
        ->and(PlatformPayout::active()->count())->toBe(0)
        ->and(FiscalDocument::sole()->id)->toBe($evidence->id);

    $page->set('activeTab', 'pending')->mountTableAction('details', $line)
        ->assertMountedActionModalSee(['DEP-42', 'Reconciliação desfeita']);
})->group('ui');

it('reconciles grouped deposits using a confirmed conversion and independent original values', function () {
    $line = platformBankLine();
    $platform = Supplier::factory()->create();
    $action = app(ApplyBankTransaction::class);

    $action->createPlatformPayouts($line, [
        platformPayoutData($platform, ['currency' => 'JPY', 'original_gross' => 100000, 'original_commission' => 10000,
            'fx_rate' => '0.006', 'fx_reference' => 'Extrato FX-1', 'fx_confirmed' => true]),
        platformPayoutData($platform, ['reference' => 'DEP-43', 'original_gross' => 40000, 'original_commission' => 4000]),
    ]);

    $converted = PlatformPayout::where('currency', 'JPY')->sole();
    expect($line->links()->count())->toBe(4)
        ->and($line->difference())->toBe(0)
        ->and($converted->original_gross)->toBe(100000)
        ->and($converted->original_commission)->toBe(10000)
        ->and($converted->gross_eur)->toBe(60000)
        ->and($converted->commission_eur)->toBe(6000)
        ->and($converted->fx_reference)->toBe('Extrato FX-1');

    expect(fn () => $action->createPlatformPayouts($line, [platformPayoutData($platform)]))
        ->toThrow(DomainException::class, 'já foi tratada');

    $action->undo($line);

    expect(OneOffIncome::count())->toBe(0)
        ->and(Expense::count())->toBe(0)
        ->and(PlatformPayout::whereNotNull('undone_at')->count())->toBe(2);
})->group('integration');

it('rejects an invalid payout without creating any records', function (array $overrides, string $message) {
    $line = platformBankLine();
    $platform = Supplier::factory()->create();

    expect(fn () => app(ApplyBankTransaction::class)->createPlatformPayouts($line, [platformPayoutData($platform, $overrides)]))
        ->toThrow(DomainException::class, $message);

    expect($line->fresh()->status)->toBe(BankTransactionStatus::Pending)
        ->and($line->links()->count())->toBe(0)
        ->and(OneOffIncome::count())->toBe(0)
        ->and(PlatformPayout::count())->toBe(0);
})->with([
    'net mismatch' => [['original_commission' => 9000], 'diferença'],
    'fee exceeds gross' => [['original_commission' => 100001], 'comissão'],
    'negative commission' => [['original_commission' => -100], 'comissão'],
    'unsupported currency' => [['currency' => 'ZZZ'], 'moeda'],
    'zero FX rate' => [['currency' => 'USD', 'fx_rate' => '0.0'], 'taxa'],
    'unverified FX' => [['currency' => 'USD', 'fx_rate' => '1', 'fx_reference' => 'FX-1'], 'conversão'],
    'missing FX evidence' => [['currency' => 'USD', 'fx_rate' => '1', 'fx_confirmed' => true], 'conversão'],
])->group('integration');

it('rolls back all deposits if the last deposit in the group cannot be reconciled', function () {
    $line = platformBankLine();
    $platform = Supplier::factory()->create();
    $issued = FiscalDocument::factory()->create();

    expect(fn () => app(ApplyBankTransaction::class)->createPlatformPayouts($line, [
        platformPayoutData($platform, ['original_gross' => 50000, 'original_commission' => 5000]),
        platformPayoutData($platform, ['original_gross' => 50000, 'original_commission' => 5000, 'commission_document_id' => $issued->id]),
    ]))->toThrow(DomainException::class, 'documento recebido e ativo');

    expect($line->fresh()->status)->toBe(BankTransactionStatus::Pending)
        ->and($line->links()->count())->toBe(0)
        ->and(OneOffIncome::count())->toBe(0)
        ->and(Payment::count())->toBe(0)
        ->and(Expense::count())->toBe(0)
        ->and(PlatformPayout::count())->toBe(0);
})->group('integration');

it('preserves the payout when an expense acquires a fiscal document link after reconciliation', function () {
    $line = platformBankLine();
    $platform = Supplier::factory()->create();
    $action = app(ApplyBankTransaction::class);
    [$payout] = $action->createPlatformPayouts($line, [platformPayoutData($platform)]);

    $document = FiscalDocument::factory()->create(['direction' => FiscalDocumentDirection::Received]);
    $document->links()->create(['expense_id' => Expense::sole()->id]);

    expect(fn () => $action->undo($line))->toThrow(DomainException::class, 'documento fiscal');
    expect($line->fresh()->status)->toBe(BankTransactionStatus::Matched)
        ->and($line->difference())->toBe(0)
        ->and($payout->fresh()->undone_at)->toBeNull();
})->group('integration');

it('rejects payout sources and documents from other accounts and leaves the bank line pending', function () {
    $line = platformBankLine();
    $platform = Supplier::factory()->create();
    [$foreignLine, $foreignPlatform, $foreignDocument] = CurrentAccount::run(Account::factory()->create(), fn () => [
        platformBankLine(),
        Supplier::factory()->create(),
        FiscalDocument::factory()->create(['direction' => FiscalDocumentDirection::Received]),
    ]);
    $action = app(ApplyBankTransaction::class);

    expect(fn () => $action->createPlatformPayouts($line, [platformPayoutData($foreignPlatform)]))
        ->toThrow(DomainException::class, 'plataforma');
    expect(fn () => $action->createPlatformPayouts($line, [platformPayoutData($platform, ['commission_document_id' => $foreignDocument->id])]))
        ->toThrow(DomainException::class, 'documento');
    expect(fn () => $action->createPlatformPayouts($foreignLine, [platformPayoutData($platform)]))
        ->toThrow(ModelNotFoundException::class);
    expect($line->fresh()->status)->toBe(BankTransactionStatus::Pending)
        ->and(PlatformPayout::count())->toBe(0);
})->group('integration');

it('blocks creating or undoing a platform payout when the account is read-only', function () {
    $line = platformBankLine();
    $platform = Supplier::factory()->create();
    $action = app(ApplyBankTransaction::class);
    $action->createPlatformPayouts($line, [platformPayoutData($platform)]);
    $this->account->update(['trial_ends_at' => now()->subDay()]);

    expect(fn () => $action->undo($line))->toThrow(AccountIsReadOnly::class)
        ->and($line->fresh()->status)->toBe(BankTransactionStatus::Matched)
        ->and(PlatformPayout::sole()->undone_at)->toBeNull();

    expect(fn () => $action->createPlatformPayouts($line, [platformPayoutData($platform)]))
        ->toThrow(AccountIsReadOnly::class);
})->group('integration');
