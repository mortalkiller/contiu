<?php

use App\Filament\Resources\Roles\RoleResource;

pest()->group('ui');

it('uses no Heroicons on the role form', function () {
    actingAsAdmin();

    $html = $this->get(RoleResource::getUrl('create'))->assertOk()->getContent();

    preg_match_all('/<svg[^>]*viewBox="0 0 24 24"[^>]*>/', $html, $matches);
    $heroicons = array_filter($matches[0], fn (string $svg) => ! str_contains($svg, 'fi-loading-indicator') && ! str_contains($svg, 'animate-spin'));

    expect($heroicons)->toBe([]);
});
