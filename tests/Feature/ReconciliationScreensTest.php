<?php

use App\Filament\Pages\BankStatementImport;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Resources\Payments\Pages\ManagePayments;
use App\Filament\Widgets\CompanyAccountStats;
use App\Models\BankTransaction;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Support\FinanceSettings;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-20'));
    actingAsAdmin();
});

function screenLine(string $date, int $amount, int $balance, string $status = 'matched', string $description = 'TRF'): BankTransaction
{
    static $n = 0;

    return BankTransaction::create(['hash' => 'sc'.(++$n), 'booked_on' => $date, 'description' => $description, 'amount' => $amount, 'balance' => $balance, 'status' => $status, 'import_batch' => 'b']);
}

it('shows and filters reconciled payments', function () {
    screenLine('2026-11-01', 0, 0);
    $covered = Payment::factory()->create(['amount' => 30000, 'paid_on' => '2026-11-02']);
    $missing = Payment::factory()->create(['amount' => 20000, 'paid_on' => '2026-11-03']);
    screenLine('2026-11-05', 30000, 30000, 'own_transfer');

    Livewire::test(ManagePayments::class)->loadTable()
        ->assertSee('Reconciliado')
        ->assertSee('Por reconciliar')
        ->filterTable('reconciliation', 'not_reconciled')
        ->assertCanSeeTableRecords([$missing])
        ->assertCanNotSeeTableRecords([$covered]);
});

it('shows and filters reconciled expenses', function () {
    screenLine('2026-11-01', 0, 50000);
    $pending = Expense::factory()->create(['net_amount' => 8374, 'paid_on' => '2026-11-03', 'spent_on' => '2026-11-03']);
    $unpaid = Expense::factory()->unpaid()->create(['net_amount' => 1000, 'spent_on' => '2026-11-03']);

    Livewire::test(ListExpenses::class)->loadTable()
        ->assertSee('Por reconciliar')
        ->filterTable('reconciliation', 'not_reconciled')
        ->assertCanSeeTableRecords([$pending])
        ->assertCanNotSeeTableRecords([$unpaid]);
});

it('reconciles every safe line with one click and leaves the rest', function () {
    app(FinanceSettings::class)->set(['account_names' => 'Ordenado']);
    $project = Project::factory()->for(Client::factory()->create(['name' => 'Gonçalo Pimenta']))->create();
    Receivable::factory()->for($project, 'billable')->create(['amount' => 73000, 'due_on' => '2026-10-31']);
    $own = screenLine('2026-10-06', -15100, 0, 'pending', 'TRF P/ Ordenado');
    $client = screenLine('2026-10-23', 73000, 73000, 'pending', 'TRF GONCALO PIMENTA');
    $unknown = screenLine('2026-10-24', 432, 73432, 'pending', 'STRIPE TECHNOLOGY EUROPE');

    Livewire::test(BankStatementImport::class)
        ->callAction('autoReconcile')
        ->assertNotified('2 linhas tratadas');

    expect($own->fresh()->status->value)->toBe('own_transfer')
        ->and($client->fresh()->status->value)->toBe('matched')
        ->and($unknown->fresh()->status->value)->toBe('pending');
});

it('previews what the automatic reconciliation will do without changing anything', function () {
    app(FinanceSettings::class)->set(['account_names' => 'Ordenado']);
    $project = Project::factory()->for(Client::factory()->create(['name' => 'Gonçalo Pimenta']))->create(['code' => 'PRESSIU OC-7']);
    Receivable::factory()->for($project, 'billable')->create(['amount' => 73000, 'due_on' => '2026-10-31', 'description' => 'Pagamento único']);
    $own = screenLine('2026-10-06', -15100, 0, 'pending', 'TRF P/ Ordenado');
    $client = screenLine('2026-10-23', 73000, 73000, 'pending', 'TRF GONCALO PIMENTA');
    screenLine('2026-10-24', 432, 73432, 'pending', 'STRIPE TECHNOLOGY EUROPE');

    Livewire::test(BankStatementImport::class)
        ->mountAction('autoReconcile')
        ->assertMountedActionModalSee([
            'Vai tratar 2 linhas',
            'TRF P/ Ordenado', 'Transferência minha',
            'TRF GONCALO PIMENTA', 'Recebimento · PRESSIU OC-7 · Pagamento único',
            'Fica por tratar (1)', 'STRIPE TECHNOLOGY EUROPE',
        ]);

    expect($own->fresh()->status->value)->toBe('pending')
        ->and($client->fresh()->status->value)->toBe('pending')
        ->and(Payment::count())->toBe(0);
});

it('says when there is nothing it can reconcile', function () {
    screenLine('2026-10-24', 432, 432, 'pending', 'STRIPE TECHNOLOGY EUROPE');

    Livewire::test(BankStatementImport::class)
        ->mountAction('autoReconcile')
        ->assertMountedActionModalSee('Nenhuma linha tem uma sugestão segura');
});

it('links from the company account card to what is still to reconcile', function () {
    screenLine('2026-11-01', 0, 0);
    Payment::factory()->create(['amount' => 20000, 'paid_on' => '2026-11-03']);
    screenLine('2026-11-05', 0, 0); // statement goes past the payment, which never reached the bank

    Livewire::test(CompanyAccountStats::class)
        ->assertSee('Ver o que falta reconciliar');
});
