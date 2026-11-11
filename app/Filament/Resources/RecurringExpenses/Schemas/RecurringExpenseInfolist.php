<?php

namespace App\Filament\Resources\RecurringExpenses\Schemas;

use App\Enums\ExpenseKind;
use App\Enums\Frequency;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Retainers\RetainerResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Support\DetailPage;
use App\Models\Expense;
use App\Models\RecurringExpense;
use App\Support\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Number;

class RecurringExpenseInfolist
{
    /** How many of the latest created expenses the history names. */
    private const int HISTORY_EXPENSES = 3;

    /**
     * The column beside the expenses a recurring expense created (see
     * ViewRecurringExpense::content()), as in the mock: its rule, how its expenses are
     * classified, who is paid and what happened to it.
     */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components(fn (RecurringExpense $record): array => array_values(array_filter([
                static::rule($record),
                static::classification($record),
                DetailPage::rowsCard(__('Supplier'), 'supplier', static::supplierRows($record)),
                DetailPage::rowsCard(__('History'), 'history', static::historyRows($record)),
            ])));
    }

    /** "Regra": how often, on which day, from when until when, how much and how it is paid. */
    private static function rule(RecurringExpense $record): Section
    {
        $started = ! $record->starts_on->isFuture();

        return static::keyValueCard(__('Rule'), [
            DetailPage::cell('frequency', __('Schedule'), $record->frequency->getLabel()),
            DetailPage::cell('day', __('Day'), match ($record->frequency) {
                Frequency::Monthly => __(':day of each month', ['day' => $record->day]),
                Frequency::Quarterly => __(':day, every three months', ['day' => $record->day]),
                Frequency::Yearly => __(':day, once a year', ['day' => $record->day]),
            }),
            DetailPage::cell('starts_on', $started ? __('Started on') : __('Starts on'), DetailPage::date($record->starts_on)),
            DetailPage::cell(
                'ends_on',
                RecurringExpenseHeader::hasEnded($record) ? __('Ended on') : __('Ends'),
                $record->ends_on === null ? __('No end date') : DetailPage::date($record->ends_on),
            ),
            DetailPage::cell('net_amount', __('Amount excluding VAT'), Money::format($record->net_amount)),
            DetailPage::cell('vat_rate', __('VAT'), static::percent($record)),
            DetailPage::cell('method', __('Method'), $record->method?->getLabel()),
            DetailPage::help('rule_help', match (true) {
                ! $record->is_active => __('While paused it creates no expenses. Resume it to carry on from the last one.'),
                RecurringExpenseHeader::hasEnded($record) => __('It reached its end date and creates no more expenses.'),
                default => __('The expense of each :period is created on its own on day :day, unpaid. Changing the rule only affects the next ones.', [
                    'period' => match ($record->frequency) {
                        Frequency::Monthly => __('month'),
                        Frequency::Quarterly => __('quarter'),
                        Frequency::Yearly => __('year'),
                    },
                    'day' => $record->day,
                ]),
            }),
        ]);
    }

    /** "Classificação": the category and the kind its expenses take and, for a direct cost, the job or retainer it is charged to. */
    private static function classification(RecurringExpense $record): Section
    {
        $centre = $record->project ?? $record->retainer;
        $centreUrl = match (true) {
            $record->project !== null && ProjectResource::canView($record->project) => ProjectResource::getUrl('view', ['record' => $record->project]),
            $record->retainer !== null && RetainerResource::canView($record->retainer) => RetainerResource::getUrl('view', ['record' => $record->retainer]),
            default => null,
        };

        return static::keyValueCard(__('Classification'), array_values(array_filter([
            DetailPage::cell('category', __('Category'), $record->category?->name),
            DetailPage::cell('kind', __('Type'), $record->kind->getLabel()),
            $record->kind !== ExpenseKind::Direct ? null : DetailPage::cell(
                'cost_centre',
                __('Charged to'),
                $centre === null ? null : implode(' · ', array_filter([$centre->code, $centre->title])),
            )->url($centreUrl)->color($centreUrl === null ? null : 'primary'),
        ])));
    }

    /**
     * One labelled value per line, as the mock's key/value list.
     *
     * @param  list<TextEntry>  $entries
     */
    private static function keyValueCard(string $heading, array $entries): Section
    {
        return Section::make($heading)
            ->inlineLabel()
            ->extraAttributes(['class' => 'fap-key-value'])
            ->schema($entries);
    }

    /**
     * The supplier, as a link to its page, over its tax number and where it is established.
     *
     * @return list<array<string, mixed>>
     */
    private static function supplierRows(RecurringExpense $record): array
    {
        $supplier = $record->supplier;

        return [[
            'title' => $supplier->name,
            'url' => SupplierResource::canView($supplier) ? SupplierResource::getUrl('view', ['record' => $supplier]) : null,
            'meta' => implode(' · ', array_filter([
                filled($supplier->vat_number) ? __('Tax ID :number', ['number' => $supplier->vat_number]) : null,
                $supplier->seat?->getLabel(),
            ])),
        ]];
    }

    /**
     * What the records themselves tell, newest first: when it was created, when it was last
     * changed and when its latest expenses were created. Nothing else is kept about it.
     *
     * @return list<array<string, mixed>>
     */
    private static function historyRows(RecurringExpense $record): array
    {
        $created = $record->expenses()->latest()->orderByDesc('spent_on')->limit(self::HISTORY_EXPENSES)->get()
            ->map(fn (Expense $expense): array => [
                'at' => $expense->created_at,
                'title' => __('Expense for :month created', ['month' => $expense->spent_on->locale(app()->getLocale())->translatedFormat('F')]),
            ]);

        return $created
            ->when(
                $record->updated_at !== null && $record->created_at !== null && $record->updated_at->gt($record->created_at),
                fn ($rows) => $rows->push(['at' => $record->updated_at, 'title' => __('Recurring expense changed')]),
            )
            ->when($record->created_at !== null, fn ($rows) => $rows->push(['at' => $record->created_at, 'title' => __('Recurring expense created')]))
            ->filter(fn (array $row): bool => $row['at'] !== null)
            ->sortByDesc('at')
            ->map(fn (array $row): array => ['title' => $row['title'], 'meta' => DetailPage::moment($row['at'])])
            ->values()
            ->all();
    }

    private static function percent(RecurringExpense $record): string
    {
        return Number::percentage((float) $record->vat_rate * 100, maxPrecision: 2, locale: app()->getLocale());
    }
}
