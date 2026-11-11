<?php

namespace App\Filament\Support;

use App\Enums\Language;
use App\Enums\ReconciliationStatus;
use App\Models\Expense;
use App\Models\Payment;
use App\Support\FinanceSettings;
use App\Support\Money;
use App\Support\Reconciliation;
use App\Support\ReconciliationResult;
use Filament\Actions\Action;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * "Banco" column and filter for payments and expenses: reconciled with the bank statement or not.
 * Worked out once per request for all records (App\Support\Reconciliation).
 */
final class ReconciliationColumns
{
    /** Only with a bank account for the activity: without one there is no statement to reconcile with. */
    public static function enabled(): bool
    {
        return app(FinanceSettings::class)->hasBusinessAccount();
    }

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
            ->label(__('Bank'))
            ->visible(fn (): bool => self::enabled())
            ->badge()
            ->state(fn (Payment $record) => self::reconciliation()->forPayment($record)->label())
            ->color(fn (Payment $record) => self::reconciliation()->forPayment($record)->status->color())
            ->description(fn (Payment $record) => self::reconciliation()->forPayment($record)->detail());
    }

    public static function expenseColumn(): TextColumn
    {
        return TextColumn::make('reconciliation')
            ->label(__('Bank'))
            ->visible(fn (): bool => self::enabled())
            ->badge()
            ->state(fn (Expense $record) => $record->is_paid ? self::reconciliation()->forExpense($record)->label() : null)
            ->color(fn (Expense $record) => self::reconciliation()->forExpense($record)->status->color())
            ->description(fn (Expense $record) => $record->is_paid ? self::reconciliation()->forExpense($record)->detail() : null)
            ->placeholder('—');
    }

    /** The result for a payment or (paid) expense. */
    public static function for(Payment|Expense $record): ReconciliationResult
    {
        return $record instanceof Payment ? self::reconciliation()->forPayment($record) : self::reconciliation()->forExpense($record);
    }

    /** "Ver no banco": the status and the statement lines behind a record. */
    public static function detailsAction(): Action
    {
        return Action::make('reconciliation')
            ->label(__('View in bank statement'))
            ->icon(Phosphor::Bank)
            ->visible(fn (Payment|Expense $record) => self::enabled() && ($record instanceof Payment || $record->is_paid))
            ->modalHeading(__('Bank reconciliation'))
            ->modalDescription(fn (Payment|Expense $record) => $record instanceof Payment
                ? __('Payment received :reference', ['reference' => $record->is_split ? $record->receivableLabels() : $record->receivable?->label]).' · '.$record->paid_on?->format(Language::resolve(app()->getLocale())->dateFormat()).' · '.Money::format($record->amount, locale: app()->getLocale())
                : __('Expense :description', ['description' => $record->description]).' · '.($record->paid_on ?? $record->spent_on)?->format(Language::resolve(app()->getLocale())->dateFormat()).' · '.Money::format($record->total, locale: app()->getLocale()))
            ->modalContent(fn (Payment|Expense $record) => view('filament.partials.reconciliation-details', ['result' => self::for($record), 'isExpense' => $record instanceof Expense]))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('Close'));
    }

    /** "Banco" section for the payment and expense forms (editing an existing, paid record). */
    public static function section(): Section
    {
        return Section::make(__('Bank'))
            ->icon(Phosphor::Bank)
            ->description(__('Shows whether it is reconciled with the statement and which lines cover it.'))
            ->columnSpanFull()
            ->schema([
                View::make('filament.partials.reconciliation-details')
                    ->viewData(fn (?Model $record) => ['result' => $record instanceof Payment || $record instanceof Expense ? self::for($record) : null, 'isExpense' => $record instanceof Expense]),
            ])
            ->visible(fn (?Model $record) => self::enabled() && $record?->exists && ($record instanceof Payment || ($record instanceof Expense && $record->is_paid)));
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
            ->label(__('Bank'))
            ->visible(fn (): bool => self::enabled())
            ->options(collect(ReconciliationStatus::cases())->mapWithKeys(fn (ReconciliationStatus $status) => [$status->value => $status->label()])->all())
            ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                ? $query->whereIn($query->getModel()->getQualifiedKeyName(), $ids(ReconciliationStatus::from($data['value'])))
                : $query);
    }
}
