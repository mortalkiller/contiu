<?php

use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Support\ContiuPalettes;
use Filament\Facades\Filament;
use Livewire\Livewire;
use MortalKiller\FilamentAppearance\AppearancePlugin;
use MortalKiller\FilamentAppearance\Livewire\PalettePicker;
use MortalKiller\FilamentAppearance\Models\CustomPalette;
use MortalKiller\FilamentAppearance\Palette;

pest()->group('ui');

it('uses the Contiu palette until the user picks another', function () {
    actingAsAdmin();

    $this->get(ProjectResource::getUrl())
        ->assertOk()
        ->assertSee('--fap-navigation: #0a5b42', escape: false)
        ->assertSee('--fap-background: #e5f3f1', escape: false)
        ->assertSee('brand/contiu/contiu-logo-white.svg', escape: false);
});

it('paints the panel with the palette the user picked', function () {
    $user = actingAsAdmin();

    Livewire::test(PalettePicker::class)->call('selectPalette', 'clean-saas-a');

    expect($user->refresh()->palette)->toBe('clean-saas-a');

    $this->get(ProjectResource::getUrl())
        ->assertOk()
        ->assertSee('--fap-navigation: #111827', escape: false)
        ->assertSee('--fap-action: #2563eb', escape: false)
        ->assertSee('--fap-background: #f3f4f6', escape: false);
});

it('shows the black logo when the navigation bar is light', function () {
    $user = actingAsAdmin();
    $user->palette = 'clean-saas-c';
    $user->save();

    $this->get(ProjectResource::getUrl())
        ->assertOk()
        ->assertSee('--fap-navigation-text: #111111', escape: false)
        ->assertSee('brand/contiu/contiu-logo-black.svg', escape: false);
});

it('stores the default palette as no choice', function () {
    $user = actingAsAdmin();
    $user->palette = 'soft-finance-a';
    $user->save();

    Livewire::test(PalettePicker::class)->call('selectPalette', ContiuPalettes::DEFAULT);

    expect($user->refresh()->palette)->toBeNull();
});

it('ignores a palette that does not exist', function () {
    $user = actingAsAdmin();

    Livewire::test(PalettePicker::class)->call('selectPalette', 'neon');

    expect($user->refresh()->palette)->toBeNull();
});

it('creates a palette from three colours and starts using it', function () {
    $user = actingAsAdmin();

    Livewire::test(PalettePicker::class)
        ->callAction('createPalette', [
            'name' => 'Vinho',
            'primary_color' => '#9f1239',
            'navigation_color' => '#3b0d1a',
            'background_color' => '#faf5f5',
        ]);

    $palette = $user->customPalettes()->sole();

    expect($palette->name)->toBe('Vinho')
        ->and($user->refresh()->palette)->toBe($palette->key());

    $this->get(ProjectResource::getUrl())
        ->assertOk()
        ->assertSee('--fap-navigation: #3b0d1a', escape: false)
        ->assertSee('--fap-action: #9f1239', escape: false);
});

it('refuses a colour that is not a hex code', function () {
    $user = actingAsAdmin();

    Livewire::test(PalettePicker::class)
        ->callAction('createPalette', [
            'name' => 'Estragada',
            'primary_color' => 'red; } body { display: none',
            'navigation_color' => '#3b0d1a',
            'background_color' => '#faf5f5',
        ])
        ->assertHasActionErrors(['primary_color']);

    expect($user->customPalettes()->count())->toBe(0);
});

it('does not let a user use or edit someone else\'s palette', function () {
    $user = actingAsAdmin();
    $foreign = CustomPalette::factory()->create();

    Livewire::test(PalettePicker::class)->call('selectPalette', $foreign->key());

    expect($user->refresh()->palette)->toBeNull();

    $user->palette = $foreign->key();
    $user->save();

    expect($user->currentPalette()->getId())->toBe(ContiuPalettes::DEFAULT);

    Livewire::test(PalettePicker::class)
        ->call('mountAction', 'editPalette', ['palette' => $foreign->getKey()])
        ->assertNotFound();
});

it('goes back to Contiu when the palette in use is deleted', function () {
    $user = actingAsAdmin();
    $palette = CustomPalette::factory()->for($user)->create();
    $user->palette = $palette->key();
    $user->save();

    Livewire::test(PalettePicker::class)
        ->callAction('deletePalette', arguments: ['palette' => $palette->getKey()]);

    expect($user->refresh()->palette)->toBeNull()
        ->and(CustomPalette::query()->count())->toBe(0);
});

it('stops offering new palettes at the limit', function () {
    $user = actingAsAdmin();
    CustomPalette::factory()->for($user)->count(AppearancePlugin::get()->getCustomPalettesLimit())->create();

    Livewire::test(PalettePicker::class)->assertActionHidden('createPalette');
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

it('gives every built-in palette readable button text in both modes', function () {
    foreach (ContiuPalettes::all() as $palette) {
        expect(Palette::contrast(Palette::textOn($palette->getAction()), $palette->getAction()))->toBeGreaterThanOrEqual(4.5)
            ->and(Palette::contrast(Palette::textOn($palette->getAction(dark: true)), $palette->getAction(dark: true)))->toBeGreaterThanOrEqual(4.5);
    }
});

it('keeps the palette logo on the profile page, which is not a sign-in page', function () {
    actingAsAdmin();

    $this->get(Filament::getProfileUrl(['section' => 'appearance']))
        ->assertOk()
        ->assertSee('brand/contiu/contiu-logo-white.svg', escape: false)
        ->assertDontSee('brand/contiu/contiu-logo-black.svg', escape: false)
        ->assertSee('height: 2rem', escape: false);
});

it('paints the black-and-white palette with a black bar and buttons, near-white buttons in dark mode', function () {
    $user = actingAsAdmin();
    $user->palette = 'black-white';
    $user->save();

    $this->get(ProjectResource::getUrl())
        ->assertOk()
        ->assertSee('--fap-navigation: #0a0a0a', escape: false)
        ->assertSee('--fap-action: #0a0a0a', escape: false)
        ->assertSee('--fap-on-action: #ffffff', escape: false);

    expect(collect(ContiuPalettes::all())->firstWhere(fn (Palette $palette): bool => $palette->getId() === 'black-white')->getAction(dark: true))->toBe('#fafafa');
});
