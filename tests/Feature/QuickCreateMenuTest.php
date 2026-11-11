<?php

use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Retainers\RetainerResource;
use App\Livewire\QuickCreateMenu;
use App\Models\Receivable;
use Livewire\Livewire;

pest()->group('ui');

beforeEach(fn () => actingAsAdmin());

it('is in the topbar of every panel page', function () {
    $this->get(ProjectResource::getUrl())->assertSeeLivewire(QuickCreateMenu::class);
});

it('offers the common shortcuts', function () {
    Receivable::factory()->create(); // "Registar recebimento" only shows when something is open

    Livewire::test(QuickCreateMenu::class)
        ->assertSee('Registar recebimento')
        ->assertSee(ProjectResource::getUrl('create'), escape: false)
        ->assertSee(RetainerResource::getUrl('create'), escape: false)
        ->assertSee(ExpenseResource::getUrl('create'), escape: false)
        ->assertSee(ClientResource::getUrl(), escape: false);
});

it('records a payment without leaving the current page', function () {
    $receivable = Receivable::factory()->create(['amount' => 12000]);

    Livewire::test(QuickCreateMenu::class)
        ->callAction('recordPayment', data: [
            'receivable_id' => $receivable->id,
            'paid_on' => today()->toDateString(),
            'amount' => 120,
            'method' => 'mbway',
        ])
        ->assertHasNoErrors();

    expect($receivable->fresh()->outstanding)->toBe(0);
});
