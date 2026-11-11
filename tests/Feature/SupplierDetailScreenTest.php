<?php

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Suppliers\Pages\ManageSuppliers;
use App\Filament\Resources\Suppliers\Pages\ViewSupplier;
use App\Filament\Resources\Suppliers\RelationManagers\SupplierExpensesRelationManager;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Resources\Suppliers\Widgets\SupplierSummary;
use App\Models\Expense;
use App\Models\Supplier;
use App\Support\Money;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-10'));
    actingAsAdmin();

    $this->shop = Supplier::factory()->create(['name' => 'Loja Demo', 'vat_number' => '509999990', 'website' => 'https://www.lojademo.example']);
    $this->unpaid = Expense::factory()->for($this->shop)->unpaid()->create(['description' => 'Internet', 'spent_on' => '2026-11-10', 'net_amount' => 6150, 'vat_rate' => 0]);
    Expense::factory()->for($this->shop)->create(['description' => 'Internet', 'spent_on' => '2025-11-10', 'net_amount' => 5990, 'vat_rate' => 0, 'invoice_number' => 'FT A/1187']);
    Expense::factory()->for($this->shop)->create(['description' => 'Router', 'spent_on' => '2025-05-12', 'net_amount' => 2460, 'vat_rate' => 0]);
    Expense::factory()->create(['spent_on' => '2026-02-01', 'net_amount' => 18450, 'vat_rate' => 0]);
});

it('opens a supplier\'s own page from the list', function () {
    Livewire::test(ManageSuppliers::class)
        ->loadTable()
        ->assertSeeHtml(SupplierResource::getUrl('view', ['record' => $this->shop]));

    $this->get(SupplierResource::getUrl('view', ['record' => $this->shop]))->assertOk();
})->group('ui');

it('sums up a supplier: this year, to pay, without document and since the start', function () {
    Livewire::test(SupplierSummary::class, ['record' => $this->shop])
        ->assertSeeInOrder(['Gasto em 2026', Money::format(6150), '1 despesa · 25% das tuas despesas'], false)
        ->assertSeeInOrder(['Por pagar', Money::format(6150), 'Internet · desde 10/11/2026'], false)
        ->assertSeeInOrder(['Sem comprovativo', '3', 'Importar a fatura'])
        ->assertSeeInOrder(['Desde sempre', Money::format(14600), '3 despesas desde maio 2025'], false);
})->group('ui');

it('shows the name, one line about the supplier and its details beside the expenses', function () {
    Livewire::test(ViewSupplier::class, ['record' => $this->shop->getRouteKey()])
        ->assertSee('Loja Demo')
        ->assertSeeInOrder(['509999990', 'lojademo.example', 'Fornecedor desde maio 2025'])
        ->assertDontSee('Visualizar')
        ->assertSee('Dados do fornecedor')
        ->assertSee('No extrato bancário')
        ->assertSeeHtml('fap-key-value')
        ->assertSeeLivewire(SupplierSummary::class)
        ->assertActionExists('edit')
        ->assertActionHasUrl('newExpense', ExpenseResource::getUrl('create', ['supplier' => $this->shop->getKey()]))
        // A supplier with expenses cannot be deleted.
        ->assertActionHidden('delete');
})->group('ui');

it('lists the supplier\'s expenses and marks an unpaid one as paid from its row', function () {
    Livewire::test(SupplierExpensesRelationManager::class, ['ownerRecord' => $this->shop, 'pageClass' => ViewSupplier::class])
        ->loadTable()
        ->assertCanSeeTableRecords($this->shop->expenses)
        ->assertTableColumnStateSet('proof', 'FT A/1187', $this->shop->expenses()->firstWhere('invoice_number', 'FT A/1187'))
        ->assertTableColumnStateSet('proof', 'Em falta', $this->unpaid)
        ->assertTableColumnSummarySet('total', 'sum', 14600)
        ->callAction(TestAction::make('markPaid')->table($this->unpaid));

    expect($this->unpaid->fresh()->is_paid)->toBeTrue();
})->group('ui');

it('opens the new expense form with the supplier already chosen', function () {
    Livewire::withQueryParams(['supplier' => $this->shop->getKey()])
        ->test(CreateExpense::class)
        ->assertSchemaStateSet(['supplier_id' => $this->shop->getKey()]);
})->group('ui');
