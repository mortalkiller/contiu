<?php

namespace App\Filament\Resources\Retainers\Tables;

use App\Enums\Frequency;
use App\Enums\Language;
use App\Filament\Actions\RecordPaymentAction;
use App\Filament\Support\OutstandingColumn;
use App\Models\Retainer;
use App\Support\RelativeDue;
use Filament\Support\Enums\FontWeight;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use MortalKiller\FilamentAppearance\Tables\Columns\ProgressColumn;
use pxlrbt\FilamentExcel\Columns\Column as ExportColumn;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

/**
 * "Avenças" as in the mock: the title with since when and on which day it falls due, the client,
 * how often, the amount and what it makes in a year, the next due date with how far off it is,
 * what is open, the margin as a bar, and "Receive" on one with something open. The export sits in
 * the page header (ListRetainers).
 */
class RetainersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultDateDisplayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
            ->defaultNumberLocale(fn (): string => app()->getLocale())
            ->defaultSort('starts_on', 'desc')
            ->searchPlaceholder(__('Retainer or client'))
            ->columns([
                TextColumn::make('title')
                    ->label(fn (): string => __('Retainer'))
                    ->description(fn (Retainer $record): string => __('Since :date · day :day', [
                        'date' => $record->starts_on->format(Language::resolve(app()->getLocale())->dateFormat()),
                        'day' => $record->due_day,
                    ]))
                    ->weight(FontWeight::Medium)
                    ->wrap()
                    ->searchable(['code', 'title']),
                TextColumn::make('client.name')
                    ->label(fn (): string => __('Client'))
                    ->searchable()
                    ->limit(24),
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
                    ->description(fn (Retainer $record): ?string => $record->next_due_on === null ? null : RelativeDue::describe($record->next_due_on))
                    ->placeholder('—'),
                OutstandingColumn::make(),
                ProgressColumn::make('margin')
                    ->label(fn (): string => __('Margin'))
                    ->state(fn (Retainer $record): ?float => $record->billed_amount > 0 ? $record->margin / $record->billed_amount * 100 : null)
                    ->placeholder('—')
                    ->precision(0)
                    ->color(fn (?float $state): string => $state !== null && $state < 0 ? 'danger' : 'primary'),
            ])
            ->filters([
                SelectFilter::make('client')->label(__('Client'))->relationship('client', 'name')->preload(),
                SelectFilter::make('frequency')->label(__('Billing frequency'))->options(Frequency::class),
            ])
            ->recordActions([
                RecordPaymentAction::rowButton(),
            ])
            ->emptyStateHeading(__('No retainers'))
            ->emptyStateDescription(__('Create a retainer for recurring charges such as maintenance or hosting.'));
    }

    /** The Excel export of the list, as the table shows it, with plain numbers for the amounts. */
    public static function excelExport(): ExcelExport
    {
        return ExcelExport::make()
            ->fromTable()
            ->ignoreFormatting(fn (ExportColumn $column) => method_exists($column->tableColumn, 'isMoney') && $column->tableColumn->isMoney())
            ->except(['margin'])
            ->withFilename(fn () => str(__('Retainers'))->slug().'-'.now()->format('Y-m-d'));
    }
}
