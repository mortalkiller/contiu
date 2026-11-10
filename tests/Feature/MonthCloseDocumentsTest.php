<?php

use App\Actions\RecordAllocatedPayment;
use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Enums\PaymentMethod;
use App\Filament\MonthClose\Widgets\MonthExpensesTable;
use App\Filament\MonthClose\Widgets\MonthPaymentsTable;
use App\Filament\MonthClose\Widgets\MonthReceivablesTable;
use App\Filament\Pages\MonthClose;
use App\Models\Expense;
use App\Models\FiscalDocument;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Support\FinanceSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-12-20'));
    actingAsAdmin();
});

it('shows the current balance separately from the historical month payment rows', function () {
    $receivable = Receivable::factory()->create(['due_on' => '2026-12-01', 'amount' => 6000]);
    $payment = Payment::factory()->for($receivable)->create(['paid_on' => '2027-01-02', 'amount' => 6000]);

    Livewire::test(MonthClose::class, ['month' => '2026-12'])
        ->assertSee('Movimentos tratados')
        ->assertSee('1 registo com dados por completar')
        ->assertDontSee('Mês fechado');

    Livewire::test(MonthReceivablesTable::class, ['month' => '2026-12'])
        ->assertSee('Saldo atual em aberto')
        ->assertSee('Falta fatura ou fatura-recibo');

    Livewire::test(MonthPaymentsTable::class, ['month' => '2026-12'])
        ->assertCanNotSeeTableRecords([$payment]);
});

it('keeps an invoice issued in December visible with its month-end balance after a January payment', function () {
    $receivable = Receivable::factory()->create(['due_on' => '2027-01-31', 'amount' => 120000]);
    FiscalDocument::factory()->create(['document_date' => '2026-12-18', 'number' => 'FT DEZEMBRO'])
        ->links()->create(['receivable_id' => $receivable->id]);
    Payment::factory()->for($receivable)->create(['paid_on' => '2027-01-08', 'amount' => 70000]);
    Payment::factory()->for($receivable)->create(['paid_on' => '2027-01-22', 'amount' => 50000]);

    Livewire::test(MonthReceivablesTable::class, ['month' => '2026-12'])
        ->assertCanSeeTableRecords([$receivable])
        ->assertTableColumnStateSet('outstanding', 0, $receivable)
        ->assertTableColumnStateSet('outstandingAtClose', 120000, $receivable);

    Livewire::test(MonthReceivablesTable::class, ['month' => '2027-01'])
        ->assertTableColumnStateSet('outstandingAtClose', 0, $receivable);
});

it('counts a split receipt once and treats a legacy reference and a voided document as unconfirmed', function () {
    app(FinanceSettings::class)->set(['has_business_account' => false]);
    $project = Project::factory()->create();
    $first = Receivable::factory()->for($project, 'billable')->create(['due_on' => '2026-12-01', 'amount' => 6000]);
    $second = Receivable::factory()->for($project, 'billable')->create(['due_on' => '2026-12-02', 'amount' => 4000]);
    $payment = app(RecordAllocatedPayment::class)->handle([
        'amount' => 10000, 'paid_on' => '2026-12-19', 'method' => PaymentMethod::Transfer, 'invoice_number' => 'REF LEGADA',
    ], [$first->id => 6000, $second->id => 4000]);

    FiscalDocument::factory()->create()->links()->create(['receivable_id' => $first->id]);
    FiscalDocument::factory()->create(['type' => FiscalDocumentType::Receipt])
        ->links()->create(['receivable_id' => $second->id]);

    $document = FiscalDocument::factory()->create([
        'type' => FiscalDocumentType::InvoiceReceipt,
        'status' => FiscalDocumentStatus::Voided,
        'number' => 'FR 2026/11',
    ]);
    $document->links()->create(['payment_id' => $payment->id]);

    Livewire::test(MonthClose::class, ['month' => '2026-12'])
        ->assertSee('2 registos com dados por completar');
    Livewire::test(MonthPaymentsTable::class, ['month' => '2026-12'])
        ->assertCanSeeTableRecords([$payment])
        ->assertSee('Falta recibo ou fatura-recibo')
        ->assertSee('Referência anterior por confirmar')
        ->assertSee('REF LEGADA');

    $document->update(['status' => FiscalDocumentStatus::Active]);

    Livewire::test(MonthClose::class, ['month' => '2026-12'])->assertSee('Mês fechado');
    Livewire::test(MonthPaymentsTable::class, ['month' => '2026-12'])
        ->assertSee('FR 2026/11')
        ->assertDontSee('Falta recibo ou fatura-recibo');
});

