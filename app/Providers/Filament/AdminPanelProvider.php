<?php

namespace App\Providers\Filament;

use App\Filament\Pages\HelpCentre;
use App\Filament\Support\BrandPalette;
use AzGasim\FilamentUnsavedChangesModal\FilamentUnsavedChangesModalPlugin;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Mortalkiller\FilamentCompleteUserProfile\CompleteUserProfilePlugin;
use MortalKiller\FilamentPageHeader\PageHeaderPlugin;
use ShuvroRoy\FilamentSpatieLaravelBackup\FilamentSpatieLaravelBackupPlugin;
use Tapp\FilamentAuditing\FilamentAuditingPlugin;
use ToneGabes\Filament\Icons\Enums\Phosphor;
use WatheqAlshowaiter\FilamentStickyTableHeader\StickyTableHeaderPlugin;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('')
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login()
            ->passwordReset()
            ->brandName(config('app.name'))
            // Contiu logo: white on the green topbar (both themes); black on the light login page.
            ->brandLogo(fn () => asset(request()->routeIs('filament.admin.auth.*') ? 'brand/contiu/contiu-logo-black.svg' : 'brand/contiu/contiu-logo-white.svg'))
            ->darkModeBrandLogo(asset('brand/contiu/contiu-logo-white.svg'))
            ->brandLogoHeight(fn () => request()->routeIs('filament.admin.auth.*') ? '2.5rem' : '1.6rem')
            ->colors([
                'primary' => BrandPalette::primary(),
                'gray' => BrandPalette::gray(),
                'clay' => BrandPalette::clay(),
                'success' => Color::Emerald,
                'warning' => Color::Amber,
                'danger' => Color::Red,
                'info' => Color::Sky,
            ])
            ->font('Geist')
            ->monoFont('Geist Mono')
            ->unsavedChangesAlerts()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->topNavigation()
            ->databaseNotifications()
            ->databaseNotificationsPolling('60s')
            ->navigationGroups(['Receitas', 'Despesas', 'Contactos', 'Análise', 'Definições'])
            ->userMenuItems([
                'help' => Action::make('help')
                    ->label('Centro de ajuda')
                    ->icon(Phosphor::Question)
                    ->url(fn (): string => HelpCentre::getUrl()),
            ])
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => view('filament.partials.brand-head')->render(),
            )
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => view('filament.partials.page-loader')->render(),
            )
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
                fn (): string => Blade::render('@livewire(\\App\\Livewire\\QuickCreateMenu::class)'),
            )
            ->plugins([
                PageHeaderPlugin::make(),
                CompleteUserProfilePlugin::make(),
                FilamentShieldPlugin::make()
                    ->navigationIcon(Phosphor::IdentificationBadge)
                    ->activeNavigationIcon(Phosphor::IdentificationBadge->fill())
                    ->navigationGroup('Definições')
                    ->navigationSort(10),
                FilamentAuditingPlugin::make(),
                FilamentSpatieLaravelBackupPlugin::make()
                    // This plugin casts enums to ->value ("hard-drives"), so pass the full icon name.
                    ->navigationIcon(Phosphor::HardDrives->getLabel())
                    ->navigationGroup('Definições')
                    ->navigationSort(30),
                FilamentUnsavedChangesModalPlugin::make(),
                // Not ->shouldScrollToTopOnPageChanged(): it scrolls to the first table on EVERY
                // Livewire request (widget polling, notifications…), so pages jumped on their own.
                StickyTableHeaderPlugin::make(),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
