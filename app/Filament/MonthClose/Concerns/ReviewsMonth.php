<?php

namespace App\Filament\MonthClose\Concerns;

use App\Support\MonthReview;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;
use Livewire\Attributes\On;
use Livewire\Attributes\Reactive;

/**
 * Shared by the "Fecho do mês" widgets: they receive the month from the page and refresh when
 * anything on the page is treated.
 */
trait ReviewsMonth
{
    #[Reactive]
    public ?string $month = null;

    protected function review(): MonthReview
    {
        return new MonthReview(Carbon::createFromFormat('!Y-m', $this->month ?? today()->format('Y-m')));
    }

    #[On('month-close-updated')]
    public function refreshMonth(): void
    {
        // Re-render with fresh figures.
    }

    /** "A receber em novembro" or "Despesas de novembro", the month as the page shows it. */
    protected function monthName(): string
    {
        return mb_strtolower($this->review()->start->locale(app()->getLocale())->translatedFormat('F'));
    }

    /** A table heading with a badge beside it, as in the mock ("1 em aberto"); no badge for null. */
    protected function headingWithBadge(string $heading, ?string $badge, string $color = 'warning'): Htmlable
    {
        return new HtmlString('<span class="inline-flex flex-wrap items-center gap-2">'.e($heading)
            .($badge === null ? '' : Blade::render('<x-filament::badge :color="$color">{{ $badge }}</x-filament::badge>', ['badge' => $badge, 'color' => $color]))
            .'</span>');
    }

    protected function monthChanged(): void
    {
        $this->dispatch('month-close-updated');
    }
}
