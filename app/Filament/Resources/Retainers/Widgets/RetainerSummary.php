<?php

namespace App\Filament\Resources\Retainers\Widgets;

use App\Enums\Frequency;
use App\Models\Receivable;
use App\Models\Retainer;
use App\Support\Money;
use App\Support\RelativeDue;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Str;
use MortalKiller\FilamentAppearance\Widgets\Concerns\GroupsStats;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

/**
 * The four figures under a retainer's title, as in the mock, in one card: what it bills each
 * period, what has come in, what is still open (and whether any of it is late) and when the next
 * amount is due.
 */
class RetainerSummary extends StatsOverviewWidget
{
    use GroupsStats;
    use HasSkeletonPlaceholder;

    public ?Retainer $record = null;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array
    {
        return ['md' => 2, 'xl' => 4];
    }

    protected function getStats(): array
    {
        $retainer = $this->record;

        if (! $retainer instanceof Retainer) {
            return [];
        }

        $retainer->loadMissing(['receivables' => fn ($query) => $query->withOutstandingBalance(), 'receivables.payments']);
        $outstanding = (int) $retainer->outstanding_amount;
        $overdue = (int) $retainer->overdue_amount;
        $next = $retainer->receivables
            ->filter(fn (Receivable $receivable): bool => $receivable->projectedOutstanding() > 0 && ! RelativeDue::isPast($receivable->due_on))
            ->sortBy('due_on')
            ->first();

        $vatNote = $retainer->vat_amount > 0
            ? __('Includes :amount VAT', ['amount' => Money::format($retainer->vat_amount)])
            : __('Excluding VAT');

        return [
            Stat::make(match ($retainer->frequency) {
                Frequency::Monthly => __('Per month'),
                Frequency::Quarterly => __('Per quarter'),
                Frequency::Yearly => __('Per year'),
            }, Money::format((int) $retainer->total))
                // A yearly retainer already says its yearly value: only the VAT note is left.
                ->description($retainer->frequency === Frequency::Yearly
                    ? $vatNote
                    : __(':amount per year · :vat', ['amount' => Money::format((int) $retainer->yearly_value), 'vat' => Str::lcfirst($vatNote)])),
            Stat::make(__('Received'), Money::format((int) $retainer->received_amount))
                ->progress(round($retainer->progress * 100, 1)),
            Stat::make(__('Outstanding'), $outstanding > 0 ? Money::format($outstanding) : '—')
                ->description(match (true) {
                    $overdue > 0 => __(':amount past due', ['amount' => Money::format($overdue)]),
                    $outstanding > 0 => __('Nothing overdue'),
                    default => __('Nothing to receive'),
                })
                ->color($overdue > 0 ? 'danger' : null)
                ->valueColor($outstanding > 0 ? 'warning' : null),
            Stat::make(__('Next due date'), $next === null ? '—' : $next->due_on->format(__('m/d')))
                ->description($next === null
                    ? ($retainer->is_active ? __('No amount created yet') : __('The retainer has ended'))
                    : $next->description.' · '.RelativeDue::describe($next->due_on)),
        ];
    }
}
