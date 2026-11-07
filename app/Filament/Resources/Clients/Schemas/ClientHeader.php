<?php

namespace App\Filament\Resources\Clients\Schemas;

use App\Models\Client;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use MortalKiller\FilamentPageHeader\Components\Header;
use MortalKiller\FilamentPageHeader\Components\MetadataEntry;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class ClientHeader
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Header::make()
                ->heading(fn (Client $record) => $record->name)
                ->initials(fn (Client $record) => $record->name)
                ->initialsBgColor('primary')
                ->metadata([
                    MetadataEntry::make('vat_number')->label('NIF')->placeholder('—')->copyable()->fieldIcon(Phosphor::IdentificationCard),
                    MetadataEntry::make('email')->label('Email')->placeholder('—')->copyable()->fieldIcon(Phosphor::Envelope),
                    MetadataEntry::make('phone')->label('Telefone')->placeholder('—')->fieldIcon(Phosphor::Phone),
                ])
                ->summary([
                    TextEntry::make('contracted_amount')->label('Contratado')->money('EUR'),
                    TextEntry::make('received_amount')->label('Recebido')->money('EUR')->color('success'),
                    TextEntry::make('outstanding_amount')->label('Por receber')->money('EUR')
                        ->color(fn (float $state) => $state > 0 ? 'warning' : 'gray'),
                    TextEntry::make('overdue_amount')->label('Vencido')->money('EUR')
                        ->color(fn (float $state) => $state > 0 ? 'danger' : 'gray'),
                ]),
        ]);
    }
}
