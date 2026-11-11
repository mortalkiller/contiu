<?php

use App\Filament\Pages\AnnualFiscalReview;
use App\Filament\Pages\IrsScenarioPage;
use App\Filament\Pages\SocialSecurity;
use App\Filament\Pages\TaxEstimate;
use App\Filament\Pages\VatReviewPage;
use App\Filament\Pages\WithholdingReviewPage;
use App\Support\FinanceSettings;
use Carbon\CarbonImmutable;
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

it('counts the late Social Security obligations on its tab', function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-10'));
    app(FinanceSettings::class)->set(['activity_started_on' => '2020-01-01', 'activity_start_kind' => 'first', 'obligations_tracked_from' => '2026-10-01']);

    $badges = collect(Livewire\Livewire::test(TaxEstimate::class)->instance()->getSubNavigation())
        ->mapWithKeys(fn (NavigationItem $item): array => [(string) $item->getLabel() => $item->getBadge()]);

    expect($badges['Segurança Social'])->toBe('2')
        ->and($badges['Estimativa'])->toBeNull();
});
