<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Models\Project;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class ProjectInfolist
{
    /** The timeline (two thirds) beside the details card, above the tabs with the records. */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'lg' => 3])
            ->components([
                Section::make('Linha do tempo')
                    ->columnSpan(['default' => 1, 'lg' => 2])
                    ->schema([
                        View::make('filament.projects.timeline')
                            ->viewData(fn (Project $record): array => ['events' => ProjectTimeline::events($record)]),
                    ]),
                Section::make('Detalhes')
                    ->columnSpan(1)
                    ->inlineLabel()
                    ->schema([
                        TextEntry::make('category.name')->label('Categoria')->placeholder('—'),
                        TextEntry::make('net_amount')->label('Valor sem IVA')->money('EUR', divideBy: 100),
                        TextEntry::make('vat_rate')->label('IVA')
                            ->formatStateUsing(fn ($state): string => (float) $state > 0 ? round((float) $state * 100).'%' : 'Sem IVA'),
                        TextEntry::make('direct_costs')->label('Custos diretos')->money('EUR', divideBy: 100),
                        TextEntry::make('description')->label('Descrição')->placeholder('—')->inlineLabel(false),
                        TextEntry::make('notes')->label('Notas')->placeholder('—')->inlineLabel(false),
                    ]),
            ]);
    }
}
