<?php

namespace App\Filament\Resources\Clients\Widgets;

use App\Enums\Language;
use App\Models\Client;
use App\Models\Receivable;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use MortalKiller\FilamentAppearance\Widgets\Concerns\GroupsStats;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

/**
 * The four figures under the client's name, in one card: what was contracted, what came in (with how much of
 * the contracted that is), what is still to receive and by when, and what is already late.
 */
class ClientSummary extends StatsOverviewWidget
{
    use GroupsStats;
    use HasSkeletonPlaceholder;

    public ?Client $record = null;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array
    {
        return ['md' => 2, 'xl' => 4];
    }

    protected function getStats(): array
    {
        $client = $this->record;

        if (! $client instanceof Client) {
            return [];
        }

        $contracted = (int) $client->contracted_amount;
        $received = (int) $client->received_amount;
        $outstanding = (int) $client->outstanding_amount;
        $overdue = (int) $client->overdue_amount;
        $nextDue = $client->openReceivables()->first(fn (Receivable $receivable): bool => $receivable->due_on->isFuture() || $receivable->due_on->isToday());
        $late = $client->openReceivables()->filter(fn (Receivable $receivable): bool => $receivable->due_on->isBefore(today()))->count();

        return [
            Stat::make(__('Contracted'), Money::format($contracted))
                ->description($this->contractedNote($client)),
            Stat::make(__('Received'), Money::format($received))
                ->progress($contracted > 0 ? round($received / $contracted * 100, 1) : null),
            Stat::make(__('Still to receive'), Money::format($outstanding))
                ->description(match (true) {
                    $outstanding === 0 => __('Nothing to receive'),
                    $nextDue !== null => __('Due on :date', ['date' => $nextDue->due_on->format(Language::resolve(app()->getLocale())->dateFormat())]),
                    default => __('Already due'),
                })
                ->valueColor($outstanding > 0 ? 'warning' : null),
            Stat::make(__('Past due'), Money::format($overdue))
                ->description(match (true) {
                    $late === 0 => __('Nothing overdue'),
                    default => trans_choice(':count amount overdue|:count amounts overdue', $late, ['count' => $late]),
                })
                ->valueColor($late > 0 ? 'danger' : null),
        ];
    }

    /** "4 projects" · "4 projects, 1 retainer" · "1 retainer". */
    private function contractedNote(Client $client): string
    {
        $projects = $client->projects()->count();
        $retainers = $client->retainers()->count();

        $parts = array_filter([
            $projects > 0 ? trans_choice(':count project|:count projects', $projects, ['count' => $projects]) : null,
            $retainers > 0 ? trans_choice(':count retainer|:count retainers', $retainers, ['count' => $retainers]) : null,
        ]);

        return $parts === [] ? __('No projects or retainers') : implode(', ', $parts);
    }
}
