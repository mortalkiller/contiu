<?php

use App\Enums\PaymentMethod;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Resources\Clients\RelationManagers\ClientExpensesRelationManager;
use App\Filament\Resources\Clients\RelationManagers\ClientPaymentsRelationManager;
use App\Filament\Resources\Clients\RelationManagers\ClientReceivablesRelationManager;
use App\Filament\Resources\Clients\RelationManagers\ProjectsRelationManager;
use App\Filament\Resources\Clients\Schemas\ClientHeader;
use App\Filament\Resources\Clients\Widgets\ClientSummary;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Support\Money;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-03-10'));
    actingAsAdmin();

    $this->client = Client::factory()->create(['name' => 'Estúdio Atlântico', 'vat_number' => '509999990', 'email' => 'geral@estudio.example']);
    $this->project = Project::factory()->for($this->client)->create(['code' => 'OC-9']);
    $paid = Receivable::factory()->for($this->project, 'billable')->create(['description' => '1/2', 'amount' => 60000, 'due_on' => '2026-01-15']);
    Receivable::factory()->for($this->project, 'billable')->create(['description' => '2/2', 'amount' => 40000, 'due_on' => '2026-04-15']);
    Payment::factory()->for($paid)->create(['paid_on' => '2026-01-20', 'amount' => 60000, 'method' => PaymentMethod::Transfer]);

    // Another client's records must stay out of this client's tabs.
    $other = Project::factory()->for(Client::factory()->create(['name' => 'Estúdio Brisa']))->create(['code' => 'OC-77']);
    Payment::factory()->for(Receivable::factory()->for($other, 'billable')->create(['amount' => 5000, 'due_on' => '2026-02-01']))->create(['paid_on' => '2026-02-02', 'amount' => 5000]);
    Receivable::factory()->for($other, 'billable')->create(['amount' => 7000, 'due_on' => '2026-05-01']);
});

it('sums the client up under its name: contracted, received, still to receive and past due', function () {
    Livewire::test(ClientSummary::class, ['record' => $this->client])
        ->assertSeeInOrder([__('Contracted'), Money::format(100000), trans_choice(':count project|:count projects', 1, ['count' => 1])])
        ->assertSeeInOrder([__('Received'), Money::format(60000)])
        ->assertSeeHtml('width: 60%')
        ->assertSeeInOrder([__('Still to receive'), Money::format(40000), __('Due on :date', ['date' => '15/04/2026'])])
        ->assertSeeInOrder([__('Past due'), Money::format(0), __('Nothing overdue')]);
})->group('ui');

it('says how many amounts are late', function () {
    $this->travelTo(Carbon::parse('2026-05-10'));

    Livewire::test(ClientSummary::class, ['record' => $this->client])
        ->assertSeeInOrder([__('Still to receive'), Money::format(40000), __('Already due')])
        ->assertSeeInOrder([__('Past due'), Money::format(40000), trans_choice(':count amount overdue|:count amounts overdue', 1, ['count' => 1])]);
})->group('ui');

it('keeps the main actions at hand and the rest under more actions', function () {
    Livewire::test(ViewClient::class, ['record' => $this->client->getRouteKey()])
        ->assertActionVisible('recordPayment')
        ->assertActionVisible('edit')
        ->assertActionVisible('sendStatement')
        ->assertActionVisible('newProject')
        ->assertActionVisible('statement')
        ->assertActionHidden('addClientEmail');
})->group('ui');

it('shows the client records and its own details in one row of tabs, with their counts', function () {
    $page = Livewire::test(ViewClient::class, ['record' => $this->client->getRouteKey()]);

    $page->assertSeeInOrder([__('Projects'), __('To receive'), __('Payments received'), __('Expenses'), __('Fiscal details')]);

    expect(ProjectsRelationManager::getBadge($this->client, ViewClient::class))->toBe('1')
        ->and(ClientReceivablesRelationManager::getBadge($this->client, ViewClient::class))->toBe('1')
        ->and(ClientPaymentsRelationManager::getBadge($this->client, ViewClient::class))->toBe('1')
        ->and($page->instance()->hasCombinedRelationManagerTabsWithContent())->toBeTrue();
})->group('ui');

