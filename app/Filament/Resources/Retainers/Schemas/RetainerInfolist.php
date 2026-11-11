<?php

namespace App\Filament\Resources\Retainers\Schemas;

use App\Actions\GenerateRetainerPeriods;
use App\Enums\Language;
use App\Models\Retainer;
use App\Support\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class RetainerInfolist
{
    /**
     * The column beside the retainer's records (see ViewRetainer::content()): the next periods it
     * will bill, then the details card. The notes show only when there are any.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->defaultDateDisplayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
            ->defaultNumberLocale(fn (): string => app()->getLocale())
            ->components([
                Section::make(__('Upcoming periods'))
                    ->description(__('If nothing changes'))
                    ->visible(fn (Retainer $record): bool => static::upcomingEvents($record) !== [])
                    ->schema([
                        View::make('filament.projects.timeline')
                            ->viewData(fn (Retainer $record): array => ['events' => static::upcomingEvents($record)]),
                    ]),
                Section::make(__('Details'))
                    ->inlineLabel()
                    // One line per entry, label then value, as the mock's key/value list (filament-appearance).
                    ->extraAttributes(['class' => 'fap-key-value'])
                    ->schema([
                        TextEntry::make('frequency')->label(__('Billing frequency')),
                        TextEntry::make('due_day')->label(__('Due date'))->formatStateUsing(fn ($state) => __('Day :day', ['day' => $state])),
                        TextEntry::make('starts_on')->label(__('Start'))->date(),
                        TextEntry::make('ends_on')->label(__('End'))->date()->placeholder(__('No end date')),
                        TextEntry::make('category.name')->label(__('Category'))->placeholder('—'),
                        TextEntry::make('net_amount')->label(__('Amount excluding VAT'))->money('EUR', divideBy: 100),
                        TextEntry::make('vat_rate')->label(__('VAT'))
                            ->formatStateUsing(fn ($state): string => (float) $state > 0 ? round((float) $state * 100).'%' : __('Excluding VAT')),
                        TextEntry::make('direct_costs')->label(__('Direct costs'))->money('EUR', divideBy: 100),
                        TextEntry::make('notes')->label(__('Notes'))->inlineLabel(false)
                            ->visible(fn (Retainer $record): bool => filled($record->notes)),
                    ]),
            ]);
    }

    /**
     * The periods still to be created, in the shape of the timeline view: one line each, with
     * when it falls due and its amount.
     *
     * @return list<array{state: string, title: string, meta: string, amount: string}>
     */
    private static function upcomingEvents(Retainer $retainer): array
    {
        $format = Language::resolve(app()->getLocale())->dateFormat();

        return array_map(fn (array $period): array => [
            'state' => 'upcoming',
            'title' => $period['label'],
            'meta' => __('Due on :date', ['date' => $period['due_on']->format($format)]),
            'amount' => Money::format($period['amount']),
        ], app(GenerateRetainerPeriods::class)->upcoming($retainer));
    }
}
