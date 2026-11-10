<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Models\Project;
use App\Models\Receivable;
use App\Support\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;
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
                    TextEntry::make('status')->label('Estado')->badge(),
                    TextEntry::make('payment_status')->label('Pagamento')->badge(),
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
                        ->money('EUR', divideBy: 100)
                        ->belowContent(fn (Project $record): string => $record->vat_amount > 0
                            ? 'Inclui '.Money::format($record->vat_amount).' de IVA'
                            : 'Sem IVA'),
                    TextEntry::make('received_amount')
                        ->label('Recebido')
                        ->money('EUR', divideBy: 100)
                        ->color('success')
                        ->belowContent(fn (Project $record): string => self::receivedPercent($record).'% do faturado'),
                    TextEntry::make('outstanding_amount')
                        ->label('Em aberto')
                        ->money('EUR', divideBy: 100)
                        ->color(fn (float $state) => $state > 0 ? 'warning' : 'gray')
                        ->belowContent(fn (Project $record): string => self::nextDueNote($record)),
                    TextEntry::make('margin')
                        ->label('Margem')
                        ->money('EUR', divideBy: 100)
                        ->belowContent(fn (Project $record): string => self::marginNote($record)),
                ]),
        ]);
    }

    private static function receivedPercent(Project $record): int
    {
        return (int) round($record->progress * 100);
    }

    /** When the next open instalment is due, or that it is already late. */
    private static function nextDueNote(Project $record): string
    {
        $next = $record->receivables
            ->filter(fn (Receivable $receivable): bool => $receivable->outstanding > 0)
            ->sortBy('due_on')
            ->first();

        return match (true) {
            $next === null => 'Nada por receber',
            $next->due_on->isBefore(today()) => 'Venceu a '.$next->due_on->format('d/m/Y'),
            default => 'Vence a '.$next->due_on->format('d/m/Y'),
        };
    }

    /** The margin as a share of what was billed, and the direct costs behind it. */
    private static function marginNote(Project $record): string
    {
        $costs = Money::format($record->direct_costs).' de custos diretos';

        return $record->billed_amount > 0
            ? Number::format($record->margin / $record->billed_amount * 100, maxPrecision: 1, locale: 'pt_PT').'% · '.$costs
            : $costs;
    }
}
