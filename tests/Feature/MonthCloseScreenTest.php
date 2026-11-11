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

pest()->group('ui');

describe('Month navigation and checklist', function () {
    beforeEach(function () {
        $this->travelTo(Carbon::parse('2026-03-20'));
        actingAsAdmin();
    });

    it('opens on the current month and says how much is left to deal with', function () {
        Receivable::factory()->create(['due_on' => '2026-03-05']);
        Expense::factory()->unpaid()->create(['spent_on' => '2026-03-02']);

        Livewire::test(MonthClose::class)
            ->assertSet('month', '2026-03')
            ->assertSee('Março 2026')
            ->assertSee('Faltam 2 itens');
    });

    it('moves to the previous and next month', function () {
        Livewire::test(MonthClose::class)
            ->callAction('previousMonth')
            ->assertSet('month', '2026-02')
            ->callAction('nextMonth')
            ->callAction('nextMonth')
            ->assertSet('month', '2026-04');
    });

    it('says the month is closed when nothing is left', function () {
        Livewire::test(MonthClose::class, ['month' => '2026-01'])
            ->assertSee('Mês fechado');
    });

    it('updates the summary when moving from a closed month to one with items left', function () {
        Receivable::factory()->create(['amount' => 2000, 'due_on' => '2026-04-01']);

        Livewire::test(MonthClose::class, ['month' => '2026-03'])
            ->assertSee('Mês fechado')
            ->callAction('nextMonth')
            ->assertSet('month', '2026-04')
            ->assertSee('Falta 1 item')
            ->assertDontSee('Mês fechado')
            ->tap(fn ($page) => expect($page->instance()->getWidgetData())->toBe(['month' => '2026-04']));
    });

    it('breaks what is left into steps, with how many are done', function () {
        Receivable::factory()->create(['due_on' => '2026-03-05']);
        Expense::factory()->unpaid()->create(['spent_on' => '2026-03-02']);
        Expense::factory()->unpaid()->create(['spent_on' => '2026-03-09']);

        Livewire::test(MonthClose::class)
            ->assertSee('O que falta para fechar')
            ->assertSeeInOrder(['1. Recebido', '1 por receber', '2. Despesas', '2 por pagar']);
    });

    it('marks every step as done in a closed month', function () {
        Livewire::test(MonthClose::class, ['month' => '2026-01'])
            ->assertSeeInOrder(['1. Recebido', 'Tudo recebido', '2. Despesas', 'Todas pagas'])
            ->assertSee('Completos')
            ->assertDontSee('por receber')
            ->assertDontSee('por pagar');
    });
});

describe('Payments and expenses', function () {
    beforeEach(function () {
        $this->travelTo(Carbon::parse('2026-03-20'));
        actingAsAdmin();
    });

    it('lists the month receivables and lets you record what came in', function () {
        $receivable = Receivable::factory()->create(['amount' => 20000, 'due_on' => '2026-03-05']);

        Livewire::test(MonthReceivablesTable::class, ['month' => '2026-03'])
            ->assertCanSeeTableRecords([$receivable])
            ->callTableAction('recordPayment', $receivable, data: [
                'paid_on' => '2026-03-20',
                'amount' => 200,
                'method' => 'transfer',
            ])
            ->assertHasNoTableActionErrors();

        expect($receivable->fresh()->outstanding)->toBe(0);
    });

    it('marks an expense as paid and stores its invoice number inline', function () {
        $expense = Expense::factory()->unpaid()->create(['spent_on' => '2026-03-02']);

        Livewire::test(MonthExpensesTable::class, ['month' => '2026-03'])
            ->assertCanSeeTableRecords([$expense])
            ->callTableAction('markPaid', $expense)
            ->call('updateTableColumnState', 'invoice_number', (string) $expense->getKey(), 'FT 2026/9');

        expect($expense->fresh())
            ->is_paid->toBeTrue()
            ->paid_on->toDateString()->toBe('2026-03-20')
            ->invoice_number->toBe('FT 2026/9');
    });
});

describe('Fiscal documents and historical balances', function () {
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
});
