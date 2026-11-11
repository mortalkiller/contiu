<?php

namespace App\Filament\Resources\Projects\Schemas;

use App\Models\Project;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;

class ProjectInfolist
{
    /**
     * The column beside the project's records (see ViewProject::content()): the timeline, then the
     * details card. The description and notes show only when there are any.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('Timeline'))
                    ->schema([
                        View::make('filament.projects.timeline')
                            ->viewData(fn (Project $record): array => ['events' => ProjectTimeline::events($record)]),
                    ]),
                Section::make(__('Details'))
                    ->inlineLabel()
                    // One line per entry, label then value, as the mock's key/value list (filament-appearance).
                    ->extraAttributes(['class' => 'fap-key-value'])
                    ->schema([
                        TextEntry::make('category.name')->label(__('Category'))->placeholder('—'),
                        TextEntry::make('net_amount')->label(__('Amount excluding VAT'))->money('EUR', divideBy: 100),
                        TextEntry::make('vat_rate')->label(__('VAT'))
                            ->formatStateUsing(fn ($state): string => (float) $state > 0 ? round((float) $state * 100).'%' : __('Excluding VAT')),
                        TextEntry::make('direct_costs')->label(__('Direct costs'))->money('EUR', divideBy: 100),
                        TextEntry::make('description')->label(__('Description'))->inlineLabel(false)
                            ->visible(fn (Project $record): bool => filled($record->description)),
                        TextEntry::make('notes')->label(__('Notes'))->inlineLabel(false)
                            ->visible(fn (Project $record): bool => filled($record->notes)),
                    ]),
            ]);
    }
}
