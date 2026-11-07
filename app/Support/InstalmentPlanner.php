<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Instalment plan presets for a project. Rows match the receivables repeater
 * (description, amount, due_on) and always add up exactly to the total: amounts are split
 * in cents and the last instalment absorbs the remainder.
 */
final class InstalmentPlanner
{
    /** @return array<int, array{description: string, amount: float, due_on: string}> */
    public static function single(float $total, CarbonInterface|string $dueOn): array
    {
        return [self::row('Pagamento único', round($total, 2), Carbon::parse($dueOn))];
    }

    /** @return array<int, array{description: string, amount: float, due_on: string}> */
    public static function halves(float $total, CarbonInterface|string $firstDue): array
    {
        [$first, $second] = self::split($total, 2);
        $start = Carbon::parse($firstDue);

        return [
            self::row('50% adjudicação', $first, $start),
            self::row('50% entrega', $second, $start->copy()->addMonthsNoOverflow(1)),
        ];
    }

    /** @return array<int, array{description: string, amount: float, due_on: string}> */
    public static function equal(float $total, int $count, CarbonInterface|string $firstDue): array
    {
        $count = max(1, $count);
        $start = Carbon::parse($firstDue);

        return array_map(
            fn (float $amount, int $index) => self::row('Prestação '.($index + 1).'/'.$count, $amount, $start->copy()->addMonthsNoOverflow($index)),
            self::split($total, $count),
            array_keys(array_fill(0, $count, null)),
        );
    }

    /** @return array<int, array{description: string, amount: float, due_on: string}> */
    public static function monthlyUntil(float $total, CarbonInterface|string $firstDue, CarbonInterface|string $lastDue): array
    {
        $start = Carbon::parse($firstDue);
        $end = Carbon::parse($lastDue);
        $count = 1;

        while ($start->copy()->addMonthsNoOverflow($count)->lte($end)) {
            $count++;
        }

        return self::equal($total, $count, $start);
    }

    /** @return array<int, float> */
    private static function split(float $total, int $count): array
    {
        $cents = (int) round($total * 100);
        $base = intdiv($cents, $count);
        $amounts = array_fill(0, $count, $base);
        $amounts[$count - 1] = $cents - $base * ($count - 1);

        return array_map(fn (int $value) => round($value / 100, 2), $amounts);
    }

    /** @return array{description: string, amount: float, due_on: string} */
    private static function row(string $description, float $amount, CarbonInterface $dueOn): array
    {
        return ['description' => $description, 'amount' => $amount, 'due_on' => $dueOn->toDateString()];
    }
}
