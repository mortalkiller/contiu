<?php

namespace App\Filament\Resources\Projects\Pages;

use App\Enums\ProjectStatus;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Projects\Tables\ProjectsTable;
use App\Filament\Resources\Projects\Widgets\ProjectsSummary;
use App\Models\Project;
use App\Models\Receivable;
use App\Support\Money;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use pxlrbt\FilamentExcel\Actions\ExportAction;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class ListProjects extends ListRecords
{
    protected static string $resource = ProjectResource::class;

    /** As in the mock, the list opens on its heading: the menu already says where you are. */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    /** "6 projects · €5,100.00 in quotes for 2026": every project, and this year's quotes. */
    public function getSubheading(): string
    {
        // The total is worked out from the amount and its VAT, so it is added up here.
        $quotedThisYear = (int) Project::query()
            ->where('status', '!=', ProjectStatus::Cancelled)
            ->whereYear('quoted_on', today()->year)
            ->get()
            ->sum(fn (Project $project): int => (int) $project->total);

        return __(':projects · :amount in quotes for :year', [
            'projects' => trans_choice('{0} No projects|{1} 1 project|[2,*] :count projects', Project::query()->count()),
            'amount' => Money::format($quotedThisYear),
            'year' => today()->year,
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            ExportAction::make()
                ->label(__('Export to Excel'))
                ->icon(Phosphor::DownloadSimple)
                ->color('gray')
                ->exports([ProjectsTable::excelExport()]),
            CreateAction::make()
                ->label(__('New project'))
                ->icon(Phosphor::Plus),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [ProjectsSummary::class];
    }

    public function getTabs(): array
    {
        $withOpenReceivables = fn (Builder $query) => $query
            ->where('status', '!=', ProjectStatus::Cancelled)
            ->whereHas('receivables', fn (Builder $query) => $query->whereIn('receivables.id', Receivable::query()->open()->select('id')));

        $count = fn (array $statuses = []): ?int => ProjectResource::getModel()::query()
            ->when($statuses !== [], fn (Builder $query) => $query->whereIn('status', $statuses))
            ->count() ?: null;

        return [
            'all' => Tab::make(__('All'))->badge($count()),
            'active' => Tab::make(__('Active projects'))
                ->badge($count([ProjectStatus::Approved, ProjectStatus::InProgress]))
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [ProjectStatus::Approved, ProjectStatus::InProgress])),
            'outstanding' => Tab::make(__('Awaiting payment'))
                ->modifyQueryUsing($withOpenReceivables)
                ->badge(fn () => $withOpenReceivables(ProjectResource::getModel()::query())->count() ?: null)
                ->badgeColor('warning'),
            'proposals' => Tab::make(__('Proposals'))
                ->badge($count([ProjectStatus::Proposal]))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ProjectStatus::Proposal)),
            'completed' => Tab::make(__('Completed projects'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ProjectStatus::Completed)),
            'cancelled' => Tab::make(__('Cancelled projects'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', ProjectStatus::Cancelled)),
        ];
    }
}
