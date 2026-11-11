<?php

use App\Enums\ExpenseKind;
use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Enums\Frequency;
use App\Enums\PaymentMethod;
use App\Enums\ProjectStatus;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Widgets\ExpensesSummary;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Resources\FiscalDocuments\Pages\ListFiscalDocuments;
use App\Filament\Resources\FiscalDocuments\Widgets\FiscalDocumentsSummary;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Payments\Widgets\PaymentsSummary;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\Widgets\ProjectsSummary;
use App\Filament\Resources\Receivables\ReceivableResource;
use App\Filament\Resources\Receivables\Widgets\ReceivablesByClient;
use App\Filament\Resources\Receivables\Widgets\ReceivablesSummary;
use App\Filament\Resources\Retainers\RetainerResource;
use App\Filament\Resources\Retainers\Widgets\RetainersAhead;
use App\Filament\Resources\Retainers\Widgets\RetainersSummary;
use App\Filament\Resources\Suppliers\Pages\ManageSuppliers;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Resources\Suppliers\Widgets\SuppliersBySpend;
use App\Filament\Support\ProportionBar;
use App\Models\Category;
use App\Models\Client;
use App\Models\CreditApplication;
use App\Models\Expense;
use App\Models\FiscalDocument;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\Retainer;
use App\Models\Supplier;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-10'));
    actingAsAdmin();
});

it('splits what is still to receive by how soon it is due', function () {
    Receivable::factory()->create(['amount' => 18000, 'due_on' => '2026-10-28']);
    Receivable::factory()->create(['amount' => 40000, 'due_on' => '2026-11-15']);
    Receivable::factory()->create(['amount' => 18000, 'due_on' => '2026-11-30']);
    $partial = Receivable::factory()->create(['amount' => 80000, 'due_on' => '2027-02-23']);
    Payment::factory()->for($partial)->create(['amount' => 65000]);

    Livewire::test(ReceivablesSummary::class)
        ->assertSee('Em aberto')
        ->assertSee('910,00 €')
        ->assertSeeInOrder(['Vencido', '180,00 €', 'Até 7 dias', '400,00 €', '8 a 30 dias', '180,00 €', 'Mais tarde', '150,00 €'])
        ->assertSee('1 valor · 1 cliente');
})->group('ui');

it('says nothing is late when every open amount is still to come', function () {
    Receivable::factory()->create(['amount' => 5000, 'due_on' => '2026-11-20']);

    Livewire::test(ReceivablesSummary::class)->assertSee('Nada vencido');
})->group('ui');

it('leaves paid amounts out of what is still to receive', function () {
    $paid = Receivable::factory()->create(['amount' => 5000, 'due_on' => '2026-10-01']);
    Payment::factory()->for($paid)->create(['amount' => 5000]);

    Livewire::test(ReceivablesSummary::class)
        ->assertSee('0,00 €')
        ->assertSee('Nada vencido');
})->group('ui');

it('sums up expenses: to pay, paid this month, without proof and by kind', function () {
    Expense::factory()->unpaid()->create(['net_amount' => 5000, 'vat_rate' => 0.23, 'spent_on' => '2026-11-10']);
    Expense::factory()->create(['net_amount' => 2000, 'spent_on' => '2026-11-10', 'paid_on' => '2026-11-10']);
    Expense::factory()->create(['net_amount' => 30000, 'spent_on' => '2026-02-25', 'paid_on' => '2026-02-25', 'kind' => ExpenseKind::Direct]);

    Livewire::test(ExpensesSummary::class)
        ->assertSeeInOrder(['Por pagar', '61,50 €', '1 despesa'])
        ->assertSeeInOrder(['Pagas em', '20,00 €'])
        ->assertSeeInOrder(['Sem comprovativo', '3'])
        ->assertSeeInOrder(['Em 2026', '381,50 €', 'Estrutura', '81,50 €', 'Diretas', '300,00 €']);
})->group('ui');

it('says everything is paid when no expense is waiting', function () {
    Expense::factory()->create();

    Livewire::test(ExpensesSummary::class)->assertSee('Tudo pago');
})->group('ui');

