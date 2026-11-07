<?php

namespace App\Filament\Support;

use App\Enums\ReconciliationStatus;
use App\Models\Expense;
use App\Models\Payment;
use App\Support\Reconciliation;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Banco" column and filter for payments and expenses: reconciled with the bank statement or not.
 * Worked out once per request for all records (App\Support\Reconciliation).
 */
final class ReconciliationColumns
{
    public static function reconciliation(): Reconciliation
    {
        if (! app()->bound(Reconciliation::class.'@request')) {
            app()->instance(Reconciliation::class.'@request', new Reconciliation);
        }

        return app(Reconciliation::class.'@request');
    }

    public static function paymentColumn(): TextColumn
    {
        return TextColumn::make('reconciliation')
            ->label('Banco')
            ->badge()
            ->state(fn (Payment $record) => self::reconciliation()->forPayment($record)->label())
            ->color(fn (Payment $record) => self::reconciliation()->forPayment($record)->status->color())
            ->description(fn (Payment $record) => self::reconciliation()->forPayment($record)->detail());
    }

    public static function expenseColumn(): TextColumn
    {
        return TextColumn::make('reconciliation')
            ->label('Banco')
            ->badge()
            ->state(fn (Expense $record) => $record->is_paid ? self::reconciliation()->forExpense($record)->label() : null)
            ->color(fn (Expense $record) => self::reconciliation()->forExpense($record)->status->color())
            ->description(fn (Expense $record) => $record->is_paid ? self::reconciliation()->forExpense($record)->detail() : null)
            ->placeholder('—');
    }

    public static function paymentFilter(): SelectFilter
    {
        return self::filter(fn (ReconciliationStatus $status) => self::reconciliation()->paymentIds($status));
    }

    public static function expenseFilter(): SelectFilter
    {
        return self::filter(fn (ReconciliationStatus $status) => self::reconciliation()->expenseIds($status));
    }

    private static function filter(callable $ids): SelectFilter
    {
        return SelectFilter::make('reconciliation')
            ->label('Banco')
            ->options(collect(ReconciliationStatus::cases())->mapWithKeys(fn (ReconciliationStatus $status) => [$status->value => $status->label()])->all())
            ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                ? $query->whereIn($query->getModel()->getQualifiedKeyName(), $ids(ReconciliationStatus::from($data['value'])))
                : $query);
    }
}
