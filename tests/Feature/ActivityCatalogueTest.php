<?php

use App\Support\ActivityCatalogue;

/*
 * "O que fazes?": the occupations of the art. 151.º CIRS table and the CAE-Rev.4 subclasses, from
 * the official texts in database/data, and the coefficient they lead to (SIM001–SIM003, B008).
 */

it('has the whole art. 151 table and CAE-Rev.4', function () {
    expect(ActivityCatalogue::occupations())->toHaveCount(90)
        ->and(ActivityCatalogue::occupations()['1332'])->toBe('Programadores informáticos')
        ->and(ActivityCatalogue::occupations()['1336'])->toBe('Designers')
        ->and(ActivityCatalogue::caeLabel('62100'))->toBe('62100 · Atividades de programação informática')
        ->and(ActivityCatalogue::caeLabel('01111'))->toBe('01111 · Cerealicultura (exceto arroz)')
        ->and(ActivityCatalogue::caeExists('99999'))->toBeFalse()
        ->and(count(ActivityCatalogue::searchCae('', limit: 2000)))->toBe(915);
});

it('gives 0.75 only to an occupation specifically on the table', function (?string $kind, ?string $occupation, ?string $workKind) {
    expect(ActivityCatalogue::workKind($kind, $occupation))->toBe($workKind);
})->with([
    'programmer' => ['technology', '1332', 'listed'],
    'designer under another kind' => ['health', '1336', 'listed'],
    'the residual 1519' => ['other_services', '1519', 'other'],
    'not on the list' => ['technology', 'other', 'other'],
    'selling products' => ['goods', null, 'goods'],
    'restaurants' => ['food_lodging', '1332', 'goods'],
    'nothing chosen' => [null, null, null],
]);

it('lists the occupations of the kind first and ends with “not on the list”', function () {
    $options = ActivityCatalogue::occupationOptions('technology');

    expect(array_slice(array_map('strval', array_keys($options)), 0, 2))->toBe(['1332', '1313'])
        ->and(array_key_last($options))->toBe(ActivityCatalogue::OTHER_OCCUPATION)
        ->and($options)->not->toHaveKey('1519')
        ->and($options)->toHaveCount(90);
});

it('finds a CAE by its number or the words of its name, those of the kind first', function () {
    expect(array_map('strval', array_keys(ActivityCatalogue::searchCae('621'))))->toBe(['62100'])
        ->and(ActivityCatalogue::searchCae('programacao informatica', 'technology'))->toHaveKey('62100')
        ->and(array_key_first(ActivityCatalogue::searchCae('program', 'technology')))->toBe(62100)
        ->and(array_key_first(ActivityCatalogue::searchCae('cafe', 'food_lodging')))->toBe(56301);
});
