<?php

namespace App\Filament\Resources\Clients\Tables;

use App\Filament\Actions\RecordPaymentAction;
use App\Models\Client;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Columns\Column as ExportColumn;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

class ClientsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->headerActions([
                ExportAction::make()
                    ->label(__('Export to Excel'))
                    ->exports([
                        ExcelExport::make()->fromTable()->ignoreFormatting(fn (ExportColumn $column) => method_exists($column->tableColumn, 'isMoney') && $column->tableColumn->isMoney())->withFilename(fn () => str(__('Clients'))->slug().'-'.now()->format('Y-m-d')),
                    ]),
            ])
            ->toolbarActions([
                ExportBulkAction::make()
                    ->label(__('Export selected'))
                    ->exports([
                        ExcelExport::make()->fromTable()->ignoreFormatting(fn (ExportColumn $column) => method_exists($column->tableColumn, 'isMoney') && $column->tableColumn->isMoney())->withFilename(fn () => str(__('Clients'))->slug().'-'.now()->format('Y-m-d')),
                    ]),
            ])
            ->modifyQueryUsing(fn ($query) => $query->withCount(['projects', 'retainers'])->withReceivableTotals())
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('Client'))
                    ->description(fn (Client $record) => $record->vat_number ? __('Tax ID :number', ['number' => $record->vat_number]) : $record->email)
                    ->weight('medium')
                    ->searchable(['name', 'vat_number', 'email'])
                    ->sortable(),
                TextColumn::make('projects_count')->label(__('Projects'))->alignCenter(),
                TextColumn::make('retainers_count')->label(__('Retainers'))->alignCenter(),
                TextColumn::make('contracted_amount')->label(__('Contracted'))->money('EUR', divideBy: 100)->alignEnd(),
                TextColumn::make('received_amount')->label(__('Received'))->money('EUR', divideBy: 100)->alignEnd(),
                TextColumn::make('outstanding_amount')->label(__('Awaiting payment'))->money('EUR', divideBy: 100)->alignEnd()
                    ->color(fn (float $state) => $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('overdue_amount')->label(__('Past due'))->money('EUR', divideBy: 100)->alignEnd()
                    ->color(fn (float $state) => $state > 0 ? 'danger' : 'gray'),
            ])
            ->recordActions([
                RecordPaymentAction::make()->iconButton()->tooltip(__('Record payment'))
                    ->visible(fn (Client $record): bool => $record->outstanding_amount > 0),
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                ]),
            ])
            ->emptyStateHeading(__('No clients'));
    }
}
