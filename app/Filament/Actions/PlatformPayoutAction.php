<?php

namespace App\Filament\Actions;

use App\Enums\BankTransactionStatus;
use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Models\BankTransaction;
use App\Models\FiscalDocument;
use App\Models\Supplier;
use App\Support\CurrentAccount;
use App\Support\Money;
use App\Support\PlatformPayoutAmounts;
use Brick\Math\BigInteger;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\Width;
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class PlatformPayoutAction extends Action
{
    public static function getDefaultName(): ?string
    {
        return 'platformPayout';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Reconciliar depósito de plataforma')
            ->icon(Phosphor::Coins)
            ->color('gray')
            ->visible(fn (BankTransaction $record): bool => $record->status === BankTransactionStatus::Pending && $record->is_credit)
            ->disabled(fn (): bool => CurrentAccount::readOnly())
            ->modalHeading('Depósito de plataforma')
            ->modalDescription(fn (BankTransaction $record): string => $record->description.' · '.$record->booked_on->format('d/m/Y').' · '.Money::format($record->amount).' no banco')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitActionLabel('Reconciliar')
            ->schema(fn (BankTransaction $record): array => [
                Repeater::make('payouts')
                    ->label('Depósitos')
                    ->addActionLabel('Adicionar depósito')
                    ->defaultItems(1)
                    ->minItems(1)
                    ->maxItems(50)
                    ->reorderable(false)
                    ->columns(2)
                    ->schema([
                        Select::make('platform_id')->label('Plataforma')
                            ->options(fn () => Supplier::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()->required(),
                        TextInput::make('title')->label('Descrição')->required()->maxLength(255),
                        TextInput::make('reference')->label('Referência do depósito')->maxLength(255),
                        Select::make('currency')->label('Moeda original')
                            ->options(fn (): array => collect(iterator_to_array(new ISOCurrencies))
                                ->mapWithKeys(fn (Currency $currency): array => [$currency->getCode() => $currency->getCode()])->sortKeys()->all())
                            ->searchable()->default('EUR')->required()->live(),
                        TextInput::make('original_gross')->label('Valor bruto original')
                            ->inputMode('decimal')->required()->live(onBlur: true),
                        TextInput::make('original_commission')->label('Comissão original')
                            ->inputMode('decimal')->default('0')->required()->live(onBlur: true),
                        TextInput::make('fx_rate')->label('Taxa de conversão para EUR')
                            ->helperText('Euros por unidade da moeda original, conforme o comprovativo.')
                            ->inputMode('decimal')->required()->live(onBlur: true)
                            ->visible(fn (Get $get): bool => $get('currency') !== 'EUR'),
                        TextInput::make('fx_reference')->label('Referência do comprovativo da conversão')
                            ->maxLength(255)->required()
                            ->visible(fn (Get $get): bool => $get('currency') !== 'EUR'),
                        Checkbox::make('fx_confirmed')->label('Conferi a taxa no comprovativo da conversão')
                            ->accepted()->columnSpanFull()
                            ->visible(fn (Get $get): bool => $get('currency') !== 'EUR'),
                        Select::make('commission_document_id')->label('Documento da comissão')
                            ->options(fn (): array => FiscalDocument::query()
                                ->where('direction', FiscalDocumentDirection::Received)
                                ->where('status', FiscalDocumentStatus::Active)
                                ->orderByDesc('document_date')->get()
                                ->mapWithKeys(fn (FiscalDocument $document): array => [
                                    $document->id => $document->number.' · '.$document->document_date->format('d/m/Y'),
                                ])->all())
                            ->searchable(),
                        TextInput::make('invoice_number')->label('N.º da fatura-recibo')->maxLength(255),
                    ]),
                Text::make(fn (Get $get): ?string => self::preview($record->amount, $get('payouts') ?? [])),
            ]);
    }

    private static function preview(int $bankAmount, array $payouts): ?string
    {
        $remaining = BigInteger::of($bankAmount);

        foreach ($payouts as $payout) {
            try {
                $amounts = PlatformPayoutAmounts::from(PlatformPayoutAmounts::minorUnitsFromForm($payout));
            } catch (DomainException) {
                return null;
            }

            $remaining = $remaining->minus($amounts->netEur());
        }

        if ($remaining->isLessThan(PHP_INT_MIN) || $remaining->isGreaterThan(PHP_INT_MAX)) {
            return 'O líquido dos depósitos excede o valor da entrada no banco.';
        }

        return 'Diferença face ao banco: '.Money::format($remaining->toInt());
    }
}
