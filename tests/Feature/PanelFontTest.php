<?php

use App\Filament\Resources\Projects\ProjectResource;

it('uses Geist and Geist Mono across the panel', function () {
    actingAsAdmin();

    $this->get(ProjectResource::getUrl())
        ->assertOk()
        ->assertSee('family=geist:', escape: false)
        ->assertSee('family=geist-mono:', escape: false)
        ->assertDontSee('instrument-sans', escape: false);
});
