<?php

namespace App\Filament\Analysis\Widgets;

use App\Enums\CalendarEntryType;
use App\Enums\ReceivableStatus;
use App\Support\ActivityCalendar;
use App\Support\CalendarEntry;
use App\Support\FinanceSettings;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Widgets\Widget;

/**
 * Beside the calendar: the current week (Monday to Sunday) in four lines, from the same rows the
 * calendar shows (App\Support\ActivityCalendar): what comes in and how much of it already did,
 * what goes out and how much is still to pay, the tax deadlines of the week, and what is late
 * from the ninety days before it.
 */
class WeekAhead extends Widget
{
    private const int LOOK_BACK_DAYS = 90;

    protected string $view = 'filament.analysis.widgets.week-ahead';

    protected int|string|array $columnSpan = 'full';

    /** @return array{range: string, incoming: string, received: string, outgoing: string, unpaid: string, deadlines: int, late: int} */
    protected function getViewData(): array
    {
        $today = CarbonImmutable::today();
        $monday = $today->startOfWeek();
        $sunday = $today->endOfWeek();
        $calendar = new ActivityCalendar(app(FinanceSettings::class), $today);

        $week = $calendar->entries($monday, $sunday, CalendarEntryType::cases());
        $before = $calendar->entries($monday->subDays(self::LOOK_BACK_DAYS), $monday->subDay(), [CalendarEntryType::Obligation, CalendarEntryType::Receivable]);

        $sum = fn (array $entries, callable $filter): int => (int) array_sum(array_map(
            fn (CalendarEntry $entry): int => (int) $entry->amount,
            array_filter($entries, $filter),
        ));

        $received = $sum($week, fn (CalendarEntry $entry): bool => $entry->type === CalendarEntryType::Payment);
        $stillComing = $sum($week, fn (CalendarEntry $entry): bool => match ($entry->type) {
            CalendarEntryType::Receivable => $entry->status !== ReceivableStatus::Paid->value,
            CalendarEntryType::ProjectedRetainer => true,
            default => false,
        });
        $paid = $sum($week, fn (CalendarEntry $entry): bool => match ($entry->type) {
            CalendarEntryType::Expense => $entry->status === 'paid',
            CalendarEntryType::Obligation => $entry->status === 'done',
            default => false,
        });
        $unpaid = $sum($week, fn (CalendarEntry $entry): bool => match ($entry->type) {
            CalendarEntryType::Expense => $entry->status === 'unpaid',
            CalendarEntryType::Obligation => $entry->status !== 'done',
            CalendarEntryType::ProjectedRecurringExpense => true,
            default => false,
        });

        return [
            'range' => __(':from to :through', [
                'from' => $monday->locale(app()->getLocale())->translatedFormat(__('M j')),
                'through' => $sunday->locale(app()->getLocale())->translatedFormat(__('F j')),
            ]),
            'incoming' => Money::format($received + $stillComing, locale: app()->getLocale()),
            'received' => Money::format($received, locale: app()->getLocale()),
            'outgoing' => Money::format($paid + $unpaid, locale: app()->getLocale()),
            'unpaid' => Money::format($unpaid, locale: app()->getLocale()),
            'deadlines' => count(array_filter($week, fn (CalendarEntry $entry): bool => $entry->type === CalendarEntryType::Obligation && $entry->status !== 'done')),
            'late' => count(array_filter($before, fn (CalendarEntry $entry): bool => match ($entry->type) {
                CalendarEntryType::Obligation => $entry->status === 'overdue',
                CalendarEntryType::Receivable => $entry->status === ReceivableStatus::Overdue->value,
                default => false,
            })),
        ];
    }
}
