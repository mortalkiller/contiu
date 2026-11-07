<?php

use App\Filament\Resources\Projects\ProjectResource;

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
        ->assertSee('brand/contiu/contiu-logo-white.svg', escape: false);
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
