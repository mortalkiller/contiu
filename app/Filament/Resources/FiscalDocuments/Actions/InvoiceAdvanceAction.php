<?php

namespace App\Filament\Resources\FiscalDocuments\Actions;

use App\Actions\LinkInvoiceAdvance;
use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Models\FiscalDocument;
use App\Support\AccountantAccess;
use App\Support\CurrentAccount;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;

final class InvoiceAdvanceAction
{
    public static function make(): Action
    {
        return Action::make('linkInvoiceAdvance')
            ->label('Ligar adiantamento')
            ->modalDescription('A fatura final deve mencionar a fatura do adiantamento e incluir apenas o valor após a dedução.')
            ->visible(fn (FiscalDocument $record): bool => $record->type === FiscalDocumentType::Invoice
                && $record->direction === FiscalDocumentDirection::Issued
                && $record->status === FiscalDocumentStatus::Active
                && $record->currency === 'EUR'
                && $record->links?->related_fiscal_document_id === null
                && $record->links?->receivable_id !== null
                && ! CurrentAccount::readOnly()
                && AccountantAccess::canManage(CurrentAccount::get())
                && FiscalDocumentResource::canEdit($record)
                && self::advances($record)->exists())
            ->schema([
                Select::make('advance')->label('Fatura do adiantamento')->required()->searchable()
                    ->options(fn (FiscalDocument $record): array => self::advances($record)->orderByDesc('document_date')->get()
                        ->mapWithKeys(fn (FiscalDocument $advance): array => [
                            $advance->id => $advance->number.' · '.Money::format($advance->total_amount),
                        ])->all()),
            ])
            ->action(fn (FiscalDocument $record, array $data) => app(LinkInvoiceAdvance::class)->handle($record->id, (int) $data['advance']))
            ->successNotificationTitle('Adiantamento ligado à fatura final');
    }

    /** @return Builder<FiscalDocument> */
    private static function advances(FiscalDocument $final): Builder
    {
        return FiscalDocument::query()->whereKeyNot($final->id)
            ->whereIn('type', [FiscalDocumentType::Invoice, FiscalDocumentType::InvoiceReceipt])
            ->where('direction', FiscalDocumentDirection::Issued)
            ->where('status', FiscalDocumentStatus::Active)
            ->where('currency', 'EUR')
            ->whereDate('document_date', '<=', $final->document_date->toDateString())
            ->whereHas('links.payment', fn (Builder $query): Builder => $query->whereNotNull('advance_client_id')
                ->whereHas('allocations', fn (Builder $allocations): Builder => $allocations->where('receivable_id', $final->links?->receivable_id)));
    }
}