it('lists only what this client still owes, what it paid and what was spent on its projects', function () {
    Expense::factory()->create(['project_id' => $this->project->id, 'description' => 'Fotografia do projeto', 'spent_on' => '2026-02-01', 'net_amount' => 3000]);
    Expense::factory()->create(['description' => 'Renda do escritório', 'spent_on' => '2026-02-01', 'net_amount' => 9000]);

    $tab = fn (string $manager) => Livewire::test($manager, ['ownerRecord' => $this->client, 'pageClass' => ViewClient::class])->loadTable();

    expect($this->client->receivablesQuery()->open()->pluck('description')->all())->toBe(['2/2'])
        ->and($this->client->paymentsQuery()->count())->toBe(1)
        ->and($this->client->expensesQuery()->pluck('description')->all())->toBe(['Fotografia do projeto']);

    $tab(ClientReceivablesRelationManager::class)->assertSee(Money::format(40000))->assertDontSee(Money::format(7000));
    $tab(ClientPaymentsRelationManager::class)->assertSee(Money::format(60000))->assertDontSee(Money::format(5000));
    $tab(ClientExpensesRelationManager::class)->assertSee('Fotografia do projeto')->assertDontSee('Renda do escritório');
})->group('ui');

it('says who the client is right under its name, with the tax number always there', function () {
    $this->client->forceFill(['created_at' => '2026-03-02'])->save();
    $since = __('Client since :date', ['date' => Carbon::parse('2026-03-02')->locale(app()->getLocale())->translatedFormat('F Y')]);

    expect(ClientHeader::identityLine($this->client))
        ->toBe(__('Tax ID :number', ['number' => '<span class="font-mono text-[0.8125rem]">509999990</span>']).' · geral@estudio.example · '.$since);

    $this->client->forceFill(['vat_number' => null, 'email' => null])->save();

    expect(ClientHeader::identityLine($this->client))->toBe(__('Tax ID :number', ['number' => '—']).' · '.$since);

    $this->client->forceFill(['email' => '<b>x</b>@example.com'])->save();

    expect(ClientHeader::identityLine($this->client))->toContain('&lt;b&gt;x&lt;/b&gt;@example.com');
})->group('integration');

it('leaves the client column out of the client tabs', function () {
    Livewire::test(ProjectsRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => ViewClient::class])
        ->loadTable()
        ->assertTableColumnDoesNotExist('client.name');

    Livewire::test(ClientReceivablesRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => ViewClient::class])
        ->loadTable()
        ->assertTableColumnHidden('client');
})->group('ui');

it('lists the client projects as in the mock, with the totals and a new project for this client', function () {
    Livewire::test(ProjectsRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => ViewClient::class])
        ->loadTable()
        ->assertTableColumnExists('title')
        ->assertTableColumnExists('received_amount')
        ->assertTableColumnDoesNotExist('business_line')
        ->assertTableColumnDoesNotExist('quoted_on')
        ->assertDontSee(__('Export to Excel'))
        ->assertActionHasUrl(TestAction::make('newProject')->table(), ProjectResource::getUrl('create', ['client' => $this->client->getKey()]));

    // The foot of the table sums the three amounts (Filament's money format spaces differ from Money::format()).
    $html = preg_replace('/[\x{00A0}\x{202F}]/u', ' ', Livewire::test(ProjectsRelationManager::class, ['ownerRecord' => $this->client, 'pageClass' => ViewClient::class])->loadTable()->html());

    expect($html)->toContain('fi-ta-summary-row')
        ->and(substr_count($html, number_format($this->project->total / 100, 2, ',', ' ')))->toBeGreaterThanOrEqual(2)
        ->and($html)->toContain('600,00')
        ->and($html)->toContain('400,00');
})->group('ui');
