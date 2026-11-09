<?php

namespace App\Filament\Support;

use App\Enums\Seat;
use App\Models\Project;
use App\Models\Retainer;
use App\Support\Money;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;

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
    public static function seat(string $label = 'Onde fica a sede da empresa?'): Select
    {
        return Select::make('seat')
            ->label($label)
            ->options(Seat::class)
            ->default(Seat::Portugal)
            ->required();
    }

    public static function isBusiness(): Toggle
    {
        return Toggle::make('is_business')
            ->label('É uma empresa?')
            ->helperText('Os serviços a empresas de outro país da UE entram na declaração recapitulativa.');
    }

    public static function clientSelect(): Select
    {
        return Select::make('client_id')
            ->label('Cliente')
            ->relationship('client', 'name')
            ->searchable()
            ->preload()
            ->required()
            ->createOptionForm([
                TextInput::make('name')->label('Nome')->required(),
                TextInput::make('vat_number')->label('NIF'),
                TextInput::make('email')->label('Email')->email(),
                self::seat('Onde fica o cliente?'),
                self::isBusiness(),
            ])
            ->createOptionModalHeading('Novo cliente');
    }

    public static function supplierSelect(): Select
    {
        return Select::make('supplier_id')
            ->label('Fornecedor')
            ->relationship('supplier', 'name')
            ->searchable()
            ->preload()
            ->required()
            ->createOptionForm([
                TextInput::make('name')->label('Nome')->required(),
                TextInput::make('vat_number')->label('NIF'),
                TextInput::make('website')->label('Site')->url(),
                self::seat(),
                Textarea::make('notes')->label('Notas')->rows(2),
            ])
            ->createOptionModalHeading('Novo fornecedor');
    }

    public static function netAmount(string $label = 'Valor sem IVA'): TextInput
    {
        return MoneyInput::make('net_amount')
            ->label($label)
            ->minValue(0)
            ->live(onBlur: true)
            ->required();
    }

    public static function vatRate(): ToggleButtons
    {
        return ToggleButtons::make('vat_rate')
            ->label('IVA')
            ->options(Money::vatOptions())
            ->default('0.0000')
            ->inline()
            ->grouped()
            ->live()
            ->required();
    }

    public static function totalPreview(): TextEntry
    {
        return TextEntry::make('total_preview')
            ->label('Total com IVA')
            ->state(function (Get $get): string {
                $net = Money::toCents($get('net_amount')) ?? 0;

                return Money::format($net + Money::vat($net, (string) ($get('vat_rate') ?? 0)));
            })
            ->size(TextSize::Large)
            ->weight(FontWeight::Bold);
    }
}
