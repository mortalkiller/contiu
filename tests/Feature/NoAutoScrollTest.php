<?php

use App\Filament\Resources\Projects\ProjectResource;

/*
 * Regression: StickyTableHeaderPlugin::shouldScrollToTopOnPageChanged() hooks EVERY Livewire request
 * (widget polling every 5s, notifications polling, live form fields…) and scrolls the first table
 * into view, so pages kept jumping to a table on their own.
 */
it('does not scroll the page to a table on background requests', function () {
    actingAsAdmin();

    $this->get(ProjectResource::getUrl())
        ->assertOk()
        ->assertDontSee('table.scrollIntoView', escape: false);
});
