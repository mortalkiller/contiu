<?php

namespace App\Filament\Resources\Receivables\Widgets;

use App\Filament\Support\ProportionBar;
use App\Models\PaymentAllocation;
use App\Models\Receivable;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;
use ToneGabes\Filament\Icons\Enums\Phosphor;

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
        return ['md' => 2, 'xl' => 4];
    }

    protected function getStats(): array
    {
        $open = Receivable::query()->open()->excludingCancelled()->withOutstandingBalance()->with('billable.client')->get();

        $buckets = ['overdue' => 0, 'week' => 0, 'month' => 0, 'later' => 0];
        $lateClients = [];
        $byClient = [];

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

            $name = $receivable->client?->name ?? __('No client');
            $byClient[$name] ??= ['open' => 0, 'overdue' => 0];
            $byClient[$name]['open'] += $receivable->projectedOutstanding();
            $byClient[$name]['overdue'] += $bucket === 'overdue' ? $receivable->projectedOutstanding() : 0;
        }

        uasort($byClient, fn (array $a, array $b): int => $b['open'] <=> $a['open']);

        $lateCount = $open->filter(fn (Receivable $receivable): bool => $receivable->due_on->isBefore(today()))->count();
        $lateClientCount = count(array_unique(array_filter($lateClients)));

        return [
            Stat::make(__('Outstanding'), Money::format(array_sum($buckets), locale: app()->getLocale()))
                ->description(ProportionBar::render([
                    ['label' => __('Past due'), 'cents' => $buckets['overdue'], 'tone' => 'danger'],
                    ['label' => __('Within 7 days'), 'cents' => $buckets['week'], 'tone' => 'strong'],
                    ['label' => __('8 to 30 days'), 'cents' => $buckets['month'], 'tone' => 'medium'],
                    ['label' => __('Later'), 'cents' => $buckets['later'], 'tone' => 'soft'],
                ]))
                ->icon(Phosphor::HourglassMedium),
            Stat::make(__('Past due'), Money::format($buckets['overdue'], locale: app()->getLocale()))
                ->description($lateCount === 0
                    ? __('No overdue amounts')
                    : __(':amounts · :clients', [
                        'amounts' => trans_choice('{1} 1 amount|[2,*] :count amounts', $lateCount),
                        'clients' => trans_choice('{1} 1 client|[2,*] :count clients', $lateClientCount),
                    ]))
                ->color($lateCount === 0 ? 'success' : 'danger')
                ->icon(Phosphor::Warning),
            $this->delayStat(),
            Stat::make(__('Who owes money'), trans_choice('{0} Nobody|{1} 1 client|[2,*] :count clients', count($byClient)))
                ->description($byClient === [] ? __('Nothing outstanding') : $this->debtors($byClient))
                ->icon(Phosphor::UsersThree),
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

        return Stat::make(__('Average time to pay'), $average === null ? '—' : trans_choice('{0} On the due date|{1} 1 day|[2,*] :count days', $average))
            ->description($average === null ? __('No payments in the last 6 months') : __('After the due date · last 6 months'))
            ->chart($average === null ? null : $byMonth)
            ->color('primary')
            ->icon(Phosphor::Timer);
    }

    /**
     * The three clients who owe the most, each with a bar relative to the biggest debt.
     *
     * @param  array<string, array{open: int, overdue: int}>  $byClient
     */
    private function debtors(array $byClient): HtmlString
    {
        $largest = max(array_column($byClient, 'open'));
        $rows = '';

        foreach (array_slice($byClient, 0, 3, true) as $name => $debt) {
            $rows .= '<span class="contiu-debtor"><span class="contiu-debtor-head"><span>'.e($name).'</span><strong>'.e(Money::format($debt['open'], locale: app()->getLocale())).'</strong></span>'
                .'<span class="fap-progress"><i style="width: '.round($debt['open'] / $largest * 100, 1).'%"></i></span>'
                .($debt['overdue'] > 0 ? '<span class="contiu-debtor-late">'.e(__(':amount overdue', ['amount' => Money::format($debt['overdue'], locale: app()->getLocale())])).'</span>' : '')
                .'</span>';
        }

        return new HtmlString('<span class="contiu-debtors">'.$rows.'</span>');
    }
}
