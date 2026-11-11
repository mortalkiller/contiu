<?php

namespace App\Filament\Resources\Retainers\Tables;

use App\Enums\Frequency;
use App\Enums\Language;
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
            ->defaultDateDisplayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
            ->defaultNumberLocale(fn (): string => app()->getLocale())
            ->headerActions([
                ExportAction::make()
                    ->label(__('Export to Excel'))
                    ->exports([
                        ExcelExport::make()->fromTable()->ignoreFormatting(fn (ExportColumn $column) => method_exists($column->tableColumn, 'isMoney') && $column->tableColumn->isMoney())->withFilename(fn () => str(__('Retainers'))->slug().'-'.now()->format('Y-m-d')),
                    ]),
            ])
            ->toolbarActions([
                ExportBulkAction::make()
                    ->label(__('Export selected'))
                    ->exports([
                        ExcelExport::make()->fromTable()->ignoreFormatting(fn (ExportColumn $column) => method_exists($column->tableColumn, 'isMoney') && $column->tableColumn->isMoney())->withFilename(fn () => str(__('Retainers'))->slug().'-'.now()->format('Y-m-d')),
                    ]),
            ])
            ->defaultSort('starts_on', 'desc')
            ->columns([
                TextColumn::make('code')
                    ->label(fn (): string => __('Retainer'))
                    ->description(fn (Retainer $record) => $record->title)
                    ->weight('medium')
                    ->searchable(['code', 'title']),
                TextColumn::make('client.name')
                    ->label(fn (): string => __('Client'))
                    ->searchable()
                    ->limit(28),
                TextColumn::make('frequency')
                    ->label(fn (): string => __('Billing frequency'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('total')
                    ->label(fn (): string => __('Amount'))
                    ->money('EUR', divideBy: 100)
                    ->alignEnd(),
                TextColumn::make('yearly_value')
                    ->label(fn (): string => __('Per year'))
                    ->money('EUR', divideBy: 100)
                    ->alignEnd(),
                TextColumn::make('next_due_on')
                    ->label(fn (): string => __('Next due date'))
                    ->date()
                    ->placeholder('—'),
                TextColumn::make('outstanding_amount')
                    ->label(fn (): string => __('Outstanding'))
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->color(fn (float $state) => $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('margin')
                    ->label(fn (): string => __('Margin'))
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->color(fn (float $state) => $state < 0 ? 'danger' : null)
                    ->toggleable(),
                IconColumn::make('is_active')
                    ->label(fn (): string => __('Active retainer'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('client')->label(__('Client'))->relationship('client', 'name')->preload(),
                SelectFilter::make('frequency')->label(__('Billing frequency'))->options(Frequency::class),
            ])
            ->recordActions([
                RecordPaymentAction::make()->iconButton()->tooltip(__('Record payment')),
                ActionGroup::make([
                    ViewAction::make(),
                    EditAction::make(),
                ]),
            ])
            ->emptyStateHeading(__('No retainers'))
            ->emptyStateDescription(__('Create a retainer for recurring charges such as maintenance or hosting.'));
    }
}
