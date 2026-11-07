<?php

use App\Filament\Pages\Dashboard;

it('adds the Contiu page loader to every panel page', function () {
    actingAsAdmin();

    $this->get(Dashboard::getUrl())
        ->assertOk()
        ->assertSee('id="fsl-page-loader"', escape: false)
        ->assertSee('role="status"', escape: false)
        ->assertSee('A carregar…');
});

it('shows the loader on the login page too', function () {
    $this->get('/login')->assertOk()->assertSee('id="fsl-page-loader"', escape: false);
});

it('draws the four pieces of the symbol in sequence', function () {
    $html = view('filament.partials.contiu-symbol')->render();

    expect(substr_count($html, 'fsl-loader-piece'))->toBe(4);
});

it('draws the Contiu symbol in the page loader as markup, not as text', function () {
    actingAsAdmin();

    $html = $this->get(Dashboard::getUrl())->getContent();

    expect(substr_count($html, 'class="fsl-loader-piece"'))->toBe(4)
        ->and($html)->not->toContain('&lt;path class=');
});