it('shows the summaries above the lists', function () {
    $this->get(ReceivableResource::getUrl())->assertOk()->assertSeeLivewire(ReceivablesSummary::class);
    $this->get(ExpenseResource::getUrl())->assertOk()->assertSeeLivewire(ExpensesSummary::class);
})->group('ui');

it('draws no proportion bar when there is nothing to split', function () {
    expect((string) ProportionBar::render([['label' => 'Vencido', 'cents' => 0, 'tone' => 'danger']]))->toBe('');
})->group('integration');

it('escapes labels in the proportion bar', function () {
    expect((string) ProportionBar::render([['label' => '<b>x</b>', 'cents' => 100, 'tone' => 'strong']]))
        ->not->toContain('<b>x</b>')
        ->toContain('&lt;b&gt;x&lt;/b&gt;');
})->group('integration');

it('sums up projects: in progress, still to receive, proposals and completed', function () {
    $running = Project::factory()->create(['net_amount' => 80000, 'status' => ProjectStatus::InProgress, 'quoted_on' => '2026-09-01']);
    $signal = Receivable::factory()->for($running, 'billable')->create(['amount' => 40000, 'due_on' => '2026-09-15']);
    Payment::factory()->for($signal)->create(['amount' => 40000]);
    Receivable::factory()->for($running, 'billable')->create(['amount' => 40000, 'due_on' => '2027-02-23']);

    Project::factory()->create(['net_amount' => 90000, 'status' => ProjectStatus::Proposal, 'quoted_on' => '2026-10-29']);
    Project::factory()->create(['net_amount' => 120000, 'status' => ProjectStatus::Completed, 'quoted_on' => '2026-01-12']);
    Project::factory()->create(['net_amount' => 50000, 'status' => ProjectStatus::Cancelled]);

    Livewire::test(ProjectsSummary::class)
        ->assertSeeInOrder(['Em curso', '800,00 €', '1 trabalho · 400,00 € recebidos'])
        ->assertSeeInOrder(['Por receber', '400,00 €', '1 trabalho · nada vencido'])
        ->assertSeeInOrder(['Propostas à espera', '900,00 €', '1 proposta · a mais antiga há 12 dias'])
        ->assertSeeInOrder(['Propostas aprovadas', '2 de 4']);
})->group('ui');

it('flags overdue money on projects', function () {
    $project = Project::factory()->create(['net_amount' => 30000, 'status' => ProjectStatus::InProgress]);
    Receivable::factory()->for($project, 'billable')->create(['amount' => 30000, 'due_on' => '2026-10-01']);

    Livewire::test(ProjectsSummary::class)->assertSee('300,00 € vencidos');
})->group('ui');

it('sums up retainers: per month, per year, next due date and what is open', function () {
    $retainer = Retainer::factory()->create(['net_amount' => 18000, 'frequency' => Frequency::Monthly]);
    Receivable::factory()->for($retainer, 'billable')->create(['amount' => 18000, 'due_on' => '2026-10-28']);
    Receivable::factory()->for($retainer, 'billable')->create(['amount' => 18000, 'due_on' => '2026-11-30']);
    Retainer::factory()->create(['net_amount' => 99900, 'is_active' => false]);

    Livewire::test(RetainersSummary::class)
        ->assertSeeInOrder(['Receita recorrente por mês', '180,00 €', '1 avença ativa'])
        ->assertSeeInOrder(['Por ano', Money::format(216000)])
        ->assertSeeInOrder(['Próximo vencimento', '28/10/2026', $retainer->client->name])
        ->assertSeeInOrder(['Em aberto', '360,00 €', '180,00 € vencidos']);
})->group('ui');

it('sums up payments: this month, this year and by method', function () {
    Payment::factory()->create(['amount' => 40000, 'paid_on' => '2026-11-05', 'method' => PaymentMethod::Transfer]);
    Payment::factory()->create(['amount' => 18000, 'paid_on' => '2026-10-02', 'method' => PaymentMethod::MbWay]);
    Payment::factory()->create(['amount' => 99900, 'paid_on' => '2025-12-30']);

    Livewire::test(PaymentsSummary::class)
        ->assertSeeInOrder(['Recebido em', '400,00 €'])
        ->assertSeeInOrder(['Recebido em 2026', '580,00 €', '2 recebimentos'])
        ->assertSeeInOrder(['Como te pagam', 'Transferência', '400,00 €', 'MB Way', '180,00 €']);
})->group('ui');

