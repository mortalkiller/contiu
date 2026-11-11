<?php

namespace App\Filament\Resources\Suppliers\Tables;

use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\Expense;
use App\Models\RecurringExpense;
use App\Models\Supplier;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\Summarizers\Summarizer;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use pxlrbt\FilamentExcel\Columns\Column as ExportColumn;
use pxlrbt\FilamentExcel\Exports\ExcelExport;

class SuppliersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            // The row opens the supplier's own page; "Edit" stays on the row.
            ->recordUrl(fn (Supplier $record): string => SupplierResource::getUrl('view', ['record' => $record]))
            ->searchPlaceholder(__('Name or tax ID'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('Supplier'))
                    ->weight('medium')
                    ->description(fn (Supplier $record): ?string => static::nameLine($record))
                    ->searchable(['name', 'vat_number']),
                TextColumn::make('vat_number')
                    ->label(__('Tax ID'))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('website')
                    ->label(__('Website'))
                    ->placeholder('—')
                    ->url(fn (Supplier $record) => $record->website, shouldOpenInNewTab: true)
                    ->formatStateUsing(fn (?string $state) => $state ? preg_replace('#^https?://(www\.)?#', '', $state) : null)
                    ->color('primary')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('seat')
                    ->label(__('Location'))
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('usual_category')
                    ->label(__('Usual category'))
                    ->state(fn (Supplier $record): ?string => $record->expenses
                        ->countBy(fn (Expense $expense): string => $expense->category?->name ?? '')
                        ->forget('')
                        ->sortDesc()
                        ->keys()
                        ->first())
                    ->placeholder('—'),
                TextColumn::make('expenses_count')
                    ->label(__('Expenses'))
                    ->numeric()
                    ->alignEnd(),
                TextColumn::make('spent_this_year')
                    ->label(__('Total in :year', ['year' => today()->year]))
                    ->state(fn (Supplier $record): int => (int) static::thisYear($record)->sum(fn (Expense $expense): int => (int) $expense->total))
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->summarize(static::sumOf(fn (Supplier $supplier): int => (int) static::thisYear($supplier)->sum(fn (Expense $expense): int => (int) $expense->total))),
                TextColumn::make('unpaid')
                    ->label(__('To pay'))
                    ->state(fn (Supplier $record): ?int => static::unpaid($record) ?: null)
                    ->money('EUR', divideBy: 100)
                    ->placeholder('—')
                    ->color('warning')
                    ->weight('semibold')
                    ->alignEnd()
                    ->summarize(static::sumOf(fn (Supplier $supplier): int => static::unpaid($supplier))),
                TextColumn::make('last_expense_on')
                    ->label(__('Last expense'))
                    ->state(fn (Supplier $record) => $record->expenses->max('spent_on'))
                    ->date()
                    ->placeholder('—'),
            ])
            ->recordActions([
                EditAction::make()
                    ->button()
                    ->color('gray')
                    ->size('sm')
                    ->icon(null)
                    ->extraModalFooterActions(fn (EditAction $action): array => [
                        DeleteAction::make()
                            // The supplier being edited, read once the form is open.
                            ->record(fn (): ?Model => $action->getRecord())
                            ->visible(fn (?Supplier $record): bool => $record?->expenses_count === 0)
                            ->cancelParentActions(),
                    ]),
            ])
            ->emptyStateHeading(__('No suppliers'))
            ->emptyStateDescription(__('You can also create suppliers when recording an expense.'));
    }

    /** The Excel export of the list, as the table shows it, with plain numbers for the amounts. */
    public static function excelExport(): ExcelExport
    {
        return ExcelExport::make()
            ->fromTable()
            ->ignoreFormatting(fn (ExportColumn $column) => method_exists($column->tableColumn, 'isMoney') && $column->tableColumn->isMoney())
            ->withFilename(fn () => str(__('Suppliers'))->slug().'-'.now()->format('Y-m-d'));
    }

    /**
     * Under the name, as in the mock: "Recurring · annual" for a supplier paid on a schedule,
     * otherwise its tax ID when there is one. A seat outside Portugal follows, since it changes
     * how the expense's VAT is treated.
     */
    private static function nameLine(Supplier $supplier): ?string
    {
        $recurring = $supplier->recurringExpenses->first(fn (RecurringExpense $expense): bool => $expense->is_active);

        $parts = [
            match (true) {
                $recurring !== null => __('Recurring · :frequency', ['frequency' => mb_strtolower($recurring->frequency->getLabel())]),
                filled($supplier->vat_number) => __('Tax ID :number', ['number' => $supplier->vat_number]),
                default => null,
            },
            $supplier->seat?->isForeign() ? $supplier->seat->getLabel() : null,
        ];

        return implode(' · ', array_filter($parts)) ?: null;
    }

    /** @return Collection<int, Expense> */
    private static function thisYear(Supplier $supplier): Collection
    {
        return $supplier->expenses->filter(fn (Expense $expense): bool => $expense->spent_on?->year === today()->year)->values()->toBase();
    }

    private static function unpaid(Supplier $supplier): int
    {
        return (int) $supplier->expenses->reject(fn (Expense $expense): bool => (bool) $expense->is_paid)->sum(fn (Expense $expense): int => (int) $expense->total);
    }

    /**
     * The total of a computed amount over the suppliers the table shows, for the foot of the
     * table.
     *
     * @param  Closure(Supplier): int  $amount
     */
    private static function sumOf(Closure $amount): Summarizer
    {
        return Summarizer::make('sum')
            ->money('EUR', divideBy: 100)
            ->using(fn (QueryBuilder $query): int => (int) Supplier::query()
                ->whereIn('id', $query->pluck('suppliers.id'))
                ->with(['expenses'])
                ->get()
                ->sum($amount));
    }
}
