<?php

use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Models\Payment;

pest()->group('ui');

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
