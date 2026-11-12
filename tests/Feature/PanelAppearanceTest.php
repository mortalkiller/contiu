<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Projects\ProjectResource;

pest()->group('ui');

it('shows the white Contiu logo on the green topbar and the brand favicons', function () {
    actingAsAdmin();

    $this->get(ProjectResource::getUrl())
        ->assertOk()
        ->assertSee('brand/contiu/contiu-logo-white.svg', escape: false)
        ->assertSee('brand/contiu/favicon.ico', escape: false)
        ->assertSee('brand/contiu/favicon.svg', escape: false)
        ->assertSee('brand/contiu/apple-touch-icon.png', escape: false)
        ->assertSee('brand/contiu/site.webmanifest', escape: false);
});

it('shows the black logo on the light login page, white in dark mode', function () {
    $this->get(route('filament.admin.auth.login'))
        ->assertOk()
        ->assertSee('brand/contiu/contiu-logo-black.svg', escape: false)
        ->assertSee('brand/contiu/contiu-logo-white.svg', escape: false)
        ->assertSee('contiu-auth-logo-dark', escape: false)
        ->assertSee('id="fsl-page-loader"', escape: false);
});

it('ships the brand files', function (string $file) {
    expect(public_path('brand/contiu/'.$file))->toBeFile();
})->with(['contiu-logo-black.svg', 'contiu-logo-white.svg', 'contiu-symbol-white.svg', 'favicon.ico', 'favicon.svg', 'apple-touch-icon.png', 'icon-192.png', 'icon-512.png', 'site.webmanifest']);

it('is called Contiu', function () {
    actingAsAdmin();

    expect(config('app.name'))->toBe('Contiu');

    $html = preg_replace('/\s+/', ' ', $this->get(ProjectResource::getUrl())->getContent());

    expect($html)->toContain('<title> Trabalhos - Contiu </title>');
});

it('renders the Contiu page loader with its inline symbol on the dashboard', function () {
    actingAsAdmin();

    $response = $this->get(Dashboard::getUrl())
        ->assertOk()
        ->assertSee('id="fsl-page-loader"', escape: false)
        ->assertSee('role="status"', escape: false)
        ->assertSee('A carregar…')
        ->assertSee('class="contiu-loader-c"', escape: false);

    $html = $response->getContent();

    expect(substr_count($html, 'class="contiu-loader-c"'))->toBe(1)
        ->and($html)->not->toContain('&lt;path class=');
});
