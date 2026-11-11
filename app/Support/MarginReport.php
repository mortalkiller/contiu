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

    /** @return array<string, array{label: string, invoiced: int, received: int, directCosts: int, margin: int, marginPercent: ?float}> (cents) */
    public function byBusinessLine(): array
    {
        return $this->cache['line'] ??= $this->group(
            fn (?Model $billable) => filled($billable?->business_line) ? $billable->business_line : self::NO_LINE,
            fn (?Model $billable) => filled($billable?->business_line) ? $billable->business_line : __('No business line'),
        );
    }

    /** @return array<string, array{label: string, invoiced: int, received: int, directCosts: int, margin: int, marginPercent: ?float}> (cents) */
    public function byClient(): array
    {
        return $this->cache['client'] ??= $this->group(fn (?Model $billable) => $billable?->client?->name ?? '—');
    }

    /** @return array<int, array{label: string, invoiced: int, received: int, directCosts: int, margin: int, marginPercent: ?float}> */
    public function byClientId(): array
    {
        return $this->cache['clientId'] ??= $this->group(
            fn (?Model $billable): int => $billable?->client?->id ?? 0,
            fn (?Model $billable): string => $billable?->client?->name ?? '—',
        );
    }

    /** @return array{invoiced: int, received: int, directCosts: int, margin: int, marginPercent: ?float, overhead: int, result: int} (cents) */
    public function totals(): array
    {
        $rows = $this->byBusinessLine();
        $invoiced = (int) array_sum(array_column($rows, 'invoiced'));
        $directCosts = (int) array_sum(array_column($rows, 'directCosts'));
        $margin = $invoiced - $directCosts;
        $overhead = $this->overhead();

        return [
            'invoiced' => $invoiced,
            'received' => (int) array_sum(array_column($rows, 'received')),
            'directCosts' => $directCosts,
            'margin' => $margin,
            'marginPercent' => $this->percent($margin, $invoiced),
            'overhead' => $overhead,
            'result' => $margin - $overhead,
        ];
    }

    public function overhead(): int
    {
        return (int) Expense::query()
            ->where('kind', ExpenseKind::Overhead)
            ->whereBetween('spent_on', $this->period->range())
            ->get()
            ->sum(fn (Expense $expense) => $expense->total);
    }

    /** @return array<array-key, array<string, mixed>> */
    private function group(callable $key, ?callable $labelFor = null): array
    {
        $rows = [];

        $add = function (?Model $billable, string $field, int $amount) use (&$rows, $key, $labelFor): void {
            $group = $key($billable);
            $rows[$group] ??= ['label' => $labelFor ? $labelFor($billable) : $group, 'invoiced' => 0, 'received' => 0, 'directCosts' => 0];
            $rows[$group][$field] += $amount;
        };

        (new PeriodSummary($this->period))->contractedReceivablesQuery()->without('payments')->with('billable.client')->get()
            ->each(fn (Receivable $receivable) => $add($receivable->billable, 'invoiced', $receivable->amount));

        Payment::query()->with('allocations.receivable.billable.client')
            ->whereBetween('paid_on', $this->period->range())
            ->get()
            ->flatMap->allocations
            ->each(fn ($allocation) => $add($allocation->receivable->billable, 'received', $allocation->amount));

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
            $invoiced = $row['invoiced'];
            $directCosts = $row['directCosts'];
            $margin = $invoiced - $directCosts;

            return [
                'label' => $row['label'],
                'invoiced' => $invoiced,
                'received' => $row['received'],
                'directCosts' => $directCosts,
                'margin' => $margin,
                'marginPercent' => $this->percent($margin, $invoiced),
            ];
        }, $rows);
    }

    private function percent(int $margin, int $invoiced): ?float
    {
        return $invoiced > 0 ? round($margin / $invoiced * 100, 1) : null;
    }
}
