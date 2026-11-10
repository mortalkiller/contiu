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
            ->label('Aplicar crédito')
            ->requiresConfirmation()
            ->modalDescription(function (FiscalDocument $record): string {
                $number = $record->links?->relatedFiscalDocument?->number;

                return $number === null
                    ? 'Liga primeiro a nota à fatura original.'
                    : 'Abater '.Money::format(abs($record->total_amount)).' ao valor a receber da fatura '.$number.'. Esta operação não regista um recebimento.';
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
            ->successNotificationTitle('Crédito aplicado');
    }

    public static function reverse(): Action
    {
        return Action::make('reverseCredit')
            ->label('Reverter aplicação do crédito')
            ->modalDescription('O valor do crédito volta a ficar em aberto. O histórico da aplicação é conservado.')
            ->visible(fn (FiscalDocument $record): bool => self::canChange($record) && self::activeApplicationId($record) !== null)
            ->fillForm(fn (FiscalDocument $record): array => ['application_id' => self::activeApplicationId($record)])
            ->schema([
                Hidden::make('application_id')->required()->rule('integer'),
                Textarea::make('reason')->label('Motivo da reversão')->required()->maxLength(ManageCreditApplication::MAX_REASON_LENGTH),
            ])
            ->action(fn (FiscalDocument $record, array $data, Action $action) => self::run(
                $action, fn () => app(ManageCreditApplication::class)->reverse($record->id, (int) $data['application_id'], $data['reason']),
            ))
            ->successNotificationTitle('Aplicação do crédito revertida');
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
