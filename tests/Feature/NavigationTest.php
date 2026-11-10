<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Receivables\ReceivableResource;
use App\Support\MobileMenu;
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

it('labels table cells with their column heading, for the phone list layout', function () {
    actingAsAdmin();

    $this->get(ExpenseResource::getUrl())
        ->assertOk()
        ->assertSee('cell.dataset.label', escape: false);
});

it('builds the phone menu from the navigation: open groups as tiles, collapsed groups as rows', function () {
    actingAsAdmin();

    $sections = collect(MobileMenu::sections())->keyBy(fn (array $section): string => $section['label'] ?? '—');

    expect($sections['Receitas']['collapsed'])->toBeFalse()
        ->and(array_column($sections['Receitas']['items'], 'label'))->toContain('A receber', 'Trabalhos', 'Avenças')
        ->and($sections['Impostos']['collapsed'])->toBeTrue()
        ->and($sections['Definições']['collapsed'])->toBeTrue()
        ->and($sections['—']['collapsed'])->toBeFalse();
});

it('leaves the pages already on the tab bar out of the phone menu', function () {
    actingAsAdmin();

    $labels = collect(MobileMenu::sections(excludedUrls: [
        Dashboard::getUrl(),
        ReceivableResource::getUrl(),
    ]))->flatMap(fn (array $section): array => array_column($section['items'], 'label'));

    expect($labels)->not->toContain('Hoje')
        ->not->toContain('A receber')
        ->toContain('Fecho do mês', 'Trabalhos');
});

it('puts the phone menu sheet on the page, with tiles and a row per collapsed group', function () {
    actingAsAdmin();

    $this->get(Dashboard::getUrl())
        ->assertOk()
        ->assertSee('contiu-menu-sheet', escape: false)
        ->assertSee('contiu-menu-tile', escape: false)
        ->assertSee('contiu-menu-row', escape: false)
        ->assertSee('Ir para…');
});
