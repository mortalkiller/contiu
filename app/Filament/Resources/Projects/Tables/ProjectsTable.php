<?php

namespace App\Filament\Resources\Projects\Tables;

use App\Enums\ProjectStatus;
use App\Filament\Actions\RecordPaymentAction;
use App\Filament\Support\OutstandingColumn;
use App\Models\Project;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use MortalKiller\FilamentAppearance\Tables\Columns\ProgressColumn;
use pxlrbt\FilamentExcel\Columns\Column as ExportColumn;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

/**
 * "Trabalhos" as in the mock: the title with its code and business line under it, the client,
 * the state, the amounts with how much of the total came in, the quote date, "Receive" on a
 * project with something open and "Approve" on a proposal, and the totals at the foot. The
 * export sits in the page header (ListProjects).
 */
class ProjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('quoted_on', 'desc')
            ->searchPlaceholder(__('Reference, name or client'))
            ->columns([
                TextColumn::make('title')
                    ->label(__('Project'))
                    ->description(fn (Project $record): string => collect([$record->code, $record->business_line])->filter()->implode(' · '))
                    ->weight(FontWeight::Medium)
                    // A long title takes two lines, so the list fits the page.
                    ->wrap()
                    ->searchable(['code', 'title']),
                TextColumn::make('client.name')
                    ->label(__('Client'))
                    ->searchable()
                    ->limit(24)
                    ->tooltip(fn (Project $record) => $record->client->name),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge(),
                TextColumn::make('total')
                    ->label(__('Total'))
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->summarize(static::sumOf('total')),
                ProgressColumn::make('progress')
                    ->label(__('Received'))
                    ->state(fn (Project $record): ?float => $record->status === ProjectStatus::Proposal ? null : $record->progress * 100)
                    ->placeholder(__('Not approved yet'))
                    ->precision(0)
                    ->color(fn (?float $state): string => $state >= 100 ? 'success' : 'primary'),
                static::outstandingColumn(),
                TextColumn::make('quoted_on')
                    ->label(__('Quote'))
                    ->date()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('client')
                    ->label(__('Client'))
                    ->relationship('client', 'name')
                    ->preload(),
                SelectFilter::make('status')
                    ->label(__('Status'))
                    ->options(ProjectStatus::class)
                    ->multiple(),
                SelectFilter::make('business_line')
                    ->label(__('Business line'))
                    ->options(fn () => Project::query()->whereNotNull('business_line')->distinct()->orderBy('business_line')->pluck('business_line', 'business_line')->all()),
                SelectFilter::make('year')
                    ->label(__('Quote year'))
                    ->options(fn () => Project::query()->whereNotNull('quoted_on')->pluck('quoted_on')->map->year->unique()->sortDesc()->mapWithKeys(fn ($year) => [$year => $year])->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, $year) => $query->whereYear('quoted_on', $year))),
            ])
            ->recordActions([
                RecordPaymentAction::rowButton(),
                static::approveAction(),
            ])
            ->emptyStateHeading(__('No projects'))
            ->emptyStateDescription(__('Create a project from an approved quote.'));
    }

    /** What is still to receive (see OutstandingColumn), with its total at the foot. */
    public static function outstandingColumn(): TextColumn
    {
        return OutstandingColumn::make()->summarize(static::sumOf('outstanding_amount'));
    }

    /** "Approve", on a proposal: the client said yes, so the work goes ahead. */
    public static function approveAction(): Action
    {
        return Action::make('approve')
            ->label(__('Approve'))
            ->link()
            ->color('gray')
            ->size('sm')
            ->requiresConfirmation()
            ->modalHeading(fn (Project $record): string => __('Approve :project?', ['project' => $record->title]))
            ->visible(fn (Project $record): bool => $record->status === ProjectStatus::Proposal)
            ->action(function (Project $record): void {
                $record->update(['status' => ProjectStatus::Approved]);

                Notification::make()->success()->title(__('Proposal approved'))->send();
            });
    }

    /**
     * The total of a computed amount (worked out from the instalments and their payments) over
     * the projects the table shows, for the foot of the table.
     */
    public static function sumOf(string $attribute): Summarizer
    {
        return Summarizer::make('sum')
            ->money('EUR', divideBy: 100)
            ->using(fn (QueryBuilder $query): int => (int) Project::query()
                ->whereIn('id', $query->pluck('projects.id'))
                ->with(['receivables' => fn ($query) => $query->withOutstandingBalance(), 'receivables.payments'])
                ->get()
                ->sum($attribute));
    }

    /** The Excel export of the list, as the table shows it, with plain numbers for the amounts. */
    public static function excelExport(): ExcelExport
    {
        return ExcelExport::make()
            ->fromTable()
            ->ignoreFormatting(fn (ExportColumn $column) => method_exists($column->tableColumn, 'isMoney') && $column->tableColumn->isMoney())
            ->except(['progress'])
            ->withFilename(fn () => str(__('Projects'))->slug().'-'.now()->format('Y-m-d'));
    }
}
