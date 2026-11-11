<?php

use App\Actions\ApplyBankTransaction;
use App\Models\BankTransaction;
use App\Models\Expense;
use App\Models\FiscalDocument;
use App\Models\Payment;
use App\Models\Receivable;

pest()->group('migration', 'fiscal');

it('preserves dated payments, legacy references and reconciliation when adding fiscal documents', function () {
    $migration = require database_path('migrations/2026_11_10_100001_create_fiscal_documents.php');

    $receivable = Receivable::factory()->create(['amount' => 120000, 'due_on' => '2027-01-31']);
    $first = Payment::factory()->for($receivable)->create([
        'amount' => 70000,
        'paid_on' => '2027-01-08',
        'invoice_number' => 'FT 12 / RC 3',
    ]);
    Payment::factory()->for($receivable)->create(['amount' => 50000, 'paid_on' => '2027-01-22']);
    $expense = Expense::factory()->create(['invoice_number' => 'REF FORNECEDOR']);
    $line = BankTransaction::create([
        'hash' => 'fiscal-migration-payment',
        'booked_on' => '2027-01-08',
        'description' => 'Transferência',
        'amount' => 70000,
        'import_batch' => 'demo',
    ]);
    app(ApplyBankTransaction::class)->linkRecords($line, [$first]);

    $allocations = require database_path('migrations/2026_11_10_170658_create_payment_allocations_table.php');
    $allocations->down();
    $migration->down();
    $migration->up();
    $allocations->up();

    expect(FiscalDocument::count())->toBe(0)
        ->and($first->fresh()->invoice_number)->toBe('FT 12 / RC 3')
        ->and($first->fresh()->paid_on->toDateString())->toBe('2027-01-08')
        ->and($expense->fresh()->invoice_number)->toBe('REF FORNECEDOR')
        ->and($receivable->fresh()->received)->toBe(120000)
        ->and($receivable->fresh()->outstanding)->toBe(0)
        ->and($line->links()->sole()->linkable->is($first))->toBeTrue()
        ->and($line->fresh()->difference())->toBe(0);
});