it('sums up fiscal documents: billed this year, charges without a document and credit notes to apply', function () {
    FiscalDocument::factory()->create(['net_amount' => 40000, 'document_date' => '2026-11-12']);
    $creditNote = FiscalDocument::factory()->create(['net_amount' => 5000, 'type' => FiscalDocumentType::CreditNote, 'document_date' => '2026-10-20']);
    FiscalDocument::factory()->create(['direction' => FiscalDocumentDirection::Received, 'document_date' => '2026-11-10']);
    FiscalDocument::factory()->create(['status' => FiscalDocumentStatus::Voided, 'document_date' => '2026-03-01']);

    $documented = Receivable::factory()->create(['due_on' => '2026-10-01']);
    FiscalDocument::factory()->create(['net_amount' => 0, 'document_date' => '2026-10-01'])->links()->create(['receivable_id' => $documented->id]);
    Receivable::factory()->create(['due_on' => '2026-10-28']);
    Receivable::factory()->create(['due_on' => '2026-12-20']);

    Livewire::test(FiscalDocumentsSummary::class)
        ->assertSeeInOrder(['Faturado em 2026, sem IVA', Money::format(35000)])
        ->assertSeeInOrder(['Cobranças sem documento', '1', 'emitir fatura'])
        ->assertSeeInOrder(['Notas de crédito por aplicar', '1', 'Aplica-as na fatura original'])
        ->assertSeeInOrder(['InvoiceXpress', 'Por ligar']);

    CreditApplication::factory()->create(['credit_note_id' => $creditNote->id]);

    Livewire::test(FiscalDocumentsSummary::class)
        ->assertSeeInOrder(['Notas de crédito por aplicar', '0', 'Tudo aplicado']);
})->group('ui');

it('splits the fiscal documents list into tabs by direction, with the voided ones apart', function () {
    $issued = FiscalDocument::factory()->create(['number' => 'FT 2026/1']);
    $received = FiscalDocument::factory()->create(['number' => 'FT A/77', 'direction' => FiscalDocumentDirection::Received]);
    $voided = FiscalDocument::factory()->create(['number' => 'FT 2026/2', 'status' => FiscalDocumentStatus::Voided]);

    Livewire::test(ListFiscalDocuments::class)
        ->assertCanSeeTableRecords([$issued, $received, $voided])
        ->set('activeTab', 'issued')
        ->assertCanSeeTableRecords([$issued])
        ->assertCanNotSeeTableRecords([$received, $voided])
        ->set('activeTab', 'received')
        ->assertCanSeeTableRecords([$received])
        ->assertCanNotSeeTableRecords([$issued, $voided])
        ->set('activeTab', 'voided')
        ->assertCanSeeTableRecords([$voided])
        ->assertCanNotSeeTableRecords([$issued, $received]);
})->group('ui');

it('shows who this year\'s expenses went to beside the suppliers', function () {
    $cloud = Supplier::factory()->create(['name' => 'Cloud Demo']);
    $shop = Supplier::factory()->create(['name' => 'Loja Demo']);
    Expense::factory()->for($cloud)->create(['net_amount' => 30000, 'vat_rate' => 0, 'spent_on' => '2026-02-25']);
    Expense::factory()->for($shop)->create(['net_amount' => 10000, 'vat_rate' => 0, 'spent_on' => '2026-11-10']);
    Expense::factory()->for($shop)->create(['net_amount' => 99900, 'vat_rate' => 0, 'spent_on' => '2025-11-10']);

    $this->get(SupplierResource::getUrl())->assertOk()->assertSeeLivewire(SuppliersBySpend::class);

    Livewire::test(SuppliersBySpend::class)
        ->assertSeeInOrder(['Onde gastas mais', 'Cloud Demo', '75%', 'Loja Demo', '25%']);
})->group('ui');

