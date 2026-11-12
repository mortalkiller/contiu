<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Projects\ProjectResource;

pest()->group('tenancy');

it('sends a guest from the root straight to the login', function () {
    $this->get('/')->assertRedirect('/login');
})->group('ui');

it('shows the login page at /login', function () {
    $this->get('/login')->assertOk()->assertSee('Entra na Contiu');
})->group('ui');

it('sends a logged-in user from the root to their account\'s dashboard', function () {
    actingAsAdmin();
    $slug = test()->account->slug;

    expect(Dashboard::getUrl())->toBe(url("/{$slug}"));

    $this->get('/')->assertRedirect(url("/{$slug}"));
    $this->get("/{$slug}")->assertOk()->assertDontSee('Laravel');
})->group('ui');

it('serves the panel pages without the /admin prefix, inside the account', function () {
    actingAsAdmin();

    expect(ProjectResource::getUrl())->toBe(url('/'.test()->account->slug.'/projects'));
})->group('integration');
