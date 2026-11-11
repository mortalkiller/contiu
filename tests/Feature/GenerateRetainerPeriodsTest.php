<?php

use App\Actions\GenerateRetainerPeriods;
use App\Enums\Frequency;
use App\Models\Retainer;
use Illuminate\Support\Carbon;

pest()->group('integration');

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-03-10')));

it('creates one receivable per month from the start date up to the horizon', function () {
    $retainer = Retainer::factory()->create(['starts_on' => '2026-01-01', 'due_day' => 5, 'net_amount' => 2000, 'vat_rate' => 0.23]);

    $created = app(GenerateRetainerPeriods::class)->forRetainer($retainer, until: Carbon::parse('2026-04-09'));

    expect($created)->toBe(4)
        ->and($retainer->receivables()->pluck('due_on')->map->toDateString()->all())
        ->toBe(['2026-01-05', '2026-02-05', '2026-03-05', '2026-04-05'])
        ->and($retainer->receivables()->first()->amount)->toBe(2460)
        ->and($retainer->receivables()->first()->description)->toBe('Jan 2026');
});

it('continues after the latest period without filling older gaps', function () {
    $retainer = Retainer::factory()->create(['starts_on' => '2026-01-01', 'due_day' => 1]);
    $retainer->receivables()->create(['description' => 'Fev 2026', 'amount' => 2000, 'due_on' => '2026-02-01']);

    app(GenerateRetainerPeriods::class)->forRetainer($retainer, until: Carbon::parse('2026-04-01'));

    expect($retainer->receivables()->pluck('due_on')->map->toDateString()->all())
        ->toBe(['2026-02-01', '2026-03-01', '2026-04-01']);
});

it('does nothing when run again for the same horizon', function () {
    $retainer = Retainer::factory()->create(['starts_on' => '2026-01-01']);
    $generator = app(GenerateRetainerPeriods::class);

    $generator->forRetainer($retainer, until: Carbon::parse('2026-04-09'));

    expect($generator->forRetainer($retainer, until: Carbon::parse('2026-04-09')))->toBe(0)
        ->and($retainer->receivables()->count())->toBe(4);
});

it('stops at the end date', function () {
    $retainer = Retainer::factory()->create(['starts_on' => '2026-01-01', 'ends_on' => '2026-02-15']);

    app(GenerateRetainerPeriods::class)->forRetainer($retainer, until: Carbon::parse('2026-06-01'));

    expect($retainer->receivables()->count())->toBe(2);
});

it('skips inactive retainers', function () {
    $retainer = Retainer::factory()->create(['starts_on' => '2026-01-01', 'is_active' => false]);

    expect(app(GenerateRetainerPeriods::class)->forRetainer($retainer, until: Carbon::parse('2026-06-01')))->toBe(0);
});

it('starts in the following period when the due day falls before the start date', function () {
    $retainer = Retainer::factory()->create(['starts_on' => '2026-01-15', 'due_day' => 1]);

    app(GenerateRetainerPeriods::class)->forRetainer($retainer, until: Carbon::parse('2026-02-28'));

    expect($retainer->receivables()->pluck('due_on')->map->toDateString()->all())->toBe(['2026-02-01']);
});

it('names yearly and quarterly periods', function () {
    $yearly = Retainer::factory()->create(['starts_on' => '2026-06-01', 'due_day' => 28, 'frequency' => Frequency::Yearly]);
    $quarterly = Retainer::factory()->create(['starts_on' => '2026-01-01', 'due_day' => 1, 'frequency' => Frequency::Quarterly]);

    app(GenerateRetainerPeriods::class)->forRetainer($yearly, until: Carbon::parse('2027-07-01'));
    app(GenerateRetainerPeriods::class)->forRetainer($quarterly, until: Carbon::parse('2026-04-01'));

    expect($yearly->receivables()->pluck('description')->all())->toBe(['Jun 2026 – Mai 2027', 'Jun 2027 – Mai 2028'])
        ->and($quarterly->receivables()->pluck('description')->all())->toBe(['Jan – Mar 2026', 'Abr – Jun 2026']);
});

it('generates every active retainer up to 30 days ahead by default', function () {
    Retainer::factory()->count(2)->create(['starts_on' => '2026-03-01', 'due_day' => 1]);

    expect(app(GenerateRetainerPeriods::class)->forAll())->toBe(4); // Mar and Apr (≤ 9 Apr) for each
});
