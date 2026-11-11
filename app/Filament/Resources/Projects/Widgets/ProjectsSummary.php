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
        $projects = Project::query()->with(['receivables' => fn ($query) => $query->withOutstandingBalance(), 'receivables.payments'])->get();

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

        $count = fn (int $number): string => trans_choice('{0} No projects|{1} 1 project|[2,*] :count projects', $number);

        return [
            Stat::make(__('Active projects'), Money::format((int) $active->sum(fn (Project $project): int => (int) $project->total)))
                ->description($active->isEmpty()
                    ? $count(0)
                    : __(':projects · :amount received', [
                        'projects' => $count($active->count()),
                        'amount' => Money::format((int) $active->sum(fn (Project $project): int => $project->received_amount)),
                    ]))
                ->icon(Phosphor::Briefcase),
            Stat::make(__('Awaiting payment'), Money::format((int) $owing->sum(fn (Project $project): int => $project->outstanding_amount)))
                ->description(match (true) {
                    $owing->isEmpty() => __('All payments received'),
                    $overdue > 0 => __(':amount past due', ['amount' => Money::format($overdue)]),
                    default => __(':projects · none past due', ['projects' => $count($owing->count())]),
                })
                ->color(match (true) {
                    $owing->isEmpty() => 'success',
                    $overdue > 0 => 'danger',
                    default => 'warning',
                })
                ->icon(Phosphor::HourglassMedium),
            Stat::make(__('Pending proposals'), Money::format((int) $proposals->sum(fn (Project $project): int => (int) $project->total)))
                ->description($proposals->isEmpty()
                    ? __('No open proposals')
                    : __(':proposals · the oldest is :age old', [
                        'proposals' => trans_choice('{1} 1 proposal|[2,*] :count proposals', $proposals->count()),
                        'age' => trans_choice('{0} less than a day|{1} 1 day|[2,*] :count days', (int) $oldestProposal->diffInDays(today())),
                    ]))
                ->icon(Phosphor::PaperPlaneTilt),
            Stat::make(__('Approved proposals'), $decided->isEmpty() ? '—' : __(':approved of :total', ['approved' => $approved->count(), 'total' => $decided->count()]))
                ->description($decided->isEmpty()
                    ? __('No proposals yet in :year', ['year' => today()->year])
                    : new HtmlString('<span class="fap-progress" role="img" aria-label="'.e(__(':percent% of proposals approved', ['percent' => $approvalRate])).'"><i style="width: '.$approvalRate.'%"></i></span>'))
                ->icon(Phosphor::CheckCircle),
        ];
    }
}
