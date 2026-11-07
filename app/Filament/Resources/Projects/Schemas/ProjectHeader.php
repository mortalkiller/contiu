<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Models\Project;
use App\Support\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use MortalKiller\FilamentPageHeader\Components\Header;
use MortalKiller\FilamentPageHeader\Components\MetadataEntry;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * Page header shared by the project view and edit pages (discovered by convention).
 */
class ProjectHeader
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Header::make()
                ->heading(fn (Project $record) => $record->code)
                ->description(fn (Project $record) => $record->title)
                ->icon(Phosphor::Briefcase)
                ->iconBgColor('primary')
                ->badges([
                    TextEntry::make('status')->badge(),
                    TextEntry::make('payment_status')->badge(),
                ])
                ->metadata([
                    MetadataEntry::make('client.name')
                        ->label('Cliente')
                        ->fieldIcon(Phosphor::User),
                    MetadataEntry::make('business_line')
                        ->label('Linha de negócio')
                        ->placeholder('—')
                        ->fieldIcon(Phosphor::Tag),
                    MetadataEntry::make('quoted_on')
                        ->label('Orçamento')
                        ->date()
                        ->fieldIcon(Phosphor::CalendarBlank),
                ])
                ->summary([
                    TextEntry::make('total')
                        ->label('Total')
                        ->money('EUR', divideBy: 100),
                    TextEntry::make('received_amount')
                        ->label('Recebido')
                        ->money('EUR', divideBy: 100)
                        ->color('success'),
                    TextEntry::make('outstanding_amount')
                        ->label('Em aberto')
                        ->money('EUR', divideBy: 100)
                        ->color(fn (float $state) => $state > 0 ? 'warning' : 'gray'),
                    TextEntry::make('margin')
                        ->label('Margem')
                        ->money('EUR', divideBy: 100)
                        ->tooltip(fn (Project $record) => 'Total menos custos diretos ('.Money::format($record->direct_costs).')'),
                ]),
        ]);
    }
}
