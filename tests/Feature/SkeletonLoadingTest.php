<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Expenses\Pages\ListExpenses;
use App\Filament\Widgets\CashFlowChart;
use App\Filament\Widgets\FinanceStatsOverview;
use App\Filament\Widgets\IncomeByCategoryChart;
use App\Filament\Widgets\UpcomingReceivablesTable;
use App\Models\Expense;
use Filament\Support\Facades\FilamentAsset;
use Filament\Widgets\Widget;
use Livewire\Livewire;
use MortalKiller\FilamentSkeletonLoading\SkeletonLoadingPlugin;

beforeEach(fn () => actingAsAdmin());

function placeholderOf(string $widget): string
{
    /** @var Widget $instance */
    $instance = app($widget);

    return $instance->placeholder()->render();
}

it('registers the skeleton plugin and its stylesheet on the panel', function () {
    expect(filament()->getPanel('admin')->hasPlugin(SkeletonLoadingPlugin::ID))->toBeTrue()
        ->and(FilamentAsset::getStyles([SkeletonLoadingPlugin::PACKAGE]))->not->toBeEmpty();

    $this->get(Dashboard::getUrl())->assertOk()->assertSee('skeleton-loading.css', escape: false);
});

it('draws a stats skeleton with one tile per stat column', function () {
    $html = placeholderOf(FinanceStatsOverview::class);

    expect($html)->toContain('fsl-skeleton--stats')
        ->and(substr_count($html, 'fsl-stat'))->toBe(4);
});

it('draws a bar chart skeleton with the real heading', function () {
    $html = placeholderOf(CashFlowChart::class);

    expect($html)->toContain('fsl-skeleton--chart')
        ->toContain('fsl-chart-bars')
        ->toContain(e(app(CashFlowChart::class)->getHeading()));
});

it('draws a ring for doughnut charts', function () {
    expect(placeholderOf(IncomeByCategoryChart::class))->toContain('fsl-chart-ring');
});

it('draws table rows for table widgets', function () {
    $html = placeholderOf(UpcomingReceivablesTable::class);

    expect($html)->toContain('fsl-skeleton--table')
        ->and(substr_count($html, 'fsl-row'))->toBe(5);
});

it('replaces the plain grey box of any lazy section with a generic skeleton', function () {
    $html = view('filament::components.loading-section', ['height' => '10rem'])->render();

    expect($html)->toContain('fsl-skeleton--generic')
        ->toContain('aria-busy="true"');
});

it('is announced to screen readers and keeps still for reduced motion', function () {
    expect(placeholderOf(CashFlowChart::class))->toContain('role="status"')->toContain('fi-sr-only');

    $css = file_get_contents(base_path('packages/mortalkiller/filament-skeleton-loading/resources/css/skeleton-loading.css'));

    expect($css)->toContain('prefers-reduced-motion')->toContain('fi-ta-table-loading-ctn');
});

it('defers the expenses list and shows its rows once loaded', function () {
    $expense = Expense::factory()->create();

    Livewire::test(ListExpenses::class)
        ->assertSee('fi-ta-table-loading-ctn', escape: false)
        ->loadTable()
        ->assertCanSeeTableRecords([$expense]);
});
