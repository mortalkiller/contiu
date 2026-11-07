<?php

use App\Support\InstalmentPlanner;

it('plans a single payment', function () {
    expect(InstalmentPlanner::single(196500, '2027-01-31'))->toBe([
        ['description' => 'Pagamento único', 'amount' => 196500, 'due_on' => '2027-01-31'],
    ]);
});

it('plans 50% on adjudication and 50% on delivery a month later', function () {
    expect(InstalmentPlanner::halves(190500, '2026-07-09'))->toBe([
        ['description' => '50% adjudicação', 'amount' => 95250, 'due_on' => '2026-07-09'],
        ['description' => '50% entrega', 'amount' => 95250, 'due_on' => '2026-08-09'],
    ]);
});

it('lets the last half absorb the odd cent', function () {
    $rows = InstalmentPlanner::halves(10001, '2026-01-01');

    expect(array_column($rows, 'amount'))->toBe([5000, 5001]);
});

it('splits into equal monthly instalments with the last one absorbing the rounding', function () {
    expect(InstalmentPlanner::equal(100000, 3, '2026-03-31'))->toBe([
        ['description' => 'Prestação 1/3', 'amount' => 33333, 'due_on' => '2026-03-31'],
        ['description' => 'Prestação 2/3', 'amount' => 33333, 'due_on' => '2026-04-30'],
        ['description' => 'Prestação 3/3', 'amount' => 33334, 'due_on' => '2026-05-31'],
    ]);
});

it('plans one instalment per month up to and including the last due date', function () {
    $rows = InstalmentPlanner::monthlyUntil(240000, '2026-03-15', '2026-06-15');

    expect(array_column($rows, 'due_on'))->toBe(['2026-03-15', '2026-04-15', '2026-05-15', '2026-06-15'])
        ->and(array_column($rows, 'amount'))->toBe([60000, 60000, 60000, 60000])
        ->and($rows[3]['description'])->toBe('Prestação 4/4');
});

it('always adds up exactly to the total', function (int $total, int $count) {
    $sum = array_sum(array_column(InstalmentPlanner::equal($total, $count, '2026-01-01'), 'amount'));

    expect($sum)->toBe($total);
})->with([[100000, 3], [9999, 7], [5, 2], [196500, 6]]);
