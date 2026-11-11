<?php

use App\Filament\Pages\AnnualFiscalReview;
use App\Filament\Pages\IrsScenarioPage;
use App\Filament\Pages\SocialSecurity;
use App\Filament\Pages\TaxEstimate;
use App\Filament\Pages\VatReviewPage;
use App\Filament\Pages\WithholdingReviewPage;
use Filament\Navigation\NavigationItem;

pest()->group('ui', 'fiscal');

beforeEach(fn () => actingAsAdmin());

it('shows the same row of tax tabs on every tax page', function (string $page) {
    $this->get($page::getUrl())
        ->assertOk()
        ->assertSeeInOrder(['Estimativa', 'Segurança Social', 'Revisão de IVA', 'Retenções na fonte', 'Cenário de IRS', 'Resumo anual'])
        ->assertSee(TaxEstimate::getUrl(), escape: false)
        ->assertSee(AnnualFiscalReview::getUrl(), escape: false);
})->with([
    TaxEstimate::class,
    SocialSecurity::class,
    VatReviewPage::class,
    WithholdingReviewPage::class,
    IrsScenarioPage::class,
    AnnualFiscalReview::class,
]);

it('marks the tab of the page that is open', function () {
    $page = Livewire\Livewire::test(SocialSecurity::class)->instance();

    $active = collect($page->getSubNavigation())
        ->filter(fn (NavigationItem $item): bool => $item->isActive())
        ->map(fn (NavigationItem $item): string => (string) $item->getLabel())
        ->values()
        ->all();

    expect($active)->toBe(['Segurança Social']);
});
