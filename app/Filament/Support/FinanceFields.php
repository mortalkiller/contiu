<?php

namespace App\Filament\Support;

use App\Enums\Seat;
use App\Models\Project;
use App\Models\Retainer;
use App\Support\Money;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Illuminate\Support\HtmlString;

/**
 * Form fields shared by projects, retainers, expenses, recurring expenses, clients and suppliers.
 */
final class FinanceFields
{
    /**
     * Business lines already used in this account (projects and retainers), as suggestions.
     *
     * @return array<int, string>
     */
    public static function businessLines(): array
    {
        return Project::query()->pluck('business_line')
            ->merge(Retainer::query()->pluck('business_line'))
            ->filter(fn ($line) => filled($line))
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** Where a supplier (or client) is seated: decides the VAT on what is bought from or sold to it. */
    public static function seat(?string $label = null): Select
    {
        return TaxSettingsFields::whereToFind(
            Select::make('seat')
                ->label($label ?? __('Where is the company registered?'))
                ->options(Seat::class)
                ->default(Seat::Portugal)
                ->required(),
            __('Check the company address and VAT number on the invoice. VAT numbers starting with IE, FR, DE or ES are from another EU country. Companies in the US or UK are outside the EU.'),
        );
    }

    public static function isBusiness(): Toggle
    {
        return TaxSettingsFields::whereToFind(
            Toggle::make('is_business')
                ->label(__('Is it a business?'))
                ->helperText(__('Services to businesses in another EU country go on the recapitulative statement.')),
            __('A business has a VAT number and asks for it on the invoice. An individual does not.'),
        );
    }

    /** The client of a record, with "+" to create one from its full form (QuickCreate). */
    public static function clientSelect(): Select
    {
        return QuickCreate::client(Select::make('client_id')
            ->label(__('Client'))
            ->relationship('client', 'name')
            ->searchable()
            ->preload()
            ->required());
    }

    /** The supplier of a record, with "+" to create one from its full form (QuickCreate). */
    public static function supplierSelect(): Select
    {
        return QuickCreate::supplier(Select::make('supplier_id')
            ->label(__('Supplier'))
            ->relationship('supplier', 'name')
            ->searchable()
            ->preload()
            ->required());
    }

    public static function netAmount(?string $label = null): TextInput
    {
        return MoneyInput::make('net_amount')
            ->label($label ?? __('Amount excluding VAT'))
            ->minValue(0)
            ->required();
    }

    public static function vatRate(): ToggleButtons
    {
        return ToggleButtons::make('vat_rate')
            ->label(__('VAT'))
            ->options(Money::vatOptions())
            ->default('0.0000')
            ->inline()
            ->grouped()
            ->required();
    }

    public static function totalPreview(): TextEntry
    {
        return TextEntry::make('total_preview')
            ->label(__('Total including VAT'))
            ->state(function (Get $get): HtmlString {
                $net = Money::toCents($get('net_amount')) ?? 0;

                return self::preview('total', $net + Money::vat($net, (string) ($get('vat_rate') ?? 0)));
            })
            ->size(TextSize::Large)
            ->weight(FontWeight::Bold);
    }

    /** @param array<string, string> $messages */
    public static function preview(string $mode, int|string $initial, array $messages = []): HtmlString
    {
        return new HtmlString(view('filament.forms.finance-preview', [
            'mode' => $mode,
            'initial' => is_int($initial) ? Money::format($initial) : $initial,
            'messages' => $messages,
        ])->render());
    }
}
