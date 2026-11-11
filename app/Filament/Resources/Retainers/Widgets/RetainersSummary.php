<?php

namespace App\Filament\Resources\Retainers\Widgets;

use App\Enums\Language;
use App\Filament\Resources\Receivables\ReceivableResource;
use App\Models\Retainer;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Collection;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

/**
 * Above the "Avenças" list: what the active retainers bring in per month and per year, the next
 * amount to fall due, and what is still open.
 */
class RetainersSummary extends StatsOverviewWidget
{
    use HasSkeletonPlaceholder;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array
    {
        return ['md' => 2, 'xl' => 4];
    }

    protected function getStats(): array
    {
        /** @var Collection<int, Retainer> $active */
        $active = Retainer::query()->where('is_active', true)->with(['client', 'receivables' => fn ($query) => $query->withOutstandingBalance(), 'receivables.payments'])->get();

        $perYear = (int) $active->sum(fn (Retainer $retainer): int => $retainer->yearly_value);
        $outstanding = (int) $active->sum(fn (Retainer $retainer): int => $retainer->outstanding_amount);
        $overdue = (int) $active->sum(fn (Retainer $retainer): int => $retainer->overdue_amount);

        $next = $active
            ->filter(fn (Retainer $retainer): bool => $retainer->next_due_on !== null)
            ->sortBy(fn (Retainer $retainer) => $retainer->next_due_on)
            ->first();

        return [
            Stat::make(__('Monthly recurring income'), Money::format(Money::round($perYear / 12), locale: app()->getLocale()))
                ->description(trans_choice('{0} No active retainers|{1} 1 active retainer|[2,*] :count active retainers', $active->count())),
            Stat::make(__('Per year'), Money::format($perYear, locale: app()->getLocale()))
                ->description(__('If nothing changes')),
            Stat::make(__('Next due date'), $next === null ? '—' : $next->next_due_on->format(Language::resolve(app()->getLocale())->dateFormat()))
                ->description($next === null ? __('Nothing due') : $next->client->name.' · '.Money::format((int) $next->total, locale: app()->getLocale())),
            Stat::make(__('Outstanding'), Money::format($outstanding, locale: app()->getLocale()))
                ->description(match (true) {
                    $outstanding === 0 => __('All received'),
                    $overdue > 0 => __(':amount overdue', ['amount' => Money::format($overdue, locale: app()->getLocale())]),
                    default => __('No overdue amounts'),
                })
                ->valueColor($outstanding > 0 ? 'warning' : null)
                ->url(ReceivableResource::getUrl()),
        ];
    }
}