it('keeps a split payment pending until receipts cover all documented allocations', function () {
    app(FinanceSettings::class)->set(['has_business_account' => false]);
    $project = Project::factory()->create();
    $first = Receivable::factory()->for($project, 'billable')->create(['due_on' => '2026-12-01', 'amount' => 6000]);
    $second = Receivable::factory()->for($project, 'billable')->create(['due_on' => '2026-12-02', 'amount' => 4000]);
    $payment = app(RecordAllocatedPayment::class)->handle([
        'amount' => 10000, 'paid_on' => '2026-12-19', 'method' => PaymentMethod::Transfer,
    ], [$first->id => 6000, $second->id => 4000]);
    $receipt = function (Receivable $receivable, string $number) use ($payment): void {
        $invoice = FiscalDocument::factory()->create();
        $invoice->links()->create(['receivable_id' => $receivable->id]);
        FiscalDocument::factory()->create([
            'type' => FiscalDocumentType::Receipt,
            'number' => $number,
            'net_amount' => $receivable->amount, 'vat_amount' => 0, 'total_amount' => $receivable->amount,
        ])->links()->create(['payment_id' => $payment->id, 'related_fiscal_document_id' => $invoice->id]);
    };

    $receipt($first, 'RC TESTE/1');
    Livewire::test(MonthClose::class, ['month' => '2026-12'])->assertDontSee('Mês fechado');
    Livewire::test(MonthPaymentsTable::class, ['month' => '2026-12'])->assertSee('Falta recibo para parte do recebimento');

    $receipt($second, 'RC TESTE/2');
    Livewire::test(MonthClose::class, ['month' => '2026-12'])->assertSee('Mês fechado');
    Livewire::test(MonthPaymentsTable::class, ['month' => '2026-12'])->assertDontSee('Falta recibo para parte do recebimento');
});

it('separates expense documents and evidence from amounts to pay and bank reconciliation', function () {
    app(FinanceSettings::class)->set(['has_business_account' => true]);
    $unpaid = Expense::factory()->unpaid()->create(['invoice_number' => 'FT LEGADA']);
    $paid = Expense::factory()->create();
    $paid->forceFill(['proof_path' => 'expense-proofs/'.$paid->account_id.'/'.Str::uuid().'.pdf'])->save();

    FiscalDocument::factory()->create(['direction' => FiscalDocumentDirection::Received, 'number' => 'FT FORNECEDOR'])
        ->links()->create(['expense_id' => $paid->id]);
    $paid->update(['invoice_number' => 'FT FORNECEDOR']);

    Livewire::test(MonthClose::class, ['month' => '2026-12'])
        ->assertSee('1 despesa por pagar')
        ->assertSee('2 registos com dados por completar');

    Livewire::test(MonthExpensesTable::class, ['month' => '2026-12'])
        ->assertCanSeeTableRecords([$unpaid, $paid])
        ->assertTableColumnStateSet('invoice_number', null, $paid)
        ->assertTableColumnStateSet('invoice_number', 'FT LEGADA', $unpaid)
        ->assertSee('Falta fatura ou fatura-recibo')
        ->assertSee('Referência anterior por confirmar')
        ->assertSee('Sem comprovativo')
        ->assertSee('Com comprovativo')
        ->assertSee('FT FORNECEDOR')
        ->assertSee('Por reconciliar');
});
