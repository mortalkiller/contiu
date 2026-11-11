<?php

namespace App\Filament\Resources\Receivables\Widgets;

use App\Filament\Support\ProportionBar;
use App\Models\PaymentAllocation;
use App\Models\Receivable;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

/**
 * Above the "A receber" list: what is still open, split by how soon it is due, and what is
 * already late. Cancelled projects are left out, as in the list itself.
 */
class ReceivablesSummary extends StatsOverviewWidget
{
    use HasSkeletonPlaceholder;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array
    {
        return ['md' => 3];
    }

    protected function getStats(): array
    {
        $open = Receivable::query()->open()->excludingCancelled()->withOutstandingBalance()->with('billable.client')->get();

        $buckets = ['overdue' => 0, 'week' => 0, 'month' => 0, 'later' => 0];
        $lateClients = [];

        foreach ($open as $receivable) {
            $bucket = match (true) {
                $receivable->due_on->isBefore(today()) => 'overdue',
                $receivable->due_on->lte(today()->addDays(7)) => 'week',
                $receivable->due_on->lte(today()->addDays(30)) => 'month',
                default => 'later',
            };

            $buckets[$bucket] += $receivable->projectedOutstanding();

            if ($bucket === 'overdue') {
                $lateClients[] = $receivable->billable?->client_id;
            }
        }

        $lateCount = $open->filter(fn (Receivable $receivable): bool => $receivable->due_on->isBefore(today()))->count();
        $lateClientCount = count(array_unique(array_filter($lateClients)));

        return [
            Stat::make(__('Outstanding'), Money::format(array_sum($buckets), locale: app()->getLocale()))
                ->description(ProportionBar::render([
                    ['label' => __('Past due'), 'cents' => $buckets['overdue'], 'tone' => 'danger'],
                    ['label' => __('Within 7 days'), 'cents' => $buckets['week'], 'tone' => 'strong'],
                    ['label' => __('8 to 30 days'), 'cents' => $buckets['month'], 'tone' => 'medium'],
                    ['label' => __('Later'), 'cents' => $buckets['later'], 'tone' => 'soft'],
                ])),
            Stat::make(__('Past due'), Money::format($buckets['overdue'], locale: app()->getLocale()))
                ->description($lateCount === 0
                    ? __('No overdue amounts')
                    : __(':amounts · :clients', [
                        'amounts' => trans_choice('{1} 1 amount|[2,*] :count amounts', $lateCount),
                        'clients' => trans_choice('{1} 1 client|[2,*] :count clients', $lateClientCount),
                    ]))
                ->valueColor($lateCount === 0 ? null : 'danger'),
            $this->delayStat(),
        ];
    }

    /**
     * How long clients take to pay after the due date, on average, over the last six months of
     * payments; the sparkline has one bar per month. Paying early counts as zero days.
     */
    private function delayStat(): Stat
    {
        $first = today()->startOfMonth()->subMonths(5);

        $delays = PaymentAllocation::query()
            ->whereDate('allocated_on', '>=', $first)
            ->with('receivable')
            ->get()
            ->filter(fn (PaymentAllocation $allocation): bool => $allocation->receivable !== null)
            ->map(fn (PaymentAllocation $allocation): array => [
                'month' => Carbon::parse($allocation->allocated_on)->format('Y-m'),
                'days' => max(0, (int) $allocation->receivable->due_on->diffInDays(Carbon::parse($allocation->allocated_on), false)),
            ]);

        $byMonth = collect(range(0, 5))
            ->map(fn (int $offset): float => round((float) $delays->where('month', $first->copy()->addMonths($offset)->format('Y-m'))->avg('days'), 1))
            ->all();

        $average = $delays->isEmpty() ? null : (int) round($delays->avg('days'));

        $stat = Stat::make(__('Average time to pay'), $average === null ? '—' : trans_choice('{0} On the due date|{1} 1 day|[2,*] :count days', $average));

        return $average === null
            ? $stat->description(__('No payments in the last 6 months'))
            // As in the mock: one bar per month, the current one in full colour.
            : $stat->bars($byMonth, label: __('After the due date · last 6 months'), labels: collect(range(0, 5))
                ->map(fn (int $offset): string => ucfirst($first->copy()->addMonths($offset)->locale(app()->getLocale())->translatedFormat('F')))
                ->all(), formatValueUsing: fn (float $days): string => trans_choice('{0} On the due date|{1} 1 day|[2,*] :count days', (int) round($days)));
    }
}
