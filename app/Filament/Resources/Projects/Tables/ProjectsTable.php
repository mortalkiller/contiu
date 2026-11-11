<?php

namespace App\Filament\Resources\Projects\Tables;

use App\Enums\ProjectStatus;
use App\Filament\Actions\RecordPaymentAction;
use App\Models\Project;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Columns\Column as ExportColumn;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

class ProjectsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->headerActions([
                ExportAction::make()
                    ->label(__('Export to Excel'))
                    ->exports([
                        ExcelExport::make()->fromTable()->ignoreFormatting(fn (ExportColumn $column) => method_exists($column->tableColumn, 'isMoney') && $column->tableColumn->isMoney())->except(['progress'])->withFilename(fn () => str(__('Projects'))->slug().'-'.now()->format('Y-m-d')),
                    ]),
            ])
            ->toolbarActions([
                ExportBulkAction::make()
                    ->label(__('Export selected'))
                    ->exports([
                        ExcelExport::make()->fromTable()->ignoreFormatting(fn (ExportColumn $column) => method_exists($column->tableColumn, 'isMoney') && $column->tableColumn->isMoney())->except(['progress'])->withFilename(fn () => str(__('Projects'))->slug().'-'.now()->format('Y-m-d')),
                    ]),
            ])
            ->defaultSort('quoted_on', 'desc')
            ->columns([
                TextColumn::make('code')
                    ->label(__('Project'))
                    ->description(fn (Project $record) => $record->title)
                    ->weight('medium')
                    ->searchable(['code', 'title']),
                TextColumn::make('client.name')
                    ->label(__('Client'))
                    ->searchable()
                    ->limit(28)
                    ->tooltip(fn (Project $record) => $record->client->name),
                TextColumn::make('business_line')
                    ->label(__('Line'))
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('status')
                    ->label(__('Status'))
                    ->badge(),
                TextColumn::make('total')
                    ->label(__('Total'))
                    ->money('EUR', divideBy: 100)
                    ->alignEnd(),
                ViewColumn::make('progress')
                    ->label(__('Received'))
                    ->view('filament.tables.columns.progress'),
                TextColumn::make('outstanding_amount')
                    ->label(__('Outstanding'))
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->color(fn (float $state) => $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('payment_status')
                    ->label(__('Payment'))
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('quoted_on')
                    ->label(__('Quote'))
                    ->date()
                    ->sortable()
                    ->toggleable(),
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
                RecordPaymentAction::make()->iconButton()->tooltip(__('Record payment')),
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                ]),
            ])
            ->emptyStateHeading(__('No projects'))
            ->emptyStateDescription(__('Create a project from an approved quote.'));
    }
}
