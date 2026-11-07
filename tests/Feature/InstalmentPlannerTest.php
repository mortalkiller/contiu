<?php

use App\Support\InstalmentPlanner;

it('plans a single payment', function () {
    expect(InstalmentPlanner::single(1965, '2027-01-31'))->toBe([
        ['description' => 'Pagamento único', 'amount' => 1965.0, 'due_on' => '2027-01-31'],
    ]);
});

it('plans 50% on adjudication and 50% on delivery a month later', function () {
    expect(InstalmentPlanner::halves(1905, '2026-07-09'))->toBe([
        ['description' => '50% adjudicação', 'amount' => 952.5, 'due_on' => '2026-07-09'],
        ['description' => '50% entrega', 'amount' => 952.5, 'due_on' => '2026-08-09'],
    ]);
});

it('lets the last half absorb the odd cent', function () {
    $rows = InstalmentPlanner::halves(100.01, '2026-01-01');

    expect(array_column($rows, 'amount'))->toBe([50.0, 50.01]);
});

it('splits into equal monthly instalments with the last one absorbing the rounding', function () {
    expect(InstalmentPlanner::equal(1000, 3, '2026-03-31'))->toBe([
        ['description' => 'Prestação 1/3', 'amount' => 333.33, 'due_on' => '2026-03-31'],
        ['description' => 'Prestação 2/3', 'amount' => 333.33, 'due_on' => '2026-04-30'],
        ['description' => 'Prestação 3/3', 'amount' => 333.34, 'due_on' => '2026-05-31'],
    ]);
});

it('plans one instalment per month up to and including the last due date', function () {
    $rows = InstalmentPlanner::monthlyUntil(2400, '2026-03-15', '2026-06-15');

    expect(array_column($rows, 'due_on'))->toBe(['2026-03-15', '2026-04-15', '2026-05-15', '2026-06-15'])
        ->and(array_column($rows, 'amount'))->toBe([600.0, 600.0, 600.0, 600.0])
        ->and($rows[3]['description'])->toBe('Prestação 4/4');
});

it('always adds up exactly to the total', function (float $total, int $count) {
    $sum = array_sum(array_column(InstalmentPlanner::equal($total, $count, '2026-01-01'), 'amount'));

    expect(round($sum, 2))->toBe(round($total, 2));
})->with([[1000, 3], [99.99, 7], [0.05, 2], [1965, 6]]);
