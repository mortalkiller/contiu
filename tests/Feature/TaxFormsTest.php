<?php

use App\Enums\Seat;
use App\Filament\Support\FinanceFields;
use App\Filament\Resources\Clients\Pages\EditClient;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Resources\Expenses\Pages\EditExpense;
use App\Filament\Resources\Suppliers\Pages\ManageSuppliers;
use App\Models\Client;
use App\Models\Expense;
use App\Models\Supplier;
use Filament\Actions\Testing\TestAction;
use Filament\Forms\Components\Select;
use Livewire\Livewire;

beforeEach(fn () => actingAsAdmin());

/** A new expense as the form takes it, for the given supplier. */
function taxFormsExpenseData(Supplier $supplier, array $overrides = []): array
{
    return [
        'supplier_id' => $supplier->id,
        'description' => 'Hosting',
        'spent_on' => today()->toDateString(),
        'net_amount' => 10,
        'vat_rate' => '0.0000',
        'kind' => 'overhead',
        'is_paid' => true,
        'paid_on' => today()->toDateString(),
        'method' => 'card',
        ...$overrides,
    ];
}

it('asks where a supplier is seated and shows it in the list', function () {
    Livewire::test(ManageSuppliers::class)
        ->mountAction('create')
        ->assertFormFieldExists('seat', 'mountedActionSchema0', fn (Select $field): bool => $field->getLabel() === 'Onde fica a sede da empresa?')
        ->assertSchemaStateSet(['seat' => Seat::Portugal], 'mountedActionSchema0')
        ->fillForm(['name' => 'Hetzner', 'seat' => Seat::EuropeanUnion->value])
        ->callMountedAction()
        ->assertHasNoFormErrors();

    expect(Supplier::firstWhere('name', 'Hetzner')->seat)->toBe(Seat::EuropeanUnion);

    Livewire::test(ManageSuppliers::class)
        ->loadTable()
        ->assertSee('Outro país da UE');
});

it('asks the seat when a supplier is created from the expense form', function () {
    Livewire::test(CreateExpense::class)
        ->callAction(TestAction::make('createOption')->schemaComponent('supplier_id'), ['name' => 'OpenAI', 'seat' => Seat::OutsideEu->value])
        ->assertHasNoFormErrors();

    expect(Supplier::firstWhere('name', 'OpenAI')->seat)->toBe(Seat::OutsideEu);
});

it('asks where a client is and whether it is a business', function () {
    $client = Client::factory()->create();

    Livewire::test(EditClient::class, ['record' => $client->getRouteKey()])
        ->assertSee('É uma empresa?')
        ->assertSchemaStateSet(['seat' => Seat::Portugal, 'is_business' => false], 'form')
        ->fillForm(['seat' => Seat::EuropeanUnion->value, 'is_business' => true])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($client->fresh())
        ->seat->toBe(Seat::EuropeanUnion)
        ->is_business->toBeTrue();

    Livewire::test(ListClients::class)
        ->callAction('create', ['name' => 'Agência Lisboa', 'seat' => Seat::Portugal->value, 'is_business' => true])
        ->assertHasNoFormErrors();

    expect(Client::firstWhere('name', 'Agência Lisboa'))
        ->seat->toBe(Seat::Portugal)
        ->is_business->toBeTrue();
});

it('shows the goods question only for foreign suppliers', function () {
    $portuguese = Supplier::factory()->create(['seat' => Seat::Portugal]);
    $european = Supplier::factory()->create(['seat' => Seat::EuropeanUnion]);
    $outsideEu = Supplier::factory()->create(['seat' => Seat::OutsideEu]);

    Livewire::test(CreateExpense::class)
        ->assertFormFieldHidden('is_goods')
        ->fillForm(['supplier_id' => $portuguese->id])
        ->assertFormFieldHidden('is_goods')
        ->fillForm(['supplier_id' => $outsideEu->id])
        ->assertFormFieldVisible('is_goods')
        ->fillForm(taxFormsExpenseData($european, ['description' => 'Monitor', 'is_goods' => true]))
        ->assertFormFieldVisible('is_goods')
        ->assertSee('É uma compra de bens (material)?')
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Expense::firstWhere('description', 'Monitor')->is_goods)->toBeTrue();

    $service = Expense::factory()->for($european, 'supplier')->create(['is_goods' => false]);

    Livewire::test(EditExpense::class, ['record' => $service->getRouteKey()])
        ->assertFormFieldVisible('is_goods')
        ->assertSchemaStateSet(['is_goods' => false], 'form');
});

it('says where to see a supplier\'s seat and what counts as goods', function () {
    expect(FinanceFields::seat()->getHint())->toBe('Onde ver?')
        ->and(FinanceFields::seat()->getHintIconTooltip())->toContain('número de IVA');

    $supplier = Supplier::factory()->create(['seat' => Seat::EuropeanUnion]);

    Livewire::test(CreateExpense::class)
        ->fillForm(['supplier_id' => $supplier->id])
        ->assertSee('Software, alojamento');
});
