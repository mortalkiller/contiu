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
                    ->label('Exportar Excel')
                    ->exports([
                        ExcelExport::make()->fromTable()->ignoreFormatting(fn (ExportColumn $column) => method_exists($column->tableColumn, 'isMoney') && $column->tableColumn->isMoney())->except(['progress'])->withFilename(fn () => 'trabalhos-'.now()->format('Y-m-d')),
                    ]),
            ])
            ->toolbarActions([
                ExportBulkAction::make()
                    ->label('Exportar seleção')
                    ->exports([
                        ExcelExport::make()->fromTable()->ignoreFormatting(fn (ExportColumn $column) => method_exists($column->tableColumn, 'isMoney') && $column->tableColumn->isMoney())->except(['progress'])->withFilename(fn () => 'trabalhos-'.now()->format('Y-m-d')),
                    ]),
            ])
            ->defaultSort('quoted_on', 'desc')
            ->columns([
                TextColumn::make('code')
                    ->label('Trabalho')
                    ->description(fn (Project $record) => $record->title)
                    ->weight('medium')
                    ->searchable(['code', 'title']),
                TextColumn::make('client.name')
                    ->label('Cliente')
                    ->searchable()
                    ->limit(28)
                    ->tooltip(fn (Project $record) => $record->client->name),
                TextColumn::make('business_line')
                    ->label('Linha')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge(),
                TextColumn::make('total')
                    ->label('Total')
                    ->money('EUR')
                    ->alignEnd(),
                ViewColumn::make('progress')
                    ->label('Recebido')
                    ->view('filament.tables.columns.progress'),
                TextColumn::make('outstanding_amount')
                    ->label('Em aberto')
                    ->money('EUR')
                    ->alignEnd()
                    ->color(fn (float $state) => $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('payment_status')
                    ->label('Pagamento')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('quoted_on')
                    ->label('Orçamento')
                    ->date()
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('client')
                    ->label('Cliente')
                    ->relationship('client', 'name')
                    ->preload(),
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(ProjectStatus::class)
                    ->multiple(),
                SelectFilter::make('business_line')
                    ->label('Linha de negócio')
                    ->options(fn () => Project::query()->whereNotNull('business_line')->distinct()->orderBy('business_line')->pluck('business_line', 'business_line')->all()),
                SelectFilter::make('year')
                    ->label('Ano do orçamento')
                    ->options(fn () => Project::query()->whereNotNull('quoted_on')->pluck('quoted_on')->map->year->unique()->sortDesc()->mapWithKeys(fn ($year) => [$year => $year])->all())
                    ->query(fn (Builder $query, array $data) => $query->when($data['value'] ?? null, fn (Builder $query, $year) => $query->whereYear('quoted_on', $year))),
            ])
            ->recordActions([
                RecordPaymentAction::make()->iconButton()->tooltip('Registar recebimento'),
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                ]),
            ])
            ->emptyStateHeading('Sem trabalhos')
            ->emptyStateDescription('Cria um trabalho a partir de um orçamento aprovado.');
    }
}
