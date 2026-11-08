<?php

use Filament\Facades\Filament;

/*
 * Public sign-up opens with CONTIU_REGISTRATION_OPEN. Filament builds the /register route as the
 * app boots, so the "registration-open" group boots the app with it on (tests/TestCase.php).
 */

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    asAWebRequest();
});

it('keeps sign-up closed by default', function () {
    expect(config('contiu.registration_open'))->toBeFalse();

    $this->get('/register')->assertNotFound();

    $this->get('/login')->assertOk()
        ->assertDontSee(__('filament-panels::auth/pages/login.actions.register.label'))
        ->assertDontSee(url('/register'), escape: false);
});

it('opens sign-up when the switch is on', function () {
    expect(config('contiu.registration_open'))->toBeTrue();

    $this->get('/register')->assertOk()->assertSee('Criar conta');

    $this->get('/login')->assertOk()
        ->assertSee(__('filament-panels::auth/pages/login.actions.register.label'))
        ->assertSee(url('/register'), escape: false);
})->group('registration-open');
