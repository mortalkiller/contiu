<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Receivables\ReceivableResource;
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

it('opens with Hoje, Fecho do mês and Calendário, outside any group', function () {
    expect(array_slice(navigation()['—'], 0, 3))->toBe(['Hoje', 'Fecho do mês', 'Calendário']);
});

it('groups the bank statement and the tax pages on their own', function () {
    $navigation = navigation();

    expect($navigation['Banco'])->toBe(['Extrato bancário'])
        ->and($navigation['Impostos'])->toContain('Estimativa de impostos', 'Segurança Social', 'Revisão de IVA', 'Retenções na fonte', 'Cenário de IRS');
});

it('keeps the plugin screens under Definições, with no Plugins menu', function () {
    $navigation = navigation();

    expect($navigation)->not->toHaveKey('Plugins')
        ->and($navigation['Definições'])->toContain('Roles', 'Audits', 'Cópias de Segurança');
});

it('gives phones a tab bar with the main places and a "+" for new records', function () {
    actingAsAdmin();

    $this->get(Dashboard::getUrl())
        ->assertOk()
        ->assertSee('contiu-tab-bar', escape: false)
        ->assertSee(ReceivableResource::getUrl(), escape: false)
        ->assertSee(ExpenseResource::getUrl(), escape: false)
        ->assertSee('fi-quick-create-fab', escape: false)
        ->assertSee('Mais');
});

it('has no tab bar on the sign-in page', function () {
    $this->get(route('filament.admin.auth.login'))
        ->assertOk()
        ->assertDontSee('contiu-tab-bar', escape: false);
});
