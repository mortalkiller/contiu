<?php

namespace App\Filament\Resources\Retainers\Schemas;

use App\Enums\Language;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RetainerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->defaultDateDisplayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
            ->defaultNumberLocale(fn (): string => app()->getLocale())
            ->components([
                Section::make(__('Details'))
                    ->columnSpanFull()
                    ->columns(4)
                    ->schema([
                        TextEntry::make('frequency')->label(__('Billing frequency')),
                        TextEntry::make('due_day')->label(__('Due date'))->formatStateUsing(fn ($state) => __('Day :day', ['day' => $state])),
                        TextEntry::make('starts_on')->label(__('Start'))->date(),
                        TextEntry::make('ends_on')->label(__('End'))->date()->placeholder(__('No end date')),
                        TextEntry::make('category.name')->label(__('Category'))->placeholder('—'),
                        TextEntry::make('net_amount')->label(__('Amount excluding VAT'))->money('EUR', divideBy: 100),
                        TextEntry::make('vat_rate')->label(__('VAT'))->formatStateUsing(fn ($state) => round((float) $state * 100).'%'),
                        TextEntry::make('direct_costs')->label(__('Direct costs'))->money('EUR', divideBy: 100),
                        TextEntry::make('notes')->label(__('Notes'))->placeholder('—')->columnSpanFull(),
                    ]),
            ]);
    }
}
