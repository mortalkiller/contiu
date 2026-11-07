<?php

use App\Filament\Pages\Dashboard;

it('adds the Contiu page loader to every panel page', function () {
    actingAsAdmin();

    $this->get(Dashboard::getUrl())
        ->assertOk()
        ->assertSee('id="contiu-page-loader"', escape: false)
        ->assertSee('role="status"', escape: false)
        ->assertSee('A carregar…');
});

it('shows the loader on the login page too', function () {
    $this->get('/login')->assertOk()->assertSee('id="contiu-page-loader"', escape: false);
});

it('draws the four pieces of the symbol in sequence', function () {
    $html = view('filament.partials.page-loader')->render();

    expect(substr_count($html, 'class="cpl-piece'))->toBe(4)
        ->and($html)->toContain('prefers-reduced-motion');
});
