<?php

namespace App\Filament\Resources\Retainers\Schemas;

use App\Enums\Language;
use App\Models\Retainer;
use App\Support\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use MortalKiller\FilamentPageHeader\Components\Header;
use MortalKiller\FilamentPageHeader\Components\MetadataEntry;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class RetainerHeader
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->defaultDateDisplayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
            ->defaultNumberLocale(fn (): string => app()->getLocale())
            ->components([
                Header::make()
                    ->heading(fn (Retainer $record) => $record->code)
                    ->description(fn (Retainer $record) => $record->title)
                    ->icon(Phosphor::Repeat)
                    ->iconBgColor('primary')
                    ->badges([
                        TextEntry::make('frequency')->badge()->color('gray'),
                        TextEntry::make('is_active')
                            ->badge()
                            ->formatStateUsing(fn (bool $state) => $state ? __('Active retainer') : __('Ended'))
                            ->color(fn (bool $state) => $state ? 'success' : 'gray'),
                        TextEntry::make('tax_exempt')
                            ->badge()
                            ->visible(fn (Retainer $record) => $record->tax_exempt)
                            ->formatStateUsing(fn () => __('Tax-exempt retainer'))
                            ->color('info'),
                    ])
                    ->metadata([
                        MetadataEntry::make('client.name')->label(__('Client'))->fieldIcon(Phosphor::User),
                        MetadataEntry::make('business_line')->label(__('Business line'))->placeholder('—')->fieldIcon(Phosphor::Tag),
                        MetadataEntry::make('next_due_on')->label(__('Next due date'))->date()->placeholder('—')->fieldIcon(Phosphor::CalendarBlank),
                    ])
                    ->summary([
                        TextEntry::make('total')->label(__('Per period'))->money('EUR', divideBy: 100),
                        TextEntry::make('yearly_value')->label(__('Per year'))->money('EUR', divideBy: 100),
                        TextEntry::make('received_amount')->label(__('Received'))->money('EUR', divideBy: 100)->color('success'),
                        TextEntry::make('outstanding_amount')->label(__('Outstanding'))->money('EUR', divideBy: 100)
                            ->color(fn (float $state) => $state > 0 ? 'warning' : 'gray'),
                        TextEntry::make('margin')->label(__('Margin'))->money('EUR', divideBy: 100)
                            ->color(fn (float $state) => $state < 0 ? 'danger' : null)
                            ->tooltip(fn (Retainer $record) => __('Billed amount less direct costs (:amount)', ['amount' => Money::format($record->direct_costs, locale: app()->getLocale())])),
                    ]),
            ]);
    }
}
