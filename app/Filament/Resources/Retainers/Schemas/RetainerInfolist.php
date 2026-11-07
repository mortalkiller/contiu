<?php

namespace App\Filament\Resources\Retainers\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RetainerInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Detalhes')
                    ->columnSpanFull()
                    ->columns(4)
                    ->schema([
                        TextEntry::make('frequency')->label('Frequência'),
                        TextEntry::make('due_day')->label('Vencimento')->formatStateUsing(fn ($state) => 'Dia '.$state),
                        TextEntry::make('starts_on')->label('Início')->date(),
                        TextEntry::make('ends_on')->label('Fim')->date()->placeholder('Sem fim'),
                        TextEntry::make('category.name')->label('Categoria')->placeholder('—'),
                        TextEntry::make('net_amount')->label('Valor sem IVA')->money('EUR', divideBy: 100),
                        TextEntry::make('vat_rate')->label('IVA')->formatStateUsing(fn ($state) => round((float) $state * 100).'%'),
                        TextEntry::make('direct_costs')->label('Custos diretos')->money('EUR', divideBy: 100),
                        TextEntry::make('notes')->label('Notas')->placeholder('—')->columnSpanFull(),
                    ]),
            ]);
    }
}
