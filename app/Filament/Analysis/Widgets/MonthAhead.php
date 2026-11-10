<?php

namespace App\Filament\Analysis\Widgets;

use App\Enums\CalendarEntryType;
use App\Filament\Pages\CashForecast;
use App\Filament\Support\ProportionBar;
use App\Support\ActivityCalendar;
use App\Support\CalendarEntry;
use App\Support\FinanceSettings;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Widgets\Widget;
use Illuminate\Support\HtmlString;

/**
 * "O resto do mês", beside the Calendário: what is still to come in and go out from today to
 * the end of the month, from the same rows the calendar shows (App\Support\ActivityCalendar).
 * Money already received or paid is left out: this is about what is ahead.
 */
class MonthAhead extends Widget
{
    protected string $view = 'filament.analysis.widgets.month-ahead';

    protected int|string|array $columnSpan = 'full';

    /** @return array{month: string, incoming: string, outgoing: string, difference: string, negative: bool, bar: HtmlString, forecastUrl: string} */
    protected function getViewData(): array
    {
        $today = CarbonImmutable::today();
        $entries = (new ActivityCalendar(app(FinanceSettings::class), $today))
            ->entries($today, $today->endOfMonth(), CalendarEntryType::cases());

        $sum = fn (callable $filter): int => (int) array_sum(array_map(
            fn (CalendarEntry $entry): int => (int) $entry->amount,
            array_filter($entries, $filter),
        ));

        $incoming = $sum(fn (CalendarEntry $entry): bool => match ($entry->type) {
            CalendarEntryType::Receivable => $entry->status !== 'paid',
            CalendarEntryType::ProjectedRetainer => true,
            default => false,
        });

        $outgoing = $sum(fn (CalendarEntry $entry): bool => match ($entry->type) {
            CalendarEntryType::Expense => $entry->status === 'unpaid',
            CalendarEntryType::Obligation => $entry->status !== 'done',
            CalendarEntryType::ProjectedRecurringExpense => true,
            default => false,
        });

        return [
            'month' => $today->translatedFormat('F'),
            'incoming' => Money::format($incoming),
            'outgoing' => Money::format($outgoing),
            'difference' => Money::format($incoming - $outgoing),
            'negative' => $incoming < $outgoing,
            'bar' => ProportionBar::render([
                ['label' => 'Entra', 'cents' => $incoming, 'tone' => 'strong'],
                ['label' => 'Sai', 'cents' => $outgoing, 'tone' => 'clay'],
            ]),
            'forecastUrl' => CashForecast::getUrl(),
        ];
    }
}
