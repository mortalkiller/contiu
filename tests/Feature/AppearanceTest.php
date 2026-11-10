<?php

use App\Filament\Resources\Projects\ProjectResource;
use App\Livewire\AppearanceSettings;
use App\Models\UserPalette;
use App\Support\Appearance\Palette;
use App\Support\Appearance\PaletteRegistry;
use Filament\Facades\Filament;
use Livewire\Livewire;

it('uses the Contiu palette until the user picks another', function () {
    actingAsAdmin();

    $this->get(ProjectResource::getUrl())
        ->assertOk()
        ->assertSee('--contiu-chrome: #0a5b42', escape: false)
        ->assertSee('--contiu-canvas: #e5f3f1', escape: false)
        ->assertSee('brand/contiu/contiu-logo-white.svg', escape: false);
});

it('paints the panel with the palette the user picked', function () {
    $user = actingAsAdmin();

    Livewire::test(AppearanceSettings::class)->call('selectPalette', 'clean-saas-a');

    expect($user->refresh()->palette)->toBe('clean-saas-a');

    $this->get(ProjectResource::getUrl())
        ->assertOk()
        ->assertSee('--contiu-chrome: #111827', escape: false)
        ->assertSee('--contiu-action-green: #2563eb', escape: false)
        ->assertSee('--contiu-canvas: #f3f4f6', escape: false);
});

it('shows the black logo when the navigation bar is light', function () {
    $user = actingAsAdmin();
    $user->palette = 'clean-saas-c';
    $user->save();

    $this->get(ProjectResource::getUrl())
        ->assertOk()
        ->assertSee('--chrome-text: #111111', escape: false)
        ->assertSee('brand/contiu/contiu-logo-black.svg', escape: false);
});

it('stores the default palette as no choice', function () {
    $user = actingAsAdmin();
    $user->palette = 'soft-finance-a';
    $user->save();

    Livewire::test(AppearanceSettings::class)->call('selectPalette', PaletteRegistry::DEFAULT);

    expect($user->refresh()->palette)->toBeNull();
});

it('ignores a palette that does not exist', function () {
    $user = actingAsAdmin();

    Livewire::test(AppearanceSettings::class)->call('selectPalette', 'neon');

    expect($user->refresh()->palette)->toBeNull();
});

it('creates a palette from three colours and starts using it', function () {
    $user = actingAsAdmin();

    Livewire::test(AppearanceSettings::class)
        ->callAction('createPalette', [
            'name' => 'Vinho',
            'primary_color' => '#9f1239',
            'navigation_color' => '#3b0d1a',
            'background_color' => '#faf5f5',
        ]);

    $palette = $user->palettes()->sole();

    expect($palette->name)->toBe('Vinho')
        ->and($user->refresh()->palette)->toBe($palette->key());

    $this->get(ProjectResource::getUrl())
        ->assertOk()
        ->assertSee('--contiu-chrome: #3b0d1a', escape: false)
        ->assertSee('--contiu-action-green: #9f1239', escape: false);
});

it('refuses a colour that is not a hex code', function () {
    $user = actingAsAdmin();

    Livewire::test(AppearanceSettings::class)
        ->callAction('createPalette', [
            'name' => 'Estragada',
            'primary_color' => 'red; } body { display: none',
            'navigation_color' => '#3b0d1a',
            'background_color' => '#faf5f5',
        ])
        ->assertHasActionErrors(['primary_color']);

    expect($user->palettes()->count())->toBe(0);
});

it('does not let a user use or edit someone else\'s palette', function () {
    $user = actingAsAdmin();
    $foreign = UserPalette::factory()->create();

    Livewire::test(AppearanceSettings::class)->call('selectPalette', $foreign->key());

    expect($user->refresh()->palette)->toBeNull();

    $user->palette = $foreign->key();
    $user->save();

    expect(PaletteRegistry::forUser($user)->id)->toBe(PaletteRegistry::DEFAULT);

    Livewire::test(AppearanceSettings::class)
        ->call('mountAction', 'editPalette', ['palette' => $foreign->getKey()])
        ->assertNotFound();
});

it('goes back to Contiu when the palette in use is deleted', function () {
    $user = actingAsAdmin();
    $palette = UserPalette::factory()->for($user)->create();
    $user->palette = $palette->key();
    $user->save();

    Livewire::test(AppearanceSettings::class)
        ->callAction('deletePalette', arguments: ['palette' => $palette->getKey()]);

    expect($user->refresh()->palette)->toBeNull()
        ->and(UserPalette::query()->count())->toBe(0);
});

it('stops offering new palettes at the limit', function () {
    $user = actingAsAdmin();
    UserPalette::factory()->for($user)->count(AppearanceSettings::MAX_CUSTOM_PALETTES)->create();

    Livewire::test(AppearanceSettings::class)->assertActionHidden('createPalette');
});

it('has an Aparência area in the profile', function () {
    actingAsAdmin();

    $this->get(Filament::getProfileUrl(['section' => 'appearance']))
        ->assertOk()
        ->assertSee('Aparência')
        ->assertSee('Paleta de cores')
        ->assertSee('Clean SaaS A')
        ->assertSee('Soft Finance C');
});

it('picks readable text for a navigation colour', function (string $background, string $text) {
    expect(Palette::textOn($background))->toBe($text);
})->with([
    'dark bar' => ['#111827', '#ffffff'],
    'white bar' => ['#ffffff', '#111111'],
    'mint bar' => ['#e8f7ef', '#111111'],
    'money green' => ['#3fa66b', '#111111'],
]);

it('gives every built-in palette readable button text in both modes', function (Palette $palette) {
    expect(Palette::contrast(Palette::textOn($palette->action), $palette->action))->toBeGreaterThanOrEqual(4.5)
        ->and(Palette::contrast(Palette::textOn($palette->actionDark), $palette->actionDark))->toBeGreaterThanOrEqual(4.5);
})->with(fn (): array => PaletteRegistry::builtIn());
