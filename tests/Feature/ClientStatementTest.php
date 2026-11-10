<?php

use App\Enums\FiscalDocumentType;
use App\Enums\PaymentMethod;
use App\Enums\ProjectStatus;
use App\Models\Client;
use App\Models\CreditApplication;
use App\Models\FiscalDocument;
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
    $first = Receivable::factory()->for($project, 'billable')->create(['description' => 'Prestação 1/2', 'amount' => 60000, 'due_on' => '2026-01-15']);
    Receivable::factory()->for($project, 'billable')->create(['description' => 'Prestação 2/2', 'amount' => 40000, 'due_on' => '2026-04-15']);
    Payment::factory()->for($first)->create(['paid_on' => '2026-01-15', 'amount' => 60000, 'method' => PaymentMethod::MbWay, 'invoice_number' => 'FR 2026/1']);

    $retainer = Retainer::factory()->for($client)->create(['code' => 'AV-1']);
    Receivable::factory()->for($retainer, 'billable')->create(['description' => 'Fev 2026', 'amount' => 2000, 'due_on' => '2026-02-01']);

    return $client;
}

it('lists charges and payments by date, charges first on the same day, with a running balance', function () {
    $entries = (new ClientStatement(statementClient()))->entries();

    expect($entries->map(fn (array $entry) => [$entry['date']->toDateString(), $entry['description'], $entry['debit'], $entry['credit'], $entry['balance']])->all())
        ->toBe([
            ['2026-01-15', 'OC-1 · Prestação 1/2', 60000, 0, 60000],
            ['2026-01-15', 'Recebimento · MB Way · FR 2026/1', 0, 60000, 0],
            ['2026-02-01', 'AV-1 · Fev 2026', 2000, 0, 2000],
            ['2026-04-15', 'OC-1 · Prestação 2/2', 40000, 0, 42000],
        ]);
});

it('flags charges that are not due yet', function () {
    $entries = (new ClientStatement(statementClient()))->entries();

    expect($entries->pluck('is_future')->all())->toBe([false, false, false, true]);
});

it('exposes totals and the overdue balance', function () {
    $statement = new ClientStatement(statementClient());

    expect($statement->charged())->toBe(102000)
        ->and($statement->received())->toBe(60000)
        ->and($statement->balance())->toBe(42000)
        ->and($statement->overdueBalance())->toBe(2000);
});

it('collapses entries before the start date into an opening balance', function () {
    $statement = new ClientStatement(statementClient(), from: Carbon::parse('2026-02-01'));

    expect($statement->entries()->map(fn (array $entry) => [$entry['description'], $entry['balance']])->all())
        ->toBe([
            ['Saldo anterior', 0],
            ['AV-1 · Fev 2026', 2000],
            ['OC-1 · Prestação 2/2', 42000],
        ])
        ->and($statement->charged())->toBe(42000)
        ->and($statement->received())->toBe(0)
        ->and($statement->balance())->toBe(42000);
});

it('stops at the end date', function () {
    $statement = new ClientStatement(statementClient(), to: Carbon::parse('2026-03-31'));

    expect($statement->entries())->toHaveCount(3)
        ->and($statement->balance())->toBe(2000);
});

it('leaves out cancelled projects', function () {
    $client = statementClient();
    $cancelled = Project::factory()->for($client)->create(['status' => ProjectStatus::Cancelled]);
    Receivable::factory()->for($cancelled, 'billable')->create(['amount' => 99900, 'due_on' => '2026-01-20']);

    expect((new ClientStatement($client))->charged())->toBe(102000);
});

it('reduces the balance for applied credit without counting it as cash and shows a reversal on its date', function () {
    $client = Client::factory()->create();
    $project = Project::factory()->for($client)->create(['code' => 'DEMO-03']);
    $charge = Receivable::factory()->for($project, 'billable')->create(['amount' => 80000, 'due_on' => '2026-02-03']);
    $invoice = FiscalDocument::factory()->create();
    $credit = FiscalDocument::factory()->create(['number' => 'NC DEMO/1', 'type' => FiscalDocumentType::CreditNote]);
    CreditApplication::query()->create([
        'receivable_id' => $charge->id, 'invoice_id' => $invoice->id, 'credit_note_id' => $credit->id,
        'amount' => 15000, 'applied_on' => '2026-02-10', 'reversed_on' => '2026-03-01',
    ]);

    $february = new ClientStatement($client, to: Carbon::parse('2026-02-28'));

    expect($february->balance())->toBe(65000)
        ->and($february->received())->toBe(0)
        ->and($february->entries()->last()['description'])->toContain('NC DEMO/1')
        ->and((new ClientStatement($client))->balance())->toBe(80000);
});
