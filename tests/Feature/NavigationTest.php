<?php

use Filament\Facades\Filament;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;

function navigation(): array
{
    actingAsAdmin();

    return collect(Filament::getNavigation())
        ->mapWithKeys(fn (NavigationGroup $group) => [(string) ($group->getLabel() ?? '—') => collect($group->getItems())->map(fn (NavigationItem $item) => (string) $item->getLabel())->all()])
        ->all();
}

it('has no Painel or Fecho do mês entries — the logo and the dashboard button lead there', function () {
    $labels = collect(navigation())->flatten()->all();

    expect($labels)->not->toContain('Painel')
        ->not->toContain('Fecho do mês');
});

it('keeps the plugin screens under Definições, with no Plugins menu', function () {
    $navigation = navigation();

    expect($navigation)->not->toHaveKey('Plugins')
        ->and($navigation['Definições'])->toContain('Roles', 'Audits', 'Cópias de Segurança');
});
