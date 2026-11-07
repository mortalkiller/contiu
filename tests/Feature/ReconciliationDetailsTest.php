<?php

use App\Filament\MonthClose\Widgets\MonthExpensesTable;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Filament\Resources\Projects\Pages\ViewProject;
use App\Filament\Resources\Shared\RelationManagers\ExpensesRelationManager;
use App\Filament\Resources\Shared\RelationManagers\PaymentsRelationManager;
use App\Models\BankTransaction;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-20'));
    actingAsAdmin();
});

function detailLine(string $date, int $amount, int $balance, string $status, string $description): BankTransaction
{
    static $n = 0;

    return BankTransaction::create(['hash' => 'rd'.(++$n), 'booked_on' => $date, 'description' => $description, 'amount' => $amount, 'balance' => $balance, 'status' => $status, 'import_batch' => 'b']);
}

/** A payment received outside the company account and covered by a transfer three days later. */
function coveredPayment(): Payment
{
    detailLine('2026-11-01', 0, 0, 'matched', 'ABERTURA');
    $project = Project::factory()->create(['code' => 'PRESSIU OC-9']);
    $receivable = Receivable::factory()->for($project, 'billable')->create(['amount' => 65500, 'description' => 'Pagamento único']);
    $payment = Payment::factory()->for($receivable)->create(['amount' => 65500, 'paid_on' => '2026-11-03']);
    detailLine('2026-11-06', 65500, 65500, 'own_transfer', 'TRF DE PEDRO MONTEIRO');

    return $payment;
}

it('shows from the payments list which statement lines reconcile a payment', function () {
    $payment = coveredPayment();

    Livewire::test(ManagePayments::class)
        ->loadTable()
        ->mountTableAction('reconciliation', $payment)
        ->assertMountedActionModalSee(['Reconciliado', '06/11/2026', 'TRF DE PEDRO MONTEIRO', 'Coberto pela transferência', Money::format(65500)]);
});

it('has a bank section when editing a payment', function () {
    $payment = coveredPayment();

    Livewire::test(ManagePayments::class)
        ->loadTable()
        ->mountTableAction('edit', $payment)
        ->assertMountedActionModalSee(['Banco', 'Reconciliado', 'TRF DE PEDRO MONTEIRO']);
});

it('has a bank section when editing a paid expense, saying what is missing', function () {
    detailLine('2026-11-01', 0, 50000, 'matched', 'ABERTURA');
    $expense = Expense::factory()->create(['net_amount' => 8374, 'paid_on' => '2026-11-03', 'spent_on' => '2026-11-03', 'description' => 'ChatGPT Pro']);

    Livewire::test(EditExpense::class, ['record' => $expense->getRouteKey()])
        ->assertSee('Banco')
        ->assertSee('Por reconciliar')
        ->assertSee('Ainda não há nenhuma linha do extrato');
});

it('does not show the bank section for an unpaid expense', function () {
    detailLine('2026-11-01', 0, 0, 'matched', 'ABERTURA');
    $expense = Expense::factory()->unpaid()->create();

    Livewire::test(EditExpense::class, ['record' => $expense->getRouteKey()])
        ->assertDontSee('Ainda não há nenhuma linha do extrato');
});

it('shows the bank column on the payments and expenses of a job', function () {
    $payment = coveredPayment();
    $project = $payment->receivable->billable;
    Expense::factory()->for($project)->create(['net_amount' => 1000, 'kind' => 'direct', 'paid_on' => '2026-11-02', 'spent_on' => '2026-11-02']);

    Livewire::test(PaymentsRelationManager::class, ['ownerRecord' => $project, 'pageClass' => ViewProject::class])
        ->loadTable()
        ->assertSee('Reconciliado');

    Livewire::test(ExpensesRelationManager::class, ['ownerRecord' => $project, 'pageClass' => ViewProject::class])
        ->loadTable()
        ->assertSee('Por reconciliar');
});

it('shows the bank column in the month close expenses', function () {
    detailLine('2026-11-01', 0, 0, 'matched', 'ABERTURA');
    Expense::factory()->create(['net_amount' => 1000, 'paid_on' => '2026-11-02', 'spent_on' => '2026-11-02']);

    Livewire::test(MonthExpensesTable::class, ['month' => '2026-11'])
        ->loadTable()
        ->assertSee('Banco')
        ->assertSee('Por reconciliar');
});

it('totals the direct costs of a job in cents, VAT per invoice', function () {
    $project = Project::factory()->create();
    Expense::factory()->for($project)->create(['net_amount' => 2233, 'vat_rate' => 0.23, 'kind' => 'direct']);
    Expense::factory()->for($project)->create(['net_amount' => 2233, 'vat_rate' => 0.23, 'kind' => 'direct']);

    Livewire::test(ExpensesRelationManager::class, ['ownerRecord' => $project, 'pageClass' => ViewProject::class])
        ->loadTable()
        ->assertSee(Money::format(5494)); // 2 × 27,47 €
});

it('says an expense was given back by a transfer from the company', function () {
    detailLine('2026-11-01', 0, 50000, 'matched', 'ABERTURA');
    $expense = Expense::factory()->create(['net_amount' => 8374, 'paid_on' => '2026-11-03', 'spent_on' => '2026-11-03', 'description' => 'ChatGPT Pro']);
    detailLine('2026-11-04', -8374, 41626, 'own_transfer', 'TRF P/ Ordenado');

    Livewire::test(EditExpense::class, ['record' => $expense->getRouteKey()])
        ->assertSee('Reconciliado')
        ->assertSee('Devolvida pela transferência');
});
