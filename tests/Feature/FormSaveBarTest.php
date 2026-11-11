<?php

use App\Enums\CategoryType;
use App\Enums\Seat;
use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Projects\Pages\CreateProject;
use App\Filament\Resources\Projects\Pages\EditProject;
use App\Models\Category;
use App\Models\Project;
use App\Models\Supplier;
use Filament\Actions\Testing\TestAction;
use Filament\Support\Enums\Alignment;
use Livewire\Livewire;

beforeEach(function () {
    actingAsAdmin();
});

it('names a new expense as the mock does, with its buttons at the end of a sticky bar', function () {
    Livewire::test(CreateExpense::class)
        ->assertSee('Nova despesa')
        ->assertSee('Importa-a')
        // Filament lays end-aligned actions out from the end: on screen, "Cancelar" comes first.
        ->assertSeeInOrder(['Guardar despesa', 'Guardar e criar outra', 'Cancelar'])
        ->tap(fn ($page) => expect($page->instance())
            ->getFormActionsAlignment()->toBe(Alignment::End)
            ->areFormActionsSticky()->toBeTrue());
});

it('sums the expense up beside the form as it is typed and says what is missing to save it', function () {
    Livewire::test(CreateExpense::class)
        ->assertSee('Falta o fornecedor para poderes guardar.')
        ->fillForm(['net_amount' => '50', 'vat_rate' => '0.2300'])
        // The update comes back as JSON, where the space before the euro sign is escaped.
        ->assertSeeInOrder(['IVA a 23%', '11,50', 'Total', '61,50']);
});

it('keeps the edit page buttons at the end of a sticky bar', function () {
    Livewire::test(EditProject::class, ['record' => Project::factory()->create()->getRouteKey()])
        ->assertSeeInOrder(['Guardar alterações', 'Cancelar'])
        ->tap(fn ($page) => expect($page->instance())
            ->getFormActionsAlignment()->toBe(Alignment::End)
            ->areFormActionsSticky()->toBeTrue());
});

it('creates a supplier from the expense form with the full supplier form and picks it', function () {
    Livewire::test(CreateExpense::class)
        ->callAction(TestAction::make('createOption')->schemaComponent('supplier_id', 'form'), [
            'name' => 'Loja Demo',
            'vat_number' => '509999990',
            'website' => 'https://loja.example',
            'seat' => Seat::Portugal->value,
        ])
        ->assertHasNoFormErrors()
        ->assertSchemaStateSet(['supplier_id' => Supplier::query()->where('name', 'Loja Demo')->value('id')]);

    expect(Supplier::query()->where('name', 'Loja Demo')->first())
        ->vat_number->toBe('509999990')
        ->website->toBe('https://loja.example');
});

it('opens a new category on the kind the select lists', function () {
    Livewire::test(CreateProject::class)
        ->callAction(TestAction::make('createOption')->schemaComponent('category_id', 'form'), ['name' => 'Consultoria'])
        ->assertHasNoActionErrors();

    expect(Category::query()->where('name', 'Consultoria')->value('type'))->toBe(CategoryType::Income);
});
