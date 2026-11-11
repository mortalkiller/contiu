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
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
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

    /** The same choice as seat(), as the three buttons of the supplier form. */
    public static function seatChoice(?string $label = null): ToggleButtons
    {
        return TaxSettingsFields::whereToFind(
            ToggleButtons::make('seat')
                ->label($label ?? __('Where is the company registered?'))
                ->options(Seat::class)
                ->default(Seat::Portugal)
                ->inline()
                ->grouped()
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

    /**
     * Amount, VAT and total, for a block of three columns as in the mocks. With more rates than
     * fit beside the amount (the autonomous regions have nine), the rates take two columns and
     * the total goes under them.
     *
     * @return list<Component>
     */
    public static function amountFields(): array
    {
        $manyRates = count(Money::vatOptions()) > 5;

        return [
            self::netAmount(),
            self::vatRate()->columnSpan(['default' => 1, 'sm' => $manyRates ? 2 : 1]),
            self::totalPreview()->alignEnd()->columnSpan(['default' => 1, 'sm' => $manyRates ? 3 : 1]),
        ];
    }

    /** The total with VAT, in cents, of an amount and a rate as typed in the form. */
    public static function totalCents(mixed $netAmount, mixed $vatRate): int
    {
        $net = Money::toCents($netAmount) ?? 0;

        return $net + Money::vat($net, (string) ($vatRate ?? 0));
    }

    public static function totalPreview(): TextEntry
    {
        return TextEntry::make('total_preview')
            ->label(__('Total including VAT'))
            ->state(fn (Get $get): HtmlString => self::preview('total', self::totalCents($get('net_amount'), $get('vat_rate'))))
            ->size(TextSize::Large)
            ->weight(FontWeight::Bold);
    }

    /**
     * "Resumo", beside the blocks of a page form and in view while scrolling, as in the mocks:
     * key/value lines (filament-appearance) that follow what is typed.
     *
     * @param  array<int, Component>  $components
     */
    public static function summary(array $components): Section
    {
        return Section::make(__('Summary'))
            ->columnSpan(1)
            ->inlineLabel()
            ->extraAttributes(['class' => 'fap-key-value lg:sticky lg:top-20'])
            ->schema($components);
    }

    /**
     * The amount excluding VAT, the VAT at its rate and the total, as typed in net_amount and
     * vat_rate.
     *
     * @return list<TextEntry>
     */
    public static function amountLines(): array
    {
        $net = fn (Get $get): int => Money::toCents($get('net_amount')) ?? 0;
        $vat = fn (Get $get): int => Money::vat($net($get), (string) ($get('vat_rate') ?? 0));

        return [
            TextEntry::make('summary_net')
                ->label(__('Amount excluding VAT'))
                ->state(fn (Get $get): HtmlString => self::preview('net', $net($get))),
            TextEntry::make('summary_vat')
                ->label(fn (Get $get): HtmlString => self::preview(
                    'vatLabel',
                    __('VAT at :rate%', ['rate' => (int) round((float) ($get('vat_rate') ?? 0) * 100)]),
                    ['rate' => __('VAT at :rate%')],
                ))
                ->state(fn (Get $get): HtmlString => self::preview('vat', $vat($get))),
            TextEntry::make('summary_total')
                ->label(__('Total'))
                ->state(fn (Get $get): HtmlString => self::preview('total', $net($get) + $vat($get)))
                ->weight(FontWeight::SemiBold),
        ];
    }

    /**
     * A value worked out in the browser as the form is typed (finance-preview.js), starting from
     * what the server computed. `field` is the field a "choice" preview reads.
     *
     * @param  array<string, string>  $messages
     */
    public static function preview(string $mode, int|string $initial, array $messages = [], ?string $field = null): HtmlString
    {
        return new HtmlString(view('filament.forms.finance-preview', [
            'mode' => $mode,
            'initial' => is_int($initial) ? Money::format($initial) : $initial,
            'messages' => $messages,
            'field' => $field,
        ])->render());
    }
}
