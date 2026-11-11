<?php

use App\Enums\ExpenseKind;
use App\Filament\Pages\ClientRevenueQuality as ClientRevenuePage;
use App\Models\Account;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\Retainer;
use App\Support\ClientRevenueQuality;
use App\Support\CurrentAccount;
use App\Support\Money;
use App\Support\Period;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

pest()->group('ui');

it('separates clients with the same name and uses the actual allocation and settlement date', function () {
    $this->travelTo(Carbon::parse('2026-11-15'));
    actingAsAdmin();
    [$alpha, $beta] = Client::factory()->count(2)->create(['name' => 'Estúdio']);
    $firstProject = Project::factory()->for($alpha)->create(['quoted_on' => '2026-10-01']);
    $secondProject = Project::factory()->for($beta)->create(['quoted_on' => '2026-10-01']);
    $first = Receivable::factory()->for($firstProject, 'billable')->create(['amount' => 60000, 'due_on' => '2026-10-07']);
    $second = Receivable::factory()->for($secondProject, 'billable')->create(['amount' => 70000, 'due_on' => '2026-10-10']);
    Payment::factory()->for($first)->create(['amount' => 20000, 'paid_on' => '2026-10-07']);
    Payment::factory()->for($second)->create(['amount' => 20000, 'paid_on' => '2026-10-07']);
    Payment::factory()->for($first)->create(['amount' => 40000, 'paid_on' => '2026-10-10']);
    Expense::factory()->create([
        'kind' => ExpenseKind::Direct, 'project_id' => $firstProject->id, 'spent_on' => '2026-10-02',
        'net_amount' => 5000, 'vat_rate' => 0,
    ]);

    $quality = new ClientRevenueQuality(Period::of(2026));

    expect($quality->rows()[$alpha->id])->toMatchArray(['received' => 60000, 'margin' => 55000, 'share' => 75.0, 'medianDelay' => 3])
        ->and($quality->rows()[$beta->id])->toMatchArray(['received' => 20000, 'share' => 25.0, 'medianDelay' => null])
        ->and($quality->scenario($beta->id))->toBe(['inflows' => 50000, 'clientInflows' => 50000, 'withoutClient' => 0]);

    Livewire::test(ClientRevenuePage::class)
        ->set('clientId', $beta->id)
        ->assertSee('Entradas sem este cliente')
        ->assertSee('Estúdio');
});

it('shifts only the selected client’s outstanding and projected inflows by calendar days', function () {
    $this->travelTo(Carbon::parse('2026-11-15'));
    actingAsAdmin();
    [$client, $other] = Client::factory()->count(2)->create(['name' => 'Estúdio']);
    $project = Project::factory()->for($client)->create();
    $partial = Receivable::factory()->for($project, 'billable')->create(['amount' => 10000, 'due_on' => '2026-11-25']);
    Payment::factory()->for($partial)->create(['amount' => 4000]);
    Receivable::factory()->for($project, 'billable')->create(['amount' => 1000, 'due_on' => '2026-10-20']);
    Receivable::factory()->for($project, 'billable')->create(['amount' => 7000, 'due_on' => '2027-01-25']);
    Receivable::factory()->for(Project::factory()->for($other), 'billable')->create(['amount' => 3000, 'due_on' => '2027-01-25']);
    $retainer = Retainer::factory()->for($client)->create(['starts_on' => '2026-12-22', 'due_day' => 22, 'net_amount' => 2000]);
    $generated = $retainer->receivables()->create(['description' => 'Dezembro', 'amount' => 2000, 'due_on' => '2026-12-22']);
    CurrentAccount::run(Account::factory()->create(), function (): void {
        Receivable::factory()->create(['amount' => 9900, 'due_on' => '2026-12-01']);
    });

    $report = new ClientRevenueQuality(Period::of(2026));
    $scenario = $report->scenario($client->id, 10);

    expect($scenario)->toMatchArray([
        'inflows' => 21000, 'clientInflows' => 18000, 'withoutClient' => 3000,
        'withDelay' => 12000, 'beyondHorizon' => 9000,
    ]);
    expect(array_column($scenario['months'], 'inflows'))->toBe([7000, 2000, 12000])
        ->and(array_column($scenario['months'], 'withDelay'))->toBe([1000, 6000, 5000]);
    expect($report->scenario($client->id, 0))->toMatchArray(['withDelay' => 21000, 'beyondHorizon' => 0]);
    expect($partial->fresh()->due_on->toDateString())->toBe('2026-11-25')
        ->and($generated->fresh()->due_on->toDateString())->toBe('2026-12-22');
    expect($retainer->receivables()->count())->toBe(1);

    Livewire::test(ClientRevenuePage::class)
        ->set('clientId', $client->id)
        ->set('delayDays', '10')
        ->assertSee('Entradas sem este cliente')
        ->assertSee('Entradas com atraso')
        ->assertSee(Money::format(12000))
        ->assertSee(Money::format(9000))
        ->set('delayDays', '-1')
        ->assertSee('Indica um número inteiro de dias entre 0 e 365.')
        ->assertDontSee('Entradas com atraso')
        ->assertSee('Entradas sem este cliente');
});

it('warns when half or more of what came in is from one client, and draws the share as a bar', function () {
    $this->travelTo(Carbon::parse('2026-11-15'));
    actingAsAdmin();
    $big = Client::factory()->create(['name' => 'Estúdio Atlântico']);
    $small = Client::factory()->create(['name' => 'Estúdio Brisa']);
    $bigReceivable = Receivable::factory()->for(Project::factory()->for($big)->create(['quoted_on' => '2026-10-01']), 'billable')->create(['amount' => 90000, 'due_on' => '2026-10-07']);
    $smallReceivable = Receivable::factory()->for(Project::factory()->for($small)->create(['quoted_on' => '2026-10-01']), 'billable')->create(['amount' => 10000, 'due_on' => '2026-10-07']);
    Payment::factory()->for($bigReceivable)->create(['amount' => 90000, 'paid_on' => '2026-10-07']);
    Payment::factory()->for($smallReceivable)->create(['amount' => 10000, 'paid_on' => '2026-10-07']);

    Livewire::test(ClientRevenuePage::class)
        ->assertSee('Estúdio Atlântico trouxe 90% do que recebeste')
        ->assertSeeHtml('fap-progress-with-value')
        ->assertSeeHtml('data-color="warning"');
});

it('stays quiet about dependence when no client reaches half', function () {
    $this->travelTo(Carbon::parse('2026-11-15'));
    actingAsAdmin();

    foreach (['Um', 'Dois', 'Três'] as $name) {
        $receivable = Receivable::factory()->for(Project::factory()->for(Client::factory()->create(['name' => $name]))->create(['quoted_on' => '2026-10-01']), 'billable')->create(['amount' => 10000, 'due_on' => '2026-10-07']);
        Payment::factory()->for($receivable)->create(['amount' => 10000, 'paid_on' => '2026-10-07']);
    }

    Livewire::test(ClientRevenuePage::class)->assertDontSee('do que recebeste');
});

it('loads settlement delays once per page render', function () {
    actingAsAdmin();

    DB::enableQueryLog();
    DB::flushQueryLog();

    try {
        Livewire::test(ClientRevenuePage::class)->assertSee('Receitas por cliente');

        $delayQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'from "receivables"')
            && str_contains($query['query'], '"due_on" between'));

        expect($delayQueries->countBy('query')->max())->toBe(1);
    } finally {
        DB::disableQueryLog();
    }
})->group('performance');
