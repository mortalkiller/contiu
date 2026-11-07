<?php

use App\Enums\PaymentMethod;
use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\Retainer;
use App\Support\ClientStatement;
use Illuminate\Support\Carbon;

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-03-10')));

function statementClient(): Client
{
    $client = Client::factory()->create();
    $project = Project::factory()->for($client)->create(['code' => 'OC-1']);
    $first = Receivable::factory()->for($project, 'billable')->create(['description' => 'Prestação 1/2', 'amount' => 600, 'due_on' => '2026-01-15']);
    Receivable::factory()->for($project, 'billable')->create(['description' => 'Prestação 2/2', 'amount' => 400, 'due_on' => '2026-04-15']);
    Payment::factory()->for($first)->create(['paid_on' => '2026-01-15', 'amount' => 600, 'method' => PaymentMethod::MbWay, 'invoice_number' => 'FR 2026/1']);

    $retainer = Retainer::factory()->for($client)->create(['code' => 'AV-1']);
    Receivable::factory()->for($retainer, 'billable')->create(['description' => 'Fev 2026', 'amount' => 20, 'due_on' => '2026-02-01']);

    return $client;
}

it('lists charges and payments by date, charges first on the same day, with a running balance', function () {
    $entries = (new ClientStatement(statementClient()))->entries();

    expect($entries->map(fn (array $entry) => [$entry['date']->toDateString(), $entry['description'], $entry['debit'], $entry['credit'], $entry['balance']])->all())
        ->toBe([
            ['2026-01-15', 'OC-1 · Prestação 1/2', 600.0, 0.0, 600.0],
            ['2026-01-15', 'Recebimento · MB Way · FR 2026/1', 0.0, 600.0, 0.0],
            ['2026-02-01', 'AV-1 · Fev 2026', 20.0, 0.0, 20.0],
            ['2026-04-15', 'OC-1 · Prestação 2/2', 400.0, 0.0, 420.0],
        ]);
});

it('flags charges that are not due yet', function () {
    $entries = (new ClientStatement(statementClient()))->entries();

    expect($entries->pluck('is_future')->all())->toBe([false, false, false, true]);
});

it('exposes totals and the overdue balance', function () {
    $statement = new ClientStatement(statementClient());

    expect($statement->charged())->toBe(1020.0)
        ->and($statement->received())->toBe(600.0)
        ->and($statement->balance())->toBe(420.0)
        ->and($statement->overdueBalance())->toBe(20.0);
});

it('collapses entries before the start date into an opening balance', function () {
    $statement = new ClientStatement(statementClient(), from: Carbon::parse('2026-02-01'));

    expect($statement->entries()->map(fn (array $entry) => [$entry['description'], $entry['balance']])->all())
        ->toBe([
            ['Saldo anterior', 0.0],
            ['AV-1 · Fev 2026', 20.0],
            ['OC-1 · Prestação 2/2', 420.0],
        ])
        ->and($statement->charged())->toBe(420.0)
        ->and($statement->received())->toBe(0.0)
        ->and($statement->balance())->toBe(420.0);
});

it('stops at the end date', function () {
    $statement = new ClientStatement(statementClient(), to: Carbon::parse('2026-03-31'));

    expect($statement->entries())->toHaveCount(3)
        ->and($statement->balance())->toBe(20.0);
});

it('leaves out cancelled projects', function () {
    $client = statementClient();
    $cancelled = Project::factory()->for($client)->create(['status' => ProjectStatus::Cancelled]);
    Receivable::factory()->for($cancelled, 'billable')->create(['amount' => 999, 'due_on' => '2026-01-20']);

    expect((new ClientStatement($client))->charged())->toBe(1020.0);
});
