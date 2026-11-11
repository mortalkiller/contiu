<?php

use App\Enums\PaymentMethod;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Pages\ClientStatementPage;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

pest()->group('ui');

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-03-10'));
    actingAsAdmin();

    $this->client = Client::factory()->create(['name' => 'Cliente Extrato']);
    $project = Project::factory()->for($this->client)->create(['code' => 'OC-9']);
    $first = Receivable::factory()->for($project, 'billable')->create(['description' => '1/2', 'amount' => 60000, 'due_on' => '2026-01-15']);
    Receivable::factory()->for($project, 'billable')->create(['description' => '2/2', 'amount' => 40000, 'due_on' => '2026-04-15']);
    Payment::factory()->for($first)->create(['paid_on' => '2026-01-20', 'amount' => 60000, 'method' => PaymentMethod::Transfer]);
});

it('shows every movement with the running balance', function () {
    Livewire::test(ClientStatementPage::class, ['record' => $this->client->getRouteKey()])
        ->assertSuccessful()
        ->assertSee('OC-9 · 1/2')
        ->assertSee('Recebimento · Transferência')
        ->assertSee('OC-9 · 2/2')
        ->assertSee(Money::format(60000))
        ->assertSee(Money::format(40000))
        ->assertSee('a vencer');
});

it('is reachable at /extrato, from the actions of the client page instead of a sub-navigation', function () {
    $url = ClientResource::getUrl('statement', ['record' => $this->client]);

    $this->get($url)->assertOk();

    $page = Livewire::test(ViewClient::class, ['record' => $this->client->getRouteKey()]);

    $page->assertActionHasUrl('statement', $url);

    expect(ClientResource::getRecordSubNavigation($page->instance()))->toBe([]);
});
