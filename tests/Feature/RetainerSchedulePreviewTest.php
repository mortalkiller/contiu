<?php

use App\Actions\GenerateRetainerPeriods;
use App\Enums\Frequency;
use App\Models\Retainer;
use Illuminate\Support\Facades\Process;

pest()->group('ui');

/*
 * While a retainer is written, its summary (per month, per year, first charge) is worked out in
 * the browser, so typing never asks the server. Both sides are held to the same cases: here the
 * server's, and in tests/JavaScript the browser's.
 */

it('works out the retainer summary as the shared cases say', function (int $total, string $frequency, string $startsOn, int $dueDay, array $expected) {
    $retainer = Retainer::factory()->make([
        'frequency' => $frequency,
        'starts_on' => $startsOn,
        'ends_on' => null,
        'due_day' => $dueDay,
        'is_active' => true,
    ]);

    expect([
        'per_month' => Frequency::from($frequency)->perMonth($total),
        'per_year' => Frequency::from($frequency)->perYear($total),
        'first_due' => app(GenerateRetainerPeriods::class)->upcoming($retainer, 1)[0]['due_on']->toDateString(),
    ])->toBe($expected);
})->with(fn (): array => collect(json_decode(file_get_contents(__DIR__.'/../JavaScript/retainer-schedule.cases.json'), true, flags: JSON_THROW_ON_ERROR))
    ->mapWithKeys(fn (array $case): array => [$case['name'] => [$case['total'], $case['frequency'], $case['starts_on'], $case['due_day'], $case['expected']]])
    ->all());

it('keeps the browser\'s retainer summary equal to the server\'s', function () {
    $result = Process::path(base_path())->run(['node', '--test', 'tests/JavaScript/retainer-schedule.test.js']);

    expect($result->successful())->toBeTrue($result->output().$result->errorOutput());
})->skip(fn (): bool => Process::run(['node', '--version'])->failed(), 'Node is not installed.');
