<?php

namespace App\Filament\Support;

use Filament\Actions\Action;
use Filament\Tables\Contracts\HasTable;

/**
 * The two empty lists of the mock. With nothing recorded yet, the way out is to create the first
 * record (each table says how). With records that a search or a filter hides, the way out is to
 * clear them, not to create: that button is on every table, shown only then.
 */
final class TableEmptyState
{
    public static function isNarrowed(HasTable $livewire): bool
    {
        return filled($livewire->getTableSearch()) || $livewire->getTable()->isFiltered();
    }

    public static function clearAction(): Action
    {
        return Action::make('clearTableNarrowing')
            ->label(__('Clear filters'))
            ->color('gray')
            ->visible(fn (HasTable $livewire): bool => self::isNarrowed($livewire))
            ->action(function (HasTable $livewire): void {
                $livewire->resetTableSearch();
                $livewire->removeTableFilters();
            });
    }

    /**
     * The heading of a list hidden by a search: "Nenhuma despesa corresponde a «adobe»". Without a
     * search (only filters), the table's own heading stays.
     */
    public static function heading(HasTable $livewire, string $empty, string $noun): string
    {
        $search = $livewire->getTableSearch();

        return match (true) {
            filled($search) => __('No :things match «:search»', ['things' => $noun, 'search' => $search]),
            self::isNarrowed($livewire) => __('No :things match these filters', ['things' => $noun]),
            default => $empty,
        };
    }
}
