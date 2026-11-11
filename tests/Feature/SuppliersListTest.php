<?php

use App\Enums\Frequency;
use App\Filament\Resources\Suppliers\Pages\ManageSuppliers;
use App\Models\Expense;
use App\Models\RecurringExpense;
use App\Models\Supplier;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-10'));
    actingAsAdmin();

    $this->cloud = Supplier::factory()->create(['name' => 'Cloud Demo', 'vat_number' => null]);
    RecurringExpense::factory()->for($this->cloud)->create(['frequency' => Frequency::Yearly, 'is_active' => true]);
    Expense::factory()->for($this->cloud)->create(['net_amount' => 20000, 'vat_rate' => 0, 'spent_on' => '2026-02-25', 'is_paid' => true]);
    Expense::factory()->for($this->cloud)->create(['net_amount' => 50000, 'vat_rate' => 0, 'spent_on' => '2025-02-25', 'is_paid' => true]);

    $this->shop = Supplier::factory()->create(['name' => 'Loja Demo', 'vat_number' => '509999990']);
    Expense::factory()->for($this->shop)->create(['net_amount' => 6150, 'vat_rate' => 0, 'spent_on' => '2026-11-10', 'is_paid' => false]);

    $this->unused = Supplier::factory()->create(['name' => 'Papelaria Demo']);
});

it('says who you pay this year, with no breadcrumbs, and offers the export and a new supplier', function () {
    Livewire::test(ManageSuppliers::class)
        ->assertSee('A quem pagas. 2 fornecedores com despesas em 2026.')
        ->assertDontSeeHtml('fi-breadcrumbs')
        ->assertActionExists('export')
        ->assertActionExists('create');
})->group('ui');

it('lists each supplier with its schedule or tax ID, this year\'s total and what is to pay, totalled at the foot', function () {
    Livewire::test(ManageSuppliers::class)
        ->loadTable()
        ->assertSeeInOrder(['Cloud Demo', 'Recorrente', 'anual', 'Loja Demo', 'NIF 509999990'])
        ->assertTableColumnStateSet('spent_this_year', 20000, $this->cloud)
        ->assertTableColumnStateSet('unpaid', 6150, $this->shop)
        ->assertTableColumnSummarySet('spent_this_year', 'sum', 26150)
        ->assertTableColumnSummarySet('unpaid', 'sum', 6150);
})->group('ui');

it('edits a supplier from the row and deletes one without expenses from the edit form', function () {
    Livewire::test(ManageSuppliers::class)
        ->loadTable()
        ->assertActionVisible(TestAction::make('edit')->table($this->shop))
        ->mountAction(TestAction::make('edit')->table($this->unused))
        ->assertActionVisible(TestAction::make('delete')->table($this->unused))
        ->callAction(TestAction::make('delete')->table($this->unused));

    expect(Supplier::find($this->unused->id))->toBeNull();
})->group('ui');
