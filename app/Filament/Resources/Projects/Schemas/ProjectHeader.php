<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Enums\Language;
use App\Models\Project;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Blade;
use MortalKiller\FilamentPageHeader\Components\Header;

/**
 * Page header shared by the project view and edit pages (discovered by convention). As in the
 * mock, on the page itself: the project's title and, under it, one line with its state, how it
 * is being paid, the client, the business line and the quote date. The code is in the
 * breadcrumbs, and the figures sit under the header (ProjectSummary).
 */
class ProjectHeader
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Header::make()
                ->contained(false)
                ->heading(fn (Project $record) => $record->title)
                ->description(fn (Project $record): string => static::identityLine($record), html: true),
        ]);
    }

    /**
     * The two badges, then "Client · Line · Quote dated 01/09/2026", as HTML. The line and the
     * date are left out while they are missing; every value is escaped.
     */
    public static function identityLine(Project $project): string
    {
        $badges = Blade::render(<<<'BLADE'
            <x-filament::badge :color="$status->getColor()">{{ $status->getLabel() }}</x-filament::badge>
            @if ($payment)
                <x-filament::badge :color="$payment->getColor()">{{ $payment->getLabel() }}</x-filament::badge>
            @endif
            BLADE, ['status' => $project->status, 'payment' => $project->payment_status]);

        $details = array_filter([
            e($project->client->name),
            filled($project->business_line) ? e($project->business_line) : null,
            $project->quoted_on === null ? null : e(__('Quote dated :date', ['date' => $project->quoted_on->format(Language::resolve(app()->getLocale())->dateFormat())])),
        ]);

        return '<span class="flex flex-wrap items-center gap-x-3 gap-y-1">'.$badges
            .collect($details)->map(fn (string $detail): string => '<span>'.$detail.'</span>')->implode('')
            .'</span>';
    }
}
