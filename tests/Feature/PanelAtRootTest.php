<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Projects\ProjectResource;

it('sends a guest from the root straight to the login', function () {
    $this->get('/')->assertRedirect('/login');
});

it('shows the login page at /login', function () {
    $this->get('/login')->assertOk()->assertSee('Iniciar sessão');
});

it('opens the dashboard at the root once logged in', function () {
    actingAsAdmin();

    expect(Dashboard::getUrl())->toBe(url('/'));

    $this->get('/')->assertOk()->assertDontSee('Laravel');
});

it('serves the panel pages without the /admin prefix', function () {
    expect(ProjectResource::getUrl())->toBe(url('/projects'));
});
