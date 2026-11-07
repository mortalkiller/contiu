<?php

use App\Filament\Resources\Clients\ClientResource;
use App\Models\Client;
use Tapp\FilamentAuditing\RelationManagers\AuditsRelationManager;

it('records changes to a client', function () {
    config(['audit.console' => true]);
    actingAsAdmin();

    $client = Client::factory()->create(['email' => null]);
    $client->update(['email' => 'novo@example.pt']);

    expect($client->audits()->latest('id')->first())
        ->event->toBe('updated')
        ->new_values->toMatchArray(['email' => 'novo@example.pt']);
});

it('shows the history tab on the client', function () {
    expect(ClientResource::getRelations())->toContain(AuditsRelationManager::class);
});
