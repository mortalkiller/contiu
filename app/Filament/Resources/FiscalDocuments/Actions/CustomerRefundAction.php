<?php

namespace App\Filament\Resources\FiscalDocuments\Actions;

use App\Actions\RecordCustomerRefund;
use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Support\MoneyInput;
use App\Models\BankTransaction;
use App\Models\CustomerRefund;
use App\Models\FiscalDocument;
use App\Support\CurrentAccount;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

final class CustomerRefundAction
{
    public static function make(): Action
    {
        return Action::make('recordCustomerRefund')
            ->label('Registar reembolso')
            ->modalDescription(function (FiscalDocument $record): string {
                $existing = CustomerRefund::query()->where('credit_note_id', $record->id)->orderBy('paid_on')->get()
                    ->map(fn (CustomerRefund $refund): string => $refund->reference.' · '.Money::format($refund->amount))->implode(', ');

                return 'Regista uma devolução que já fizeste ao cliente.'
                    .($existing === '' ? '' : ' Reembolsos registados: '.$existing.'.');
            })
            ->visible(fn (FiscalDocument $record): bool => $record->type === FiscalDocumentType::CreditNote
                && $record->direction === FiscalDocumentDirection::Issued
                && $record->status === FiscalDocumentStatus::Active && $record->currency === 'EUR'
                && $record->links?->related_fiscal_document_id !== null
                && ! CurrentAccount::readOnly() && FiscalDocumentResource::canEdit($record))
            ->fillForm(fn (FiscalDocument $record): array => [
                'paid_on' => today()->toDateString(),
                'amount' => max(0, abs($record->total_amount) - (int) CustomerRefund::query()->where('credit_note_id', $record->id)->sum('amount')),
            ])
            ->schema([
                DatePicker::make('paid_on')->label('Data do reembolso')->required()->maxDate(today()),
                MoneyInput::make('amount')->label('Valor devolvido')->required()->minValue(0.01)->rule('decimal:0,2'),
                TextInput::make('reference')->label('Referência do comprovativo')->required()->maxLength(120),
                Select::make('bank_transaction_id')->label('Movimento bancário')->searchable()
                    ->options(fn (): array => BankTransaction::query()->pending()->where('amount', '<', 0)
                        ->latest('booked_on')->latest('id')->limit(100)->get()->mapWithKeys(
                            fn (BankTransaction $line): array => [$line->id => $line->booked_on->format('d/m/Y').' · '.Money::format($line->absolute_amount).' · '.$line->description],
                        )->all()),
            ])
            ->action(function (FiscalDocument $record, array $data, Schema $schema): void {
                try {
                    app(RecordCustomerRefund::class)->handle($record->id, $data['amount'], $data['paid_on'], $data['reference'],
                        filled($data['bank_transaction_id'] ?? null) ? (int) $data['bank_transaction_id'] : null);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(Arr::prependKeysWith($exception->errors(), $schema->getStatePath().'.'));
                }
            })
            ->successNotificationTitle('Reembolso registado');
    }
}
