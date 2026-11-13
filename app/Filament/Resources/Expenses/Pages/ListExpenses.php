<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Enums\ExpenseKind;
use App\Filament\Pages\InvoiceImportInbox;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Tables\ExpensesTable;
use App\Filament\Resources\Expenses\Widgets\ExpensesSummary;
use App\Models\Expense;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;
use pxlrbt\FilamentExcel\Actions\ExportAction;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class ListExpenses extends ListRecords
{
    protected static string $resource = ExpenseResource::class;

    /** As in the mock, the list opens on its heading: the menu already says where you are. */
    public function getBreadcrumbs(): array
    {
        return [];
    }

    /** "4 expenses · €381.50 in total · €61.50 unpaid". */
    public function getSubheading(): string
    {
        // The total is worked out from the amount and its VAT, so it is added up here.
        $expenses = Expense::query()->get();
        $total = fn (iterable $expenses): int => (int) collect($expenses)->sum(fn (Expense $expense): int => (int) $expense->total);

        return __(':expenses · :total in total · :unpaid unpaid', [
            'expenses' => trans_choice('{0} No expenses|{1} 1 expense|[2,*] :count expenses', $expenses->count()),
            'total' => Money::format($total($expenses)),
            'unpaid' => Money::format($total($expenses->where('is_paid', false))),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            ExportAction::make()
                ->label(__('Export to Excel'))
                ->icon(Phosphor::DownloadSimple)
                ->color('gray')
                ->exports([ExpensesTable::excelExport()]),
            Action::make('importInvoices')
                ->label(__('Import invoices'))
                ->color('gray')
                ->url(fn (): string => InvoiceImportInbox::getUrl())
                ->visible(fn (): bool => InvoiceImportInbox::canAccess()),
            CreateAction::make()
                ->label(__('New expense'))
                ->icon(Phosphor::Plus),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [ExpensesSummary::class];
    }

    public function getTabs(): array
    {
        return [
            'all' => Tab::make(__('All expenses')),
            'unpaid' => Tab::make(__('Unpaid'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_paid', false))
                ->badge(fn () => Expense::query()->where('is_paid', false)->count() ?: null)
                ->badgeColor('warning'),
            'paid' => Tab::make(__('Paid expenses'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_paid', true)),
            'overhead' => Tab::make(__('Overhead'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('kind', ExpenseKind::Overhead)),
            'direct' => Tab::make(__('Direct expenses'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('kind', ExpenseKind::Direct)),
        ];
    }
}
