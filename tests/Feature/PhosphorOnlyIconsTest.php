<?php

use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Payment;

/** Heroicons are 24×24 SVGs; Filament's own loading spinners are the only 24×24 ones allowed. */
function heroiconsOn(string $html): int
{
    preg_match_all('/<svg\b[^>]*viewBox="0 0 24 24"[^>]*>/', $html, $matches);

    return collect($matches[0])->reject(fn (string $tag) => str_contains($tag, 'fi-loading-indicator'))->count();
}

it('shows only Phosphor icons on list pages, including the Excel export buttons', function (string $resource) {
    actingAsAdmin();
    Payment::factory()->create();

    // Resolved after logging in: the URL needs the account (tenant).
    $html = $this->get($resource::getUrl())->assertOk()->getContent();

    expect(heroiconsOn($html))->toBe(0);
})->with([
    'trabalhos' => ProjectResource::class,
    'recebimentos' => PaymentResource::class,
]);
