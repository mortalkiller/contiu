<?php

namespace App\Support;

use App\Enums\ExpenseKind;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\Retainer;
use Illuminate\Database\Eloquent\Model;

/**
 * Margins per business line and per client for a period.
 *
 * - Faturado: what was contracted in the period (same rule as the dashboard: projects by quote date,
 *   retainer periods by due date, cancelled projects excluded).
 * - Recebido: payments received in the period (cash view, whatever the contract date).
 * - Custos diretos: direct expenses of the period, attributed to their project/retainer.
 * - Margem = Faturado − Custos diretos. Overhead (estrutura) is only subtracted in the totals.
 */
final class MarginReport
{
    public const NO_LINE = 'Sem linha';

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $cache = [];

    public function __construct(public readonly Period $period) {}

    /** @return array<string, array{label: string, invoiced: float, received: float, directCosts: float, margin: float, marginPercent: ?float}> */
    public function byBusinessLine(): array
    {
        return $this->cache['line'] ??= $this->group(fn (?Model $billable) => filled($billable?->business_line) ? $billable->business_line : self::NO_LINE);
    }

    /** @return array<string, array{label: string, invoiced: float, received: float, directCosts: float, margin: float, marginPercent: ?float}> */
    public function byClient(): array
    {
        return $this->cache['client'] ??= $this->group(fn (?Model $billable) => $billable?->client?->name ?? '—');
    }

    /** @return array{invoiced: float, received: float, directCosts: float, margin: float, marginPercent: ?float, overhead: float, result: float} */
    public function totals(): array
    {
        $rows = $this->byBusinessLine();
        $invoiced = round(array_sum(array_column($rows, 'invoiced')), 2);
        $directCosts = round(array_sum(array_column($rows, 'directCosts')), 2);
        $margin = round($invoiced - $directCosts, 2);
        $overhead = $this->overhead();

        return [
            'invoiced' => $invoiced,
            'received' => round(array_sum(array_column($rows, 'received')), 2),
            'directCosts' => $directCosts,
            'margin' => $margin,
            'marginPercent' => $this->percent($margin, $invoiced),
            'overhead' => $overhead,
            'result' => round($margin - $overhead, 2),
        ];
    }

    public function overhead(): float
    {
        return round(Expense::query()
            ->where('kind', ExpenseKind::Overhead)
            ->whereBetween('spent_on', $this->period->range())
            ->get()
            ->sum(fn (Expense $expense) => $expense->total), 2);
    }

    /** @return array<string, array<string, mixed>> */
    private function group(callable $key): array
    {
        $rows = [];

        $add = function (?Model $billable, string $field, float $amount) use (&$rows, $key): void {
            $label = $key($billable);
            $rows[$label] ??= ['label' => $label, 'invoiced' => 0.0, 'received' => 0.0, 'directCosts' => 0.0];
            $rows[$label][$field] += $amount;
        };

        (new PeriodSummary($this->period))->contractedReceivablesQuery()->with('billable.client')->get()
            ->each(fn (Receivable $receivable) => $add($receivable->billable, 'invoiced', (float) $receivable->amount));

        Payment::query()->with('receivable.billable.client')
            ->whereBetween('paid_on', $this->period->range())
            ->get()
            ->each(fn (Payment $payment) => $add($payment->receivable?->billable, 'received', (float) $payment->amount));

        Expense::query()->with(['project.client', 'retainer.client'])
            ->where('kind', ExpenseKind::Direct)
            ->whereBetween('spent_on', $this->period->range())
            ->get()
            ->each(fn (Expense $expense) => $add($expense->project ?? $expense->retainer, 'directCosts', $expense->total));

        ksort($rows, SORT_NATURAL | SORT_FLAG_CASE);

        if (isset($rows[self::NO_LINE])) {
            $rows = [...array_diff_key($rows, [self::NO_LINE => true]), self::NO_LINE => $rows[self::NO_LINE]];
        }

        return array_map(function (array $row): array {
            $invoiced = round($row['invoiced'], 2);
            $directCosts = round($row['directCosts'], 2);
            $margin = round($invoiced - $directCosts, 2);

            return [
                'label' => $row['label'],
                'invoiced' => $invoiced,
                'received' => round($row['received'], 2),
                'directCosts' => $directCosts,
                'margin' => $margin,
                'marginPercent' => $this->percent($margin, $invoiced),
            ];
        }, $rows);
    }

    private function percent(float $margin, float $invoiced): ?float
    {
        return $invoiced > 0 ? round($margin / $invoiced * 100, 1) : null;
    }
}