it('shows a summary above each of the other lists', function (string $resource, string $widget) {
    $this->get($resource::getUrl())->assertOk()->assertSeeLivewire($widget);
})->with([
    'trabalhos' => [ProjectResource::class, ProjectsSummary::class],
    'avenças' => [RetainerResource::class, RetainersSummary::class],
    'recebimentos' => [PaymentResource::class, PaymentsSummary::class],
    'documentos fiscais' => [FiscalDocumentResource::class, FiscalDocumentsSummary::class],
])->group('ui');

it('shows how long clients take to pay and who owes the most', function () {
    $brisa = Client::factory()->create(['name' => 'Estúdio Brisa']);
    $norte = Client::factory()->create(['name' => 'Oficina Norte']);
    Receivable::factory()->for(Project::factory()->for($brisa), 'billable')->create(['amount' => 40000, 'due_on' => '2026-11-15']);
    Receivable::factory()->for(Project::factory()->for($norte), 'billable')->create(['amount' => 18000, 'due_on' => '2026-10-28']);
    $late = Receivable::factory()->for(Project::factory()->for($norte), 'billable')->create(['amount' => 10000, 'due_on' => '2026-10-01']);
    Payment::factory()->for($late)->create(['amount' => 10000, 'paid_on' => '2026-10-11']);

    Livewire::test(ReceivablesSummary::class)
        ->assertSeeInOrder(['Demoram a pagar, em média', '10 dias', 'Depois do vencimento']);

    Livewire::test(ReceivablesByClient::class)
        ->assertSeeInOrder(['Quem deve', 'Estúdio Brisa', Money::format(40000), 'Oficina Norte', Money::format(18000), 'vencidos'])
        ->assertSee('Ver extrato de um cliente');
})->group('ui');

it('shows how many of this year\'s proposals went ahead', function () {
    Project::factory()->create(['status' => ProjectStatus::Completed, 'quoted_on' => '2026-03-01']);
    Project::factory()->create(['status' => ProjectStatus::InProgress, 'quoted_on' => '2026-09-01']);
    Project::factory()->create(['status' => ProjectStatus::Proposal, 'quoted_on' => '2026-10-29']);
    Project::factory()->create(['status' => ProjectStatus::Completed, 'quoted_on' => '2025-10-29']);

    Livewire::test(ProjectsSummary::class)
        ->assertSeeInOrder(['Propostas aprovadas', '2 de 3'])
        ->assertSeeHtml('67% das propostas aprovadas');
})->group('ui');

it('shows what the active retainers will generate in the next six months', function () {
    Retainer::factory()->create(['starts_on' => '2026-06-01', 'frequency' => Frequency::Monthly, 'net_amount' => 18000, 'vat_rate' => 0, 'is_active' => true]);
    Retainer::factory()->create(['starts_on' => '2026-09-01', 'frequency' => Frequency::Quarterly, 'net_amount' => 30000, 'vat_rate' => 0, 'is_active' => true]);
    Retainer::factory()->create(['starts_on' => '2026-01-01', 'net_amount' => 99900, 'vat_rate' => 0, 'is_active' => false]);

    Livewire::test(RetainersAhead::class)
        ->assertSeeInOrder(['Próximos 6 meses', 'Nov', Money::format(18000), 'Dez', Money::format(48000), 'Jan', Money::format(18000), 'Mar', Money::format(48000)]);
})->group('ui');

it('shows what each supplier is still owed and when the last expense was', function () {
    $shop = Supplier::factory()->create(['name' => 'Loja Demo']);
    $comms = Category::factory()->create(['name' => 'Comunicações']);
    Expense::factory()->for($shop)->unpaid()->create(['net_amount' => 5000, 'vat_rate' => 0.23, 'spent_on' => '2026-11-10', 'category_id' => $comms->id]);
    Expense::factory()->for($shop)->create(['net_amount' => 2000, 'spent_on' => '2026-02-01', 'category_id' => $comms->id]);
    Expense::factory()->for($shop)->create(['net_amount' => 1000, 'spent_on' => '2026-01-01', 'category_id' => Category::factory()->create(['name' => 'Outros'])->id]);

    Livewire::test(ManageSuppliers::class)
        ->assertTableColumnStateSet('usual_category', 'Comunicações', $shop)
        ->assertTableColumnStateSet('unpaid', 6150, $shop)
        ->assertTableColumnFormattedStateSet('last_expense_on', '10/11/2026', $shop);
})->group('ui');
