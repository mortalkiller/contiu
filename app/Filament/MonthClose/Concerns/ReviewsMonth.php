<?php

namespace App\Filament\MonthClose\Concerns;

use App\Support\MonthReview;
use Illuminate\Support\Carbon;
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
        return new MonthReview(Carbon::createFromFormat('Y-m', $this->month ?? today()->format('Y-m'))->startOfMonth());
    }

    #[On('month-close-updated')]
    public function refreshMonth(): void
    {
        // Re-render with fresh figures.
    }

    protected function monthChanged(): void
    {
        $this->dispatch('month-close-updated');
    }
}
