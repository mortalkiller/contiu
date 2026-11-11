<?php

namespace App\Filament\Resources\Projects\Widgets;

use App\Enums\Language;
use App\Models\Project;
use App\Models\Receivable;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Number;
use MortalKiller\FilamentAppearance\Widgets\Concerns\GroupsStats;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

/**
 * The four figures under the project's title, in one card: the total and its VAT, what came in
 * (with how much of the total that is), what is still open and by when, and the margin after the
 * direct costs.
 */
class ProjectSummary extends StatsOverviewWidget
{
    use GroupsStats;
    use HasSkeletonPlaceholder;

    public ?Project $record = null;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array
    {
        return ['md' => 2, 'xl' => 4];
    }

    protected function getStats(): array
    {
        $project = $this->record;

        if (! $project instanceof Project) {
            return [];
        }

        $project->loadMissing(['receivables' => fn ($query) => $query->withOutstandingBalance(), 'receivables.payments', 'expenses']);
        $outstanding = (int) $project->outstanding_amount;

        return [
            Stat::make(__('Total'), Money::format((int) $project->total))
                ->description($project->vat_amount > 0
                    ? __('Includes :amount VAT', ['amount' => Money::format($project->vat_amount)])
                    : __('Excluding VAT')),
            Stat::make(__('Received'), Money::format((int) $project->received_amount))
                ->progress(round($project->progress * 100, 1)),
            Stat::make(__('Outstanding'), $outstanding > 0 ? Money::format($outstanding) : '—')
                ->description(static::nextDueNote($project))
                ->valueColor($outstanding > 0 ? 'warning' : null),
            Stat::make(__('Margin'), Money::format((int) $project->margin))
                ->description(static::marginNote($project)),
        ];
    }

    /** When the next open instalment is due, or that it is already late. */
    private static function nextDueNote(Project $project): string
    {
        $next = $project->receivables
            ->filter(fn (Receivable $receivable): bool => $receivable->projectedOutstanding() > 0)
            ->sortBy('due_on')
            ->first();

        if ($next === null) {
            return __('Nothing to receive');
        }

        $date = $next->due_on->format(Language::resolve(app()->getLocale())->dateFormat());

        return $next->due_on->isBefore(today())
            ? __('Was due on :date', ['date' => $date])
            : __('Due on :date', ['date' => $date]);
    }

    /** "52.5% · €380.00 in direct costs": the margin as a share of what was billed, and the costs behind it. */
    private static function marginNote(Project $project): string
    {
        $costs = __(':amount in direct costs', ['amount' => Money::format($project->direct_costs)]);

        return $project->billed_amount > 0
            ? Number::format($project->margin / $project->billed_amount * 100, maxPrecision: 1, locale: app()->getLocale()).'% · '.$costs
            : $costs;
    }
}
