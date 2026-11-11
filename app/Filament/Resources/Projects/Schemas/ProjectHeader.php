<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Enums\Language;
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
                    TextEntry::make('status')->label(__('Status'))->badge(),
                    TextEntry::make('payment_status')->label(__('Payment'))->badge(),
                ])
                ->metadata([
                    MetadataEntry::make('client.name')
                        ->label(__('Client'))
                        ->fieldIcon(Phosphor::User),
                    MetadataEntry::make('business_line')
                        ->label(__('Business line'))
                        ->placeholder('—')
                        ->fieldIcon(Phosphor::Tag),
                    MetadataEntry::make('quoted_on')
                        ->label(__('Quote'))
                        ->date()
                        ->fieldIcon(Phosphor::CalendarBlank),
                ])
                ->summary([
                    TextEntry::make('total')
                        ->label(__('Total'))
                        ->money('EUR', divideBy: 100)
                        ->belowContent(fn (Project $record): string => $record->vat_amount > 0
                            ? __('Includes :amount VAT', ['amount' => Money::format($record->vat_amount)])
                            : __('Excluding VAT')),
                    TextEntry::make('received_amount')
                        ->label(__('Received'))
                        ->money('EUR', divideBy: 100)
                        ->color('success')
                        ->belowContent(fn (Project $record): string => __(':percent% of invoiced amount', ['percent' => self::receivedPercent($record)])),
                    TextEntry::make('outstanding_amount')
                        ->label(__('Outstanding'))
                        ->money('EUR', divideBy: 100)
                        ->color(fn (float $state) => $state > 0 ? 'warning' : 'gray')
                        ->belowContent(fn (Project $record): string => self::nextDueNote($record)),
                    TextEntry::make('margin')
                        ->label(__('Margin'))
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
            ->filter(fn (Receivable $receivable): bool => $receivable->projectedOutstanding() > 0)
            ->sortBy('due_on')
            ->first();

        return match (true) {
            $next === null => __('Nothing to receive'),
            $next->due_on->isBefore(today()) => __('Was due on :date', ['date' => $next->due_on->format(Language::resolve(app()->getLocale())->dateFormat())]),
            default => __('Due on :date', ['date' => $next->due_on->format(Language::resolve(app()->getLocale())->dateFormat())]),
        };
    }

    /** The margin as a share of what was billed, and the direct costs behind it. */
    private static function marginNote(Project $record): string
    {
        $costs = __(':amount in direct costs', ['amount' => Money::format($record->direct_costs)]);

        return $record->billed_amount > 0
            ? Number::format($record->margin / $record->billed_amount * 100, maxPrecision: 1, locale: app()->getLocale()).'% · '.$costs
            : $costs;
    }
}
