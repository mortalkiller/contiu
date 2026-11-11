<?php

namespace App\Filament\Resources\FiscalDocuments\Actions;

use App\Actions\ManageCreditApplication;
use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Models\CreditApplication;
use App\Models\FiscalDocument;
use App\Support\CurrentAccount;
use App\Support\Money;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Textarea;
use Illuminate\Validation\ValidationException;

final class CreditApplicationActions
{
    public static function apply(): Action
    {
        return Action::make('applyCredit')
            ->label(__('Apply credit'))
            ->requiresConfirmation()
            ->modalDescription(function (FiscalDocument $record): string {
                $number = $record->links?->relatedFiscalDocument?->number;

                return $number === null
                    ? __('Link the credit note to the original invoice first.')
                    : __('Deduct :amount from the amount due on invoice :number. This does not record a payment.', ['amount' => Money::format(abs($record->total_amount), locale: app()->getLocale()), 'number' => $number]);
            })
            ->visible(fn (FiscalDocument $record): bool => $record->type === FiscalDocumentType::CreditNote
                && $record->direction === FiscalDocumentDirection::Issued
                && $record->status === FiscalDocumentStatus::Active
                && $record->currency === 'EUR'
                && self::canChange($record)
                && self::activeApplicationId($record) === null)
            ->fillForm(function (FiscalDocument $record): array {
                $invoice = $record->links?->relatedFiscalDocument;

                return [
                    'total' => $record->total_amount,
                    'invoice_id' => $invoice?->id,
                    'receivable_id' => $invoice?->links?->receivable_id,
                ];
            })
            ->schema([
                Hidden::make('total')->required()->rule('integer'),
                Hidden::make('invoice_id')->rule('integer'),
                Hidden::make('receivable_id')->rule('integer'),
            ])
            ->action(fn (FiscalDocument $record, array $data, Action $action) => self::run(
                $action, fn () => app(ManageCreditApplication::class)->apply($record->id, [
                    'total' => (int) $data['total'],
                    'invoice_id' => (int) $data['invoice_id'],
                    'receivable_id' => (int) $data['receivable_id'],
                ]),
            ))
            ->successNotificationTitle(__('Credit applied'));
    }

    public static function reverse(): Action
    {
        return Action::make('reverseCredit')
            ->label(__('Reverse credit application'))
            ->modalDescription(__('The credit amount becomes available again. The application history is kept.'))
            ->visible(fn (FiscalDocument $record): bool => self::canChange($record) && self::activeApplicationId($record) !== null)
            ->fillForm(fn (FiscalDocument $record): array => ['application_id' => self::activeApplicationId($record)])
            ->schema([
                Hidden::make('application_id')->required()->rule('integer'),
                Textarea::make('reason')->label(__('Reversal reason'))->required()->maxLength(ManageCreditApplication::MAX_REASON_LENGTH),
            ])
            ->action(fn (FiscalDocument $record, array $data, Action $action) => self::run(
                $action, fn () => app(ManageCreditApplication::class)->reverse($record->id, (int) $data['application_id'], $data['reason']),
            ))
            ->successNotificationTitle(__('Credit application reversed'));
    }

    private static function canChange(FiscalDocument $record): bool
    {
        return ! CurrentAccount::readOnly() && FiscalDocumentResource::canEdit($record);
    }

    private static function activeApplicationId(FiscalDocument $record): ?int
    {
        return CreditApplication::query()->where('credit_note_id', $record->id)->whereNull('reversed_on')->value('id');
    }

    private static function run(Action $action, Closure $callback): void
    {
        try {
            $callback();
        } catch (ValidationException $exception) {
            $action->failureNotificationTitle($exception->getMessage());
            $action->failure();
            $action->halt();
        }
    }
}
