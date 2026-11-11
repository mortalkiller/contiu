<?php

use Illuminate\Support\Facades\Route;

it('shows the branded page for a missing page', function () {
    // Logged in: a guest is sent to the login first, since "/{account}" could be any word.
    actingAsAdmin();

    $this->get('/pagina-que-nao-existe')
        ->assertNotFound()
        ->assertSee('Página não encontrada')
        ->assertSee('brand/contiu/contiu-logo-white.svg', escape: false)
        ->assertSee('Voltar ao painel')
        ->assertDontSee('Not Found');
})->group('ui');

it('shows the branded page when access is denied', function () {
    Route::get('/teste-403', fn () => abort(403))->middleware('web');

    $this->get('/teste-403')->assertForbidden()->assertSee('Sem acesso')->assertSee('Contiu');
})->group('ui');

it('shows the branded page when the session expired', function () {
    Route::get('/teste-419', fn () => abort(419))->middleware('web');

    $this->get('/teste-419')->assertStatus(419)->assertSee('A sessão expirou')->assertSee('Contiu');
})->group('ui');

it('shows the branded page for a server error without debug details', function () {
    config(['app.debug' => false]);
    Route::get('/teste-500', fn () => throw new RuntimeException('segredo interno'))->middleware('web');

    $this->get('/teste-500')
        ->assertStatus(500)
        ->assertSee('Algo correu mal')
        ->assertSee('Contiu')
        ->assertDontSee('segredo interno');
})->group('ui');

it('renders branded views for unauthorized, rate limited and maintenance responses', function (int $code, string $title) {
    expect(view("errors.$code")->render())->toContain($title)->toContain('Contiu');
})->with([
    [401, 'Sessão necessária'],
    [429, 'Demasiados pedidos'],
    [503, 'Em manutenção'],
])->group('integration');
