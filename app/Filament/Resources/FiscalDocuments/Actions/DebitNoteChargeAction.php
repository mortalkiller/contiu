<?php

namespace App\Filament\Resources\FiscalDocuments\Actions;

use App\Actions\RecordDebitNoteCharge;
use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Models\FiscalDocument;
use App\Support\AccountantAccess;
use App\Support\CurrentAccount;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;

final class DebitNoteChargeAction
{
    public static function make(): Action
    {
        return Action::make('recordDebitNoteCharge')
            ->label(__('Create receivable'))
            ->modalDescription(fn (FiscalDocument $record): string => __('Create a charge of :amount for note :number.', ['amount' => Money::format($record->total_amount, locale: app()->getLocale()), 'number' => $record->number]))
            ->visible(fn (FiscalDocument $record): bool => $record->type === FiscalDocumentType::DebitNote
                && $record->direction === FiscalDocumentDirection::Issued
                && $record->status === FiscalDocumentStatus::Active
                && $record->currency === 'EUR' && $record->links?->related_fiscal_document_id !== null
                && $record->links->receivable_id === null
                && ! CurrentAccount::readOnly()
                && AccountantAccess::canManage(CurrentAccount::get())
                && FiscalDocumentResource::canEdit($record))
            ->fillForm(fn (FiscalDocument $record): array => [
                'total' => $record->total_amount,
                'original' => $record->links?->related_fiscal_document_id,
                'due_on' => today()->toDateString(),
            ])
            ->schema([
                DatePicker::make('due_on')->label(__('Due date'))->required()->minDate(fn (FiscalDocument $record) => $record->document_date),
                Hidden::make('total')->required()->rule('integer'),
                Hidden::make('original')->required()->rule('integer'),
            ])
            ->action(fn (FiscalDocument $record, array $data) => app(RecordDebitNoteCharge::class)->handle(
                $record->id,
                $data['due_on'],
                (int) $data['total'],
                (int) $data['original'],
            ))
            ->successNotificationTitle(__('Receivable created'));
    }
}
