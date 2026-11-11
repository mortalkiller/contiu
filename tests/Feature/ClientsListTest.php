<?php

use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Widgets\ClientsSummary;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-10'));
    actingAsAdmin();

    $this->atlantico = Client::factory()->create(['name' => 'Estúdio Atlântico']);
    $paid = Receivable::factory()->for(Project::factory()->for($this->atlantico), 'billable')->create(['amount' => 325000, 'due_on' => '2026-03-01']);
    Payment::factory()->for($paid)->create(['amount' => 325000, 'paid_on' => '2026-03-01']);
    Receivable::factory()->for(Project::factory()->for($this->atlantico), 'billable')->create(['amount' => 15000, 'due_on' => '2027-02-23']);

    $this->norte = Client::factory()->create(['name' => 'Oficina Norte']);
    Receivable::factory()->for(Project::factory()->for($this->norte), 'billable')->create(['amount' => 18000, 'due_on' => '2026-10-30']);

    $this->quiet = Client::factory()->create(['name' => 'Cliente Antigo']);
});

it('says who you work for and what is awaiting payment, with no breadcrumbs', function () {
    Livewire::test(ListClients::class)
        ->assertSee('Para quem trabalhas. 3 clientes · '.Money::format(33000).' por receber.', escape: false)
        ->assertDontSeeHtml('fi-breadcrumbs')
        ->assertActionExists('export')
        ->assertActionExists('create');
})->group('ui');

it('sums up the clients: contracted and received this year, awaiting payment and past due', function () {
    Livewire::test(ClientsSummary::class)
        ->assertSeeInOrder(['Contratado em 2026', Money::format(343000), '2 clientes ativos'], false)
        ->assertSeeInOrder(['Recebido', Money::format(325000)], false)
        ->assertSeeInOrder(['Por receber', Money::format(33000), 'De 2 clientes'], false)
        ->assertSeeInOrder(['Vencido', Money::format(18000), 'Oficina Norte', 'há 11 dias'], false);
})->group('ui');

it('lists each client with what came in as a bar and totals at the foot', function () {
    Livewire::test(ListClients::class)
        ->loadTable()
        ->assertTableColumnStateSet('received_share', 325000 / 340000 * 100, $this->atlantico)
        ->assertTableColumnStateSet('received_share', null, $this->quiet)
        ->assertTableColumnSummarySet('contracted_amount', 'sum', 358000)
        ->assertTableColumnSummarySet('outstanding_amount', 'sum', 33000)
        ->assertTableColumnSummarySet('overdue_amount', 'sum', 18000);
})->group('ui');

it('filters the clients that owe, the late ones and the ones with nothing this year', function () {
    Livewire::test(ListClients::class)
        ->loadTable()
        ->set('activeTab', 'owing')
        ->assertCanSeeTableRecords([$this->atlantico, $this->norte])
        ->assertCanNotSeeTableRecords([$this->quiet])
        ->set('activeTab', 'late')
        ->assertCanSeeTableRecords([$this->norte])
        ->assertCanNotSeeTableRecords([$this->atlantico, $this->quiet])
        ->set('activeTab', 'inactive')
        ->assertCanSeeTableRecords([$this->quiet])
        ->assertCanNotSeeTableRecords([$this->atlantico, $this->norte]);
})->group('ui');
