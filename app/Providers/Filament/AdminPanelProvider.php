<?php

namespace App\Providers\Filament;

use App\Filament\Pages\AccountantReview;
use App\Filament\Pages\Auth\RegisterAccount;
use App\Filament\Pages\HelpCentre;
use App\Filament\Pages\Tenancy\EditAccountProfile;
use App\Filament\Support\AccountantAwareGlobalSearch;
use App\Filament\Support\BrandPalette;
use App\Http\Middleware\ApplyUserPalette;
use App\Http\Middleware\RestrictAccountantRoutes;
use App\Http\Middleware\UseDefaultAccountOutsideTenantRoutes;
use App\Livewire\AppearanceSettings;
use App\Models\Account;
use App\Models\User;
use App\Support\AccountantAccess;
use App\Support\AccountStatus;
use App\Support\Appearance\PaletteRegistry;
use AzGasim\FilamentUnsavedChangesModal\FilamentUnsavedChangesModalPlugin;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Actions\Action;
use Filament\Enums\GlobalSearchPosition;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\SimplePage;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Schemas\Components\Livewire as LivewireComponent;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Livewire\Livewire;
use Mortalkiller\FilamentCompleteUserProfile\AccountSection;
use Mortalkiller\FilamentCompleteUserProfile\CompleteUserProfilePlugin;
use MortalKiller\FilamentPageHeader\PageHeaderPlugin;
use MortalKiller\FilamentSkeletonLoading\SkeletonLoadingPlugin;
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
            // Each account is a tenant: pages live at /{account-slug}/…, and "/" sends the user to
            // their own account. Business resources are scoped by Filament AND by BelongsToAccount.
            // Livewire update requests re-run IdentifyTenant (Filament makes it persistent
            // middleware), so the tenant always comes from the page URL.
            ->tenant(Account::class, slugAttribute: 'slug', ownershipRelationship: 'account')
            ->tenantProfile(EditAccountProfile::class)
            // The account switcher only matters to whoever can open more than one account. Pages
            // outside an account (/profile) have no tenant, and Filament's menu needs one.
            ->tenantMenu(fn (): bool => Filament::getTenant() !== null && static::canSwitchAccounts())
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login()
            // Self-service sign-up: the wizard creates the user and their account, and nothing
            // in the panel opens until the email is confirmed. Off (no route, no link on the login
            // page) until CONTIU_REGISTRATION_OPEN is set.
            ->registration(config('contiu.registration_open') ? RegisterAccount::class : null)
            ->emailVerification()
            ->passwordReset()
            ->brandName(config('app.name'))
            // Contiu logo: white on a dark topbar, black on a light one (the user's palette decides,
            // see Perfil → Aparência); black on the light sign-in/sign-up pages.
            ->brandLogo(fn () => asset(static::onAuthPage() || PaletteRegistry::current()->hasLightNavigation()
                ? 'brand/contiu/contiu-logo-black.svg'
                : 'brand/contiu/contiu-logo-white.svg'))
            ->darkModeBrandLogo(fn () => asset(! static::onAuthPage() && PaletteRegistry::current()->hasLightNavigation(dark: true)
                ? 'brand/contiu/contiu-logo-black.svg'
                : 'brand/contiu/contiu-logo-white.svg'))
            ->brandLogoHeight(fn () => static::onAuthPage() ? '2.5rem' : '1.6rem')
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
            // Sidebar on the left in the palette's navigation colour; the topbar keeps the search,
            // "Novo", notifications and the user menu.
            ->sidebarWidth('15.5rem')
            ->navigation(fn (NavigationBuilder $builder): NavigationBuilder|bool => AccountantAccess::isReviewOnly()
                ? $builder->items(AccountantReview::getNavigationItems())
                : true)
            ->homeUrl(fn (): ?string => AccountantAccess::isReviewOnly() ? AccountantReview::getUrl() : null)
            ->globalSearch(AccountantAwareGlobalSearch::class, position: fn (): GlobalSearchPosition => AccountantAccess::isReviewOnly()
                ? GlobalSearchPosition::Sidebar
                : GlobalSearchPosition::Topbar)
            ->databaseNotifications()
            ->databaseNotificationsPolling('60s')
            // Hoje, Fecho do mês and Calendário come first, outside any group. The groups that
            // are opened less often start closed.
            ->navigationGroups([
                NavigationGroup::make('Receitas'),
                NavigationGroup::make('Despesas'),
                NavigationGroup::make('Banco'),
                NavigationGroup::make('Contactos'),
                NavigationGroup::make('Impostos')->collapsed(),
                NavigationGroup::make('Análise')->collapsed(),
                NavigationGroup::make('Definições')->collapsed(),
            ])
            ->userMenuItems([
                'help' => Action::make('help')
                    ->label('Centro de ajuda')
                    ->icon(Phosphor::Question)
                    ->url(fn (): string => HelpCentre::getUrl())
                    ->visible(fn (): bool => ! AccountantAccess::isReviewOnly())
                    ->sort(-1),
            ])
            ->renderHook(
                PanelsRenderHook::USER_MENU_PROFILE_BEFORE,
                fn (): string => static::renderUserMenuHeader(),
            )
            ->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => view('filament.partials.brand-head')->render(),
            )
            // Trial days left, or that the account is read-only (App\Support\AccountStatus).
            ->renderHook(
                PanelsRenderHook::CONTENT_START,
                fn (): string => AccountantAccess::isReviewOnly() || ($banner = AccountStatus::banner()) === null
                    ? ''
                    : view('filament.partials.account-status-banner', ['banner' => $banner])->render(),
            )
            // Phone: tables become lists of cards; this labels each cell with its column heading.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => view('filament.partials.mobile-tables')->render(),
            )
            // Phone and tablet: shortcuts at the bottom of the screen, as in a phone app.
            ->renderHook(
                PanelsRenderHook::BODY_END,
                fn (): string => static::showsMobileTabBar() ? view('filament.partials.mobile-tab-bar')->render() : '',
            )
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
                fn (): string => AccountantAccess::isReviewOnly() ? '' : Blade::render('@livewire(\\App\\Livewire\\QuickCreateMenu::class)'),
            )
            ->plugins([
                SkeletonLoadingPlugin::make()
                    ->pageLoader()
                    ->pageLoaderSymbol(fn () => view('filament.partials.contiu-symbol'))
                    ->pageLoaderLabel('A carregar…'),
                PageHeaderPlugin::make(),
                CompleteUserProfilePlugin::make()
                    // After "Segurança" (30): light/dark mode and the colour palette.
                    ->section(
                        AccountSection::make('appearance')
                            ->label('Aparência')
                            ->description('Escolhe as cores e o modo da interface.')
                            ->sort(35)
                            ->schema([LivewireComponent::make(AppearanceSettings::class)]),
                    ),
                FilamentShieldPlugin::make()
                    // Roles are global (no Shield teams); RoleResource lets only super admins in.
                    ->scopeToTenant(false)
                    ->navigationIcon(Phosphor::IdentificationBadge)
                    ->activeNavigationIcon(Phosphor::IdentificationBadge->fill())
                    ->navigationGroup('Definições')
                    ->navigationSort(10),
                FilamentAuditingPlugin::make(),
                FilamentSpatieLaravelBackupPlugin::make()
                    // A dump holds every account's data.
                    ->authorize(fn (): bool => User::loggedInIsSuperAdmin())
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
                UseDefaultAccountOutsideTenantRoutes::class,
                // The user's colour palette, on top of the Contiu colours registered above.
                ApplyUserPalette::class,
            ])
            // Also on Livewire updates from those pages (e.g. saving /profile).
            ->persistentMiddleware([UseDefaultAccountOutsideTenantRoutes::class, ApplyUserPalette::class])
            ->tenantMiddleware([RestrictAccountantRoutes::class], isPersistent: true);
    }

    /**
     * Sign-in, sign-up and email confirmation are simple pages on a light background. Checked by
     * the component too: on their Livewire updates (each sign-up step) the route is Livewire's.
     */
    private static function onAuthPage(): bool
    {
        return request()->routeIs('filament.admin.auth.*') || Livewire::current() instanceof SimplePage;
    }

    /**
     * Inside an account, for whoever uses the whole panel: not on sign-in pages, and not for an
     * accountant in review-only mode (their menu is a single screen).
     */
    private static function showsMobileTabBar(): bool
    {
        return Filament::auth()->check()
            && Filament::getTenant() !== null
            && ! static::onAuthPage()
            && ! AccountantAccess::isReviewOnly();
    }

    private static function canSwitchAccounts(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User
            && ($user->isSuperAdmin() ? Account::count() : $user->accounts()->count()) > 1;
    }

    private static function renderUserMenuHeader(): string
    {
        $user = Filament::auth()->user();
        $account = Filament::getTenant();

        if (! $user instanceof User) {
            return '';
        }

        $role = $account instanceof Account
            ? $user->accounts()->whereKey($account->getKey())->value('account_user.role')
            : null;

        $roleLabel = match ($role) {
            'owner' => 'Proprietário',
            'member' => 'Membro',
            AccountantAccess::ROLE => 'Contabilista',
            null => $account instanceof Account && $user->isSuperAdmin() ? 'Superadministrador' : null,
            default => $role,
        };

        return view('filament.partials.user-menu-header', [
            'user' => $user,
            'accountName' => $account instanceof Account ? $account->name : null,
            'roleLabel' => $roleLabel,
            'accountProfileUrl' => $account instanceof Account && EditAccountProfile::canView($account)
                ? EditAccountProfile::getUrl(tenant: $account)
                : null,
        ])->render();
    }
}
