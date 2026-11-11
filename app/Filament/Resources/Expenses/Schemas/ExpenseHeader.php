<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Support\DetailPage;
use App\Models\Expense;
use Filament\Schemas\Schema;
use MortalKiller\FilamentPageHeader\Components\Header;

/**
 * Page header of an expense (discovered by convention), as in the mock: what was bought with its
 * state and kind right after it, then one muted line saying from whom and when.
 */
class ExpenseHeader
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Header::make()
                ->contained(false)
                ->heading(fn (Expense $record): string => static::titleLine($record), html: true)
                ->description(fn (Expense $record): string => static::identityLine($record), html: true),
        ]);
    }

    /** "Internet · novembro" and its badges (to pay or paid, overhead or direct), as HTML. Every value is escaped. */
    public static function titleLine(Expense $expense): string
    {
        return DetailPage::titleWithBadges(static::title($expense), [
            static::paymentState($expense),
            ['label' => $expense->kind->getLabel(), 'color' => $expense->kind->getColor()],
        ]);
    }

    /** What the expense is called: its description, as in the list and the breadcrumbs. */
    public static function title(Expense $expense): string
    {
        return filled($expense->description) ? $expense->description : __('Expense');
    }

    /**
     * "Por pagar" or "Paga", worded and coloured as in the list.
     *
     * @return array{label: string, color: string}
     */
    public static function paymentState(Expense $expense): array
    {
        return $expense->is_paid
            ? ['label' => __('Expense paid'), 'color' => 'success']
            : ['label' => __('Unpaid'), 'color' => 'warning'];
    }

    /** "Loja Demo · 10/11/2026", as HTML, the supplier as a link to its page. Every value is escaped. */
    public static function identityLine(Expense $expense): string
    {
        $supplier = $expense->supplier;
        $name = match (true) {
            $supplier === null => null,
            SupplierResource::canView($supplier) => '<a class="font-medium text-primary-600 hover:underline dark:text-primary-400" href="'
                .e(SupplierResource::getUrl('view', ['record' => $supplier])).'">'.e($supplier->name).'</a>',
            default => e($supplier->name),
        };

        return implode(' · ', array_filter([$name, e(DetailPage::date($expense->spent_on))]));
    }
}
