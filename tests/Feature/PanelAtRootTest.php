<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Projects\ProjectResource;

pest()->group('tenancy');

it('sends a guest from the root straight to the login', function () {
    $this->get('/app')->assertRedirect('/app/login');
})->group('ui');

it('shows the login page at /app/login', function () {
    $this->get('/app/login')->assertOk()->assertSee('Entra na Contiu');
})->group('ui');

it('sends a logged-in user from the root to their account\'s dashboard', function () {
    actingAsAdmin();
    $slug = test()->account->slug;

    expect(Dashboard::getUrl())->toBe(url("/app/{$slug}"));

    $this->get('/app')->assertRedirect(url("/app/{$slug}"));
    $this->get("/app/{$slug}")->assertOk()->assertDontSee('Laravel');
})->group('ui');

it('serves the panel pages without the /admin prefix, inside the account', function () {
    actingAsAdmin();

    expect(ProjectResource::getUrl())->toBe(url('/app/'.test()->account->slug.'/projects'));
})->group('integration');
