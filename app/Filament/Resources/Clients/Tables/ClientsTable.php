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
                    ->label('Exportar Excel')
                    ->exports([
                        ExcelExport::make()->fromTable()->ignoreFormatting(fn (ExportColumn $column) => method_exists($column->tableColumn, 'isMoney') && $column->tableColumn->isMoney())->withFilename(fn () => 'clientes-'.now()->format('Y-m-d')),
                    ]),
            ])
            ->toolbarActions([
                ExportBulkAction::make()
                    ->label('Exportar seleção')
                    ->exports([
                        ExcelExport::make()->fromTable()->ignoreFormatting(fn (ExportColumn $column) => method_exists($column->tableColumn, 'isMoney') && $column->tableColumn->isMoney())->withFilename(fn () => 'clientes-'.now()->format('Y-m-d')),
                    ]),
            ])
            ->modifyQueryUsing(fn ($query) => $query->withCount(['projects', 'retainers']))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label('Cliente')
                    ->description(fn (Client $record) => $record->vat_number ? 'NIF '.$record->vat_number : $record->email)
                    ->weight('medium')
                    ->searchable(['name', 'vat_number', 'email'])
                    ->sortable(),
                TextColumn::make('projects_count')->label('Trabalhos')->alignCenter(),
                TextColumn::make('retainers_count')->label('Avenças')->alignCenter(),
                TextColumn::make('contracted_amount')->label('Contratado')->money('EUR', divideBy: 100)->alignEnd(),
                TextColumn::make('received_amount')->label('Recebido')->money('EUR', divideBy: 100)->alignEnd(),
                TextColumn::make('outstanding_amount')->label('Por receber')->money('EUR', divideBy: 100)->alignEnd()
                    ->color(fn (float $state) => $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('overdue_amount')->label('Vencido')->money('EUR', divideBy: 100)->alignEnd()
                    ->color(fn (float $state) => $state > 0 ? 'danger' : 'gray'),
            ])
            ->recordActions([
                RecordPaymentAction::make()->iconButton()->tooltip('Registar recebimento'),
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                ]),
            ])
            ->emptyStateHeading('Sem clientes');
    }
}
