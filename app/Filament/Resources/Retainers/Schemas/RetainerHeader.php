<?php

namespace App\Filament\Resources\Retainers\Schemas;

use App\Enums\Language;
use App\Models\Retainer;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Blade;
use MortalKiller\FilamentPageHeader\Components\Header;

/**
 * Page header shared by the retainer view and edit pages (discovered by convention). As in the
 * mock, on the page itself: the retainer's title and, under it, one line with whether it is
 * active, how often it bills, the client, the business line and since when. The code is in the
 * breadcrumbs, and the figures sit under the header (RetainerSummary).
 */
class RetainerHeader
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Header::make()
                ->contained(false)
                ->heading(fn (Retainer $record) => $record->title)
                ->description(fn (Retainer $record): string => static::identityLine($record), html: true),
        ]);
    }

    /**
     * The badges, then "Client · Line · Since 01/07/2026", as HTML. The line is left out while it
     * is missing; every value is escaped.
     */
    public static function identityLine(Retainer $retainer): string
    {
        $badges = Blade::render(<<<'BLADE'
            <x-filament::badge :color="$active ? 'success' : 'gray'">{{ $active ? __('Active retainer') : __('Ended') }}</x-filament::badge>
            <x-filament::badge color="gray">{{ $frequency }}</x-filament::badge>
            @if ($exempt)
                <x-filament::badge color="info">{{ __('Tax-exempt retainer') }}</x-filament::badge>
            @endif
            BLADE, ['active' => (bool) $retainer->is_active, 'frequency' => $retainer->frequency->getLabel(), 'exempt' => (bool) $retainer->tax_exempt]);

        $details = array_filter([
            e($retainer->client->name),
            filled($retainer->business_line) ? e($retainer->business_line) : null,
            e(__('Since :date', ['date' => $retainer->starts_on->format(Language::resolve(app()->getLocale())->dateFormat())])),
        ]);

        return '<span class="flex flex-wrap items-center gap-x-3 gap-y-1">'.$badges
            .collect($details)->map(fn (string $detail): string => '<span>'.$detail.'</span>')->implode('')
            .'</span>';
    }
}
