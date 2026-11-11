<?php

use Livewire\Features\SupportTesting\Testable;

/** The page's visible text on one line, so a row of indicators can be matched in order. */
function importScreenText(Testable $page): string
{
    return preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($page->html())));
}
