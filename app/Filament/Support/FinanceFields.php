<?php

namespace App\Filament\Support;

use App\Support\Money;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;

/**
 * Form fields shared by projects, retainers, expenses and recurring expenses.
 */
final class FinanceFields
{
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
