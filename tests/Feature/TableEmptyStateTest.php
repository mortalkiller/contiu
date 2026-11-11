<?php

use App\Filament\Resources\RecurringExpenses\Pages\ManageRecurringExpenses;
use App\Models\RecurringExpense;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(fn () => actingAsAdmin());

it('invites to create the first recurring expense while there is none', function () {
    Livewire::test(ManageRecurringExpenses::class)
        ->loadTable()
        ->assertSee('Ainda não tens despesas recorrentes')
        ->assertSee('Passam a aparecer na previsão de tesouraria')
        ->assertActionHidden(TestAction::make('clearTableNarrowing')->table());
})->group('ui');

it('offers to clear the search when it hides every record, instead of creating', function () {
    RecurringExpense::factory()->create(['description' => 'Alojamento']);

    Livewire::test(ManageRecurringExpenses::class)
        ->loadTable()
        ->searchTable('adobe')
        ->assertSee('Nada em despesas recorrentes corresponde a «adobe»')
        ->assertActionVisible(TestAction::make('clearTableNarrowing')->table())
        ->callAction(TestAction::make('clearTableNarrowing')->table())
        ->assertSet('tableSearch', '')
        ->assertSee('Alojamento');
})->group('ui');
