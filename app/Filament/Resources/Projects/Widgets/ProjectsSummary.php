<?php

namespace App\Filament\Resources\Projects\Widgets;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * Above the "Trabalhos" list: work in progress, what is still to receive, proposals waiting for
 * an answer, and what was completed this year.
 */
class ProjectsSummary extends StatsOverviewWidget
{
    use HasSkeletonPlaceholder;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array
    {
        return ['md' => 2, 'xl' => 4];
    }

    protected function getStats(): array
    {
        /** @var Collection<int, Project> $projects */
        $projects = Project::query()->with('receivables.payments')->get();

        $active = $projects->filter(fn (Project $project): bool => in_array($project->status, [ProjectStatus::Approved, ProjectStatus::InProgress], true));
        $notCancelled = $projects->filter(fn (Project $project): bool => $project->status !== ProjectStatus::Cancelled);
        $owing = $notCancelled->filter(fn (Project $project): bool => $project->outstanding_amount > 0);
        $overdue = (int) $owing->sum(fn (Project $project): int => $project->overdue_amount);
        $proposals = $projects->filter(fn (Project $project): bool => $project->status === ProjectStatus::Proposal);
        $oldestProposal = $proposals->min('quoted_on');
        // Every proposal of this year, and the ones that went ahead (cancelled ones did not).
        $decided = $projects->filter(fn (Project $project): bool => $project->quoted_on?->year === today()->year);
        $approved = $decided->reject(fn (Project $project): bool => in_array($project->status, [ProjectStatus::Proposal, ProjectStatus::Cancelled], true));
        $approvalRate = $decided->isEmpty() ? 0 : (int) round($approved->count() / $decided->count() * 100);

        $count = fn (int $number): string => trans_choice('{0} Nenhum trabalho|{1} 1 trabalho|[2,*] :count trabalhos', $number);

        return [
            Stat::make('Em curso', Money::format((int) $active->sum(fn (Project $project): int => (int) $project->total)))
                ->description($active->isEmpty()
                    ? $count(0)
                    : $count($active->count()).' · '.Money::format((int) $active->sum(fn (Project $project): int => $project->received_amount)).' recebidos')
                ->icon(Phosphor::Briefcase),
            Stat::make('Por receber', Money::format((int) $owing->sum(fn (Project $project): int => $project->outstanding_amount)))
                ->description(match (true) {
                    $owing->isEmpty() => 'Tudo recebido',
                    $overdue > 0 => Money::format($overdue).' vencidos',
                    default => $count($owing->count()).' · nada vencido',
                })
                ->color(match (true) {
                    $owing->isEmpty() => 'success',
                    $overdue > 0 => 'danger',
                    default => 'warning',
                })
                ->icon(Phosphor::HourglassMedium),
            Stat::make('Propostas à espera', Money::format((int) $proposals->sum(fn (Project $project): int => (int) $project->total)))
                ->description($proposals->isEmpty()
                    ? 'Nenhuma proposta em aberto'
                    : trans_choice('{1} 1 proposta|[2,*] :count propostas', $proposals->count())
                        .' · a mais antiga há '.trans_choice('{0} menos de um dia|{1} 1 dia|[2,*] :count dias', (int) $oldestProposal->diffInDays(today())))
                ->icon(Phosphor::PaperPlaneTilt),
            Stat::make('Propostas aprovadas', $decided->isEmpty() ? '—' : $approved->count().' de '.$decided->count())
                ->description($decided->isEmpty()
                    ? 'Ainda sem propostas em '.today()->year
                    : new HtmlString('<span class="contiu-progress" role="img" aria-label="'.$approvalRate.'% das propostas aprovadas"><i style="width: '.$approvalRate.'%"></i></span>'))
                ->icon(Phosphor::CheckCircle),
        ];
    }
}
