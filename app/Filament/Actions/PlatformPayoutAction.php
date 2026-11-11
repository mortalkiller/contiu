<?php

namespace App\Filament\Actions;

use App\Enums\BankTransactionStatus;
use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\Language;
use App\Filament\Support\QuickCreate;
use App\Models\BankTransaction;
use App\Models\FiscalDocument;
use App\Models\Supplier;
use App\Support\CurrentAccount;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\View;
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

        $foreignCurrency = fn (Get $get): bool => $get('currency') !== 'EUR';
        $foreignCurrencyJs = "\$get('currency') !== 'EUR'";

        $this->label(__('Reconcile platform payout'))
            ->icon(Phosphor::Coins)
            ->color('gray')
            ->visible(fn (BankTransaction $record): bool => $record->status === BankTransactionStatus::Pending && $record->is_credit)
            ->disabled(fn (): bool => CurrentAccount::readOnly())
            ->modalHeading(__('Platform payout'))
            ->modalDescription(fn (BankTransaction $record): string => __(':description · :date · :amount in the bank', [
                'description' => $record->description,
                'date' => $record->booked_on->format(Language::resolve(app()->getLocale())->dateFormat()),
                'amount' => Money::format($record->amount),
            ]))
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitActionLabel(__('Reconcile'))
            ->schema(fn (BankTransaction $record): array => [
                Repeater::make('payouts')
                    ->label(__('Payouts'))
                    ->addActionLabel(__('Add payout'))
                    ->defaultItems(1)
                    ->minItems(1)
                    ->maxItems(50)
                    ->reorderable(false)
                    ->columns(2)
                    ->schema([
                        QuickCreate::supplier(Select::make('platform_id')->label(__('Platform'))
                            ->options(fn () => Supplier::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()->required()),
                        TextInput::make('title')->label(__('Description'))->required()->maxLength(255),
                        TextInput::make('reference')->label(__('Payout reference'))->maxLength(255),
                        Select::make('currency')->label(__('Original currency'))
                            ->options(fn (): array => collect(iterator_to_array(new ISOCurrencies))
                                ->mapWithKeys(fn (Currency $currency): array => [$currency->getCode() => $currency->getCode()])->sortKeys()->all())
                            ->searchable()->dynamicOptions(false)->default('EUR')->required(),
                        TextInput::make('original_gross')->label(__('Original gross amount'))
                            ->inputMode('decimal')->required(),
                        TextInput::make('original_commission')->label(__('Original fee'))
                            ->inputMode('decimal')->default('0')->required(),
                        TextInput::make('fx_rate')->label(__('Exchange rate to EUR'))
                            ->helperText(__('Euros per unit of the original currency, as shown on the supporting document.'))
                            ->inputMode('decimal')->required($foreignCurrency)
                            ->dehydrated($foreignCurrency)
                            ->visibleJs($foreignCurrencyJs),
                        TextInput::make('fx_reference')->label(__('Exchange rate document reference'))
                            ->maxLength(255)->required($foreignCurrency)
                            ->dehydrated($foreignCurrency)
                            ->visibleJs($foreignCurrencyJs),
                        Checkbox::make('fx_confirmed')->label(__('I checked the rate on the supporting document'))
                            ->accepted($foreignCurrency)->columnSpanFull()
                            ->dehydrated($foreignCurrency)
                            ->visibleJs($foreignCurrencyJs),
                        Select::make('commission_document_id')->label(__('Fee document'))
                            ->options(fn (): array => FiscalDocument::query()
                                ->where('direction', FiscalDocumentDirection::Received)
                                ->where('status', FiscalDocumentStatus::Active)
                                ->orderByDesc('document_date')->get()
                                ->mapWithKeys(fn (FiscalDocument $document): array => [
                                    $document->id => $document->number.' · '.$document->document_date->format(Language::resolve(app()->getLocale())->dateFormat()),
                                ])->all())
                            ->searchable(),
                        TextInput::make('invoice_number')->label(__('Invoice-receipt number'))->maxLength(255),
                    ]),
                View::make('filament.actions.contiu-platform-payout-preview')
                    ->viewData(function () use ($record): array {
                        $currencies = new ISOCurrencies;

                        return [
                            'amount' => (string) $record->amount,
                            'locale' => Language::resolve(app()->getLocale())->value,
                            'scales' => collect($currencies)->mapWithKeys(fn (Currency $currency): array => [
                                $currency->getCode() => $currencies->subunitFor($currency),
                            ])->all(),
                        ];
                    }),
            ]);
    }
}
