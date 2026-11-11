<?php

namespace App\Filament\Resources\RecurringExpenses\Schemas;

use App\Enums\Frequency;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Support\DetailPage;
use App\Models\RecurringExpense;
use App\Support\ScheduleProjector;
use Carbon\CarbonImmutable;
use Filament\Schemas\Schema;
use MortalKiller\FilamentPageHeader\Components\Header;

/**
 * Page header of a recurring expense (discovered by convention), as in the mock: what it is with
 * its state and how often it repeats right after it, then one muted line saying who is paid, on
 * which day and since when.
 */
class RecurringExpenseHeader
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Header::make()
                ->contained(false)
                ->heading(fn (RecurringExpense $record): string => static::titleLine($record), html: true)
                ->description(fn (RecurringExpense $record): string => static::identityLine($record), html: true),
        ]);
    }

    /** "Internet" and its badges (active, monthly), as HTML. Every value is escaped. */
    public static function titleLine(RecurringExpense $template): string
    {
        return DetailPage::titleWithBadges($template->description, [
            static::state($template),
            ['label' => $template->frequency->getLabel(), 'color' => 'gray'],
        ]);
    }

    /**
     * Whether it still creates expenses: paused by hand, past its end date, or active.
     *
     * @return array{label: string, color: string}
     */
    public static function state(RecurringExpense $template): array
    {
        return match (true) {
            ! $template->is_active => ['label' => __('Paused'), 'color' => 'gray'],
            static::hasEnded($template) => ['label' => __('Ended'), 'color' => 'gray'],
            default => ['label' => __('Active recurring expense'), 'color' => 'success'],
        };
    }

    public static function hasEnded(RecurringExpense $template): bool
    {
        return $template->ends_on !== null && $template->ends_on->isBefore(today());
    }

    /**
     * The dates of the expenses still to be created, oldest first, at least as far as the next
     * one and through the end of this year: what the scheduled generator will create, read from
     * the projection the cash forecast uses. Empty while paused and after the end date.
     *
     * @return list<CarbonImmutable>
     */
    public static function upcomingDates(RecurringExpense $template): array
    {
        $anchor = CarbonImmutable::parse(max(
            today()->toDateString(),
            $template->starts_on->toDateString(),
            (string) $template->expenses()->max('spent_on'),
        ));
        $until = $anchor->addMonthsNoOverflow($template->frequency->months())->endOfMonth();

        return array_values(app(ScheduleProjector::class)->recurringExpenseDates($template, $until->max(today()->endOfYear())));
    }

    /**
     * "Loja Demo · todos os meses no dia 10 · desde 10/05/2025", as HTML, with the supplier as a
     * link to its page and the end date when there is one. Every value is escaped.
     */
    public static function identityLine(RecurringExpense $template): string
    {
        $supplier = $template->supplier;
        $name = e($supplier->name);

        return implode(' · ', array_filter([
            SupplierResource::canView($supplier)
                ? '<a class="font-medium text-primary-600 hover:underline dark:text-primary-400" href="'.e(SupplierResource::getUrl('view', ['record' => $supplier])).'">'.$name.'</a>'
                : $name,
            e(match ($template->frequency) {
                Frequency::Monthly => __('every month on day :day', ['day' => $template->day]),
                Frequency::Quarterly => __('every three months on day :day', ['day' => $template->day]),
                Frequency::Yearly => __('every year on day :day', ['day' => $template->day]),
            }),
            e(__('since :date', ['date' => DetailPage::date($template->starts_on)])),
            $template->ends_on === null ? null : e(__('until :date', ['date' => DetailPage::date($template->ends_on)])),
        ]));
    }
}
