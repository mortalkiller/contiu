<?php

namespace App\Filament\Resources\RecurringExpenses\RelationManagers;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Suppliers\RelationManagers\SupplierExpensesRelationManager;
use App\Models\Expense;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The expenses a recurring expense created, newest first. It is the supplier's table of expenses
 * (same columns, year filter, total and "mark as paid") over this relation: only the month under
 * each description, where a row leads and the words of the empty table are its own.
 */
class GeneratedExpensesRelationManager extends SupplierExpensesRelationManager
{
    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('Generated expenses');
    }

    public function table(Table $table): Table
    {
        $table = parent::table($table)
            // The only table of the page: there are no tabs to name it.
            ->heading(__('Generated expenses'))
            ->recordUrl(fn (Expense $record): ?string => static::expenseUrl($record))
            ->emptyStateDescription(__('The expenses this recurring expense creates show up here.'));

        // Every row has the template's description and category: the month tells them apart.
        $table->getColumn('description')
            ?->description(fn (Expense $record): string => Str::ucfirst($record->spent_on->locale(app()->getLocale())->translatedFormat('F Y')));

        return $table;
    }

    /** The expense's own page once it has one, its form until then. */
    private static function expenseUrl(Expense $record): ?string
    {
        return match (true) {
            ExpenseResource::hasPage('view') && ExpenseResource::canView($record) => ExpenseResource::getUrl('view', ['record' => $record]),
            ExpenseResource::canEdit($record) => ExpenseResource::getUrl('edit', ['record' => $record]),
            default => null,
        };
    }
}
