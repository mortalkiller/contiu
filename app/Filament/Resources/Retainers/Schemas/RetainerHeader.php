<?php

namespace App\Filament\Resources\Retainers\Schemas;

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
        return $schema->components([
            Header::make()
                ->heading(fn (Retainer $record) => $record->code)
                ->description(fn (Retainer $record) => $record->title)
                ->icon(Phosphor::Repeat)
                ->iconBgColor('primary')
                ->badges([
                    TextEntry::make('frequency')->badge()->color('gray'),
                    TextEntry::make('is_active')
                        ->badge()
                        ->formatStateUsing(fn (bool $state) => $state ? 'Ativa' : 'Terminada')
                        ->color(fn (bool $state) => $state ? 'success' : 'gray'),
                    TextEntry::make('tax_exempt')
                        ->badge()
                        ->visible(fn (Retainer $record) => $record->tax_exempt)
                        ->formatStateUsing(fn () => 'Isenta de impostos')
                        ->color('info'),
                ])
                ->metadata([
                    MetadataEntry::make('client.name')->label('Cliente')->fieldIcon(Phosphor::User),
                    MetadataEntry::make('business_line')->label('Linha de negócio')->placeholder('—')->fieldIcon(Phosphor::Tag),
                    MetadataEntry::make('next_due_on')->label('Próximo vencimento')->date()->placeholder('—')->fieldIcon(Phosphor::CalendarBlank),
                ])
                ->summary([
                    TextEntry::make('total')->label('Por período')->money('EUR'),
                    TextEntry::make('yearly_value')->label('Por ano')->money('EUR'),
                    TextEntry::make('received_amount')->label('Recebido')->money('EUR')->color('success'),
                    TextEntry::make('outstanding_amount')->label('Em aberto')->money('EUR')
                        ->color(fn (float $state) => $state > 0 ? 'warning' : 'gray'),
                    TextEntry::make('margin')->label('Margem')->money('EUR')
                        ->color(fn (float $state) => $state < 0 ? 'danger' : null)
                        ->tooltip(fn (Retainer $record) => 'Faturado menos custos diretos ('.Money::format($record->direct_costs).')'),
                ]),
        ]);
    }
}
