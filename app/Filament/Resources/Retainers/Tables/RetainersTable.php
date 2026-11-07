<?php

namespace App\Filament\Resources\Retainers\Tables;

use App\Enums\Frequency;
use App\Filament\Actions\RecordPaymentAction;
use App\Models\Retainer;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use pxlrbt\FilamentExcel\Actions\Tables\ExportAction;
use pxlrbt\FilamentExcel\Actions\Tables\ExportBulkAction;
use pxlrbt\FilamentExcel\Columns\Column as ExportColumn;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

class RetainersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->headerActions([
                ExportAction::make()
                    ->label('Exportar Excel')
                    ->exports([
                        ExcelExport::make()->fromTable()->ignoreFormatting(fn (ExportColumn $column) => method_exists($column->tableColumn, 'isMoney') && $column->tableColumn->isMoney())->withFilename(fn () => 'avencas-'.now()->format('Y-m-d')),
                    ]),
            ])
            ->toolbarActions([
                ExportBulkAction::make()
                    ->label('Exportar seleção')
                    ->exports([
                        ExcelExport::make()->fromTable()->ignoreFormatting(fn (ExportColumn $column) => method_exists($column->tableColumn, 'isMoney') && $column->tableColumn->isMoney())->withFilename(fn () => 'avencas-'.now()->format('Y-m-d')),
                    ]),
            ])
            ->defaultSort('starts_on', 'desc')
            ->columns([
                TextColumn::make('code')
                    ->label('Avença')
                    ->description(fn (Retainer $record) => $record->title)
                    ->weight('medium')
                    ->searchable(['code', 'title']),
                TextColumn::make('client.name')
                    ->label('Cliente')
                    ->searchable()
                    ->limit(28),
                TextColumn::make('frequency')
                    ->label('Frequência')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('total')
                    ->label('Valor')
                    ->money('EUR', divideBy: 100)
                    ->alignEnd(),
                TextColumn::make('yearly_value')
                    ->label('Por ano')
                    ->money('EUR', divideBy: 100)
                    ->alignEnd(),
                TextColumn::make('next_due_on')
                    ->label('Próximo vencimento')
                    ->date()
                    ->placeholder('—'),
                TextColumn::make('outstanding_amount')
                    ->label('Em aberto')
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->color(fn (float $state) => $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('margin')
                    ->label('Margem')
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->color(fn (float $state) => $state < 0 ? 'danger' : null)
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label('Ativa')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('client')->label('Cliente')->relationship('client', 'name')->preload(),
                SelectFilter::make('frequency')->label('Frequência')->options(Frequency::class),
            ])
            ->recordActions([
                RecordPaymentAction::make()->iconButton()->tooltip('Registar recebimento'),
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                ]),
            ])
            ->emptyStateHeading('Sem avenças')
            ->emptyStateDescription('Cria uma avença para valores que se repetem, como manutenção ou alojamento.');
    }
}
