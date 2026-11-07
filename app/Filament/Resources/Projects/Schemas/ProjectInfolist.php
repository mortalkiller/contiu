<?php

namespace App\Filament\Resources\Projects\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProjectInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Detalhes')
                    ->columnSpanFull()
                    ->columns(4)
                    ->schema([
                        TextEntry::make('category.name')->label('Categoria')->placeholder('—'),
                        TextEntry::make('net_amount')->label('Valor sem IVA')->money('EUR'),
                        TextEntry::make('vat_rate')->label('IVA')->formatStateUsing(fn ($state) => round((float) $state * 100).'%'),
                        TextEntry::make('direct_costs')->label('Custos diretos')->money('EUR'),
                        TextEntry::make('description')->label('Descrição')->placeholder('—')->columnSpanFull(),
                        TextEntry::make('notes')->label('Notas')->placeholder('—')->columnSpanFull(),
                    ]),
            ]);
    }
}
