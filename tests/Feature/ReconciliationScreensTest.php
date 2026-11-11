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
use App\Support\Money;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

pest()->group('ui', 'banking');

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

it('shows in the preview which payments each transfer covers', function () {
    app(FinanceSettings::class)->set(['account_names' => 'Pedro Monteiro']);
    screenLine('2026-11-01', 0, 0);
    $receivable = Receivable::factory()->for(Project::factory()->create(['code' => 'PRESSIU OC-9']), 'billable')->create(['amount' => 65500, 'description' => 'Pagamento único']);
    Payment::factory()->for($receivable)->create(['amount' => 65500, 'paid_on' => '2026-11-04']); // received outside the company account
    screenLine('2026-11-06', 65500, 65500, 'pending', 'TRF DE PEDRO MONTEIRO');

    Livewire::test(BankStatementImport::class)
        ->mountAction('autoReconcile')
        ->assertMountedActionModalSee(['Transferência minha', 'Cobre:', 'Recebimento PRESSIU OC-9 · Pagamento único · 04/11/2026']);
});

it('says when a transfer covers nothing still to reconcile', function () {
    app(FinanceSettings::class)->set(['account_names' => 'Ordenado']);
    screenLine('2026-10-06', -15100, 0, 'pending', 'TRF P/ Ordenado');

    Livewire::test(BankStatementImport::class)
        ->mountAction('autoReconcile')
        ->assertMountedActionModalSee('Não cobre nenhum recebimento ou despesa por reconciliar');
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

it('sums up in the treated tab what a transfer covered, with the details one click away', function () {
    screenLine('2026-11-01', 0, 0);
    $project = Project::factory()->for(Client::factory()->create(['name' => 'Gonçalo Pimenta']))->create(['code' => 'PRESSIU OC-9']);
    $receivable = Receivable::factory()->for($project, 'billable')->create(['amount' => 65500, 'description' => 'Pagamento único']);
    Payment::factory()->for($receivable)->create(['amount' => 65500, 'paid_on' => '2026-11-04']);
    $transfer = screenLine('2026-11-06', 70000, 70000, 'own_transfer', 'TRF DE PEDRO MONTEIRO');

    Livewire::test(BankStatementImport::class)
        ->set('activeTab', 'done')
        ->loadTable()
        ->assertSee('Cobre 1 recebimento')
        ->assertDontSee('Recebimento PRESSIU OC-9 · Pagamento único · 04/11/2026')
        ->assertTableActionVisible('details', $transfer)
        ->mountTableAction('details', $transfer)
        ->assertMountedActionModalSee([
            'TRF DE PEDRO MONTEIRO', Money::format(70000),
            'Recebimento', 'PRESSIU OC-9 · Pagamento único', 'Gonçalo Pimenta', '04/11/2026', Money::format(65500), 'Completo',
            'Sobram '.Money::format(4500),
        ]);
});

it('shows the details of a line linked to records', function () {
    $project = Project::factory()->for(Client::factory()->create(['name' => 'Mariana Moreira']))->create(['code' => 'MINFRA']);
    $receivable = Receivable::factory()->for($project, 'billable')->create(['amount' => 2000, 'description' => 'Nov 2026']);
    $payment = Payment::factory()->for($receivable)->create(['amount' => 2000, 'paid_on' => '2026-11-04']);
    $line = screenLine('2026-11-04', 2000, 2000, 'matched', 'MBWAY MARIANA');
    $line->links()->create(['linkable_type' => $payment->getMorphClass(), 'linkable_id' => $payment->id]);

    Livewire::test(BankStatementImport::class)
        ->set('activeTab', 'done')
        ->loadTable()
        ->mountTableAction('details', $line)
        ->assertMountedActionModalSee(['MINFRA · Nov 2026', 'Mariana Moreira', 'Completo']);
});
