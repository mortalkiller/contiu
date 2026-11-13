<?php

namespace App\Providers\Filament;

use App\Enums\Language;
use App\Filament\Pages\AccountantReview;
use App\Filament\Pages\AccountSubscription;
use App\Filament\Pages\Auth\EditUserProfile;
use App\Filament\Pages\Auth\RegisterAccount;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\HelpCentre;
use App\Filament\Pages\Tenancy\EditAccountProfile;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Receivables\ReceivableResource;
use App\Filament\Support\AccountantAwareGlobalSearch;
use App\Filament\Support\BrandPalette;
use App\Filament\Support\ContiuPalettes;
use App\Http\Controllers\InvoiceBenchmarkPreviewController;
use App\Http\Middleware\RestrictAccountantRoutes;
use App\Http\Middleware\UseDefaultAccountOutsideTenantRoutes;
use App\Models\Account;
use App\Models\User;
use App\Support\AccountantAccess;
use App\Support\AccountStatus;
use AzGasim\FilamentUnsavedChangesModal\FilamentUnsavedChangesModalPlugin;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Actions\Action;
use Filament\Enums\GlobalSearchPosition;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationBuilder;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Platform;
use Filament\Support\Enums\Width;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Livewire\Livewire;
use MortalKiller\FilamentAppearance\AppearancePlugin;
use MortalKiller\FilamentAppearance\Schemas\Components\PalettePicker;
use Mortalkiller\FilamentCompleteUserProfile\AccountSection;
use Mortalkiller\FilamentCompleteUserProfile\CompleteUserProfilePlugin;
use Mortalkiller\FilamentCompleteUserProfile\Features\Profile;
use MortalKiller\FilamentPageHeader\Components\Header;
use MortalKiller\FilamentPageHeader\Enums\BreadcrumbPosition;
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
            // Larger on the sign-in and sign-up pages, where the logo stands alone.
            ->brandLogoHeight(fn () => AppearancePlugin::isOnSimplePage() ? '2.5rem' : '1.6rem')
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
            // The sidebar can shrink to its icons; the topbar corner then shows the Contiu symbol.
            ->sidebarCollapsibleOnDesktop()
            ->navigation(fn (NavigationBuilder $builder): NavigationBuilder|bool => AccountantAccess::isReviewOnly()
                ? $builder->items(AccountantReview::getNavigationItems())
                : true)
            ->homeUrl(fn (): ?string => AccountantAccess::isReviewOnly() ? AccountantReview::getUrl() : null)
            ->globalSearch(AccountantAwareGlobalSearch::class, position: fn (): GlobalSearchPosition => AccountantAccess::isReviewOnly()
                ? GlobalSearchPosition::Sidebar
                : GlobalSearchPosition::Topbar)
            // Ctrl K (⌃K on a Mac) focuses the search; the field shows the shortcut.
            ->globalSearchKeyBindings(['ctrl+k'])
            ->globalSearchFieldSuffix(fn (): string => Platform::detect() === Platform::Mac ? '⌃K' : 'Ctrl K')
            ->databaseNotifications()
            ->databaseNotificationsPolling('60s')
            // Hoje, Fecho do mês and Calendário come first, outside any group. The groups that
            // are opened less often start closed.
            ->navigationGroups([
                NavigationGroup::make(fn (): string => __('Revenue')),
                NavigationGroup::make(fn (): string => __('Expenses')),
                NavigationGroup::make(fn (): string => __('Bank')),
                NavigationGroup::make(fn (): string => __('Contacts')),
                NavigationGroup::make(fn (): string => __('Taxes'))->collapsed(),
                NavigationGroup::make(fn (): string => __('Analysis'))->collapsed(),
                NavigationGroup::make(fn (): string => __('Settings'))->collapsed(),
            ])
            ->userMenuItems([
                'help' => Action::make('help')
                    ->label(fn (): string => __('Help center'))
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
            // Trial days left, or that the account is read-only (App\Support\AccountStatus). Not on
            // "Assinatura" itself, which says the same in its own card.
            ->renderHook(
                PanelsRenderHook::CONTENT_START,
                fn (): string => AccountantAccess::isReviewOnly() || request()->routeIs(AccountSubscription::getRouteName()) || ($banner = AccountStatus::banner()) === null
                    ? ''
                    : view('filament.partials.account-status-banner', ['banner' => $banner])->render(),
            )
            ->renderHook(
                PanelsRenderHook::GLOBAL_SEARCH_BEFORE,
                fn (): string => AccountantAccess::isReviewOnly() ? '' : Blade::render('@livewire(\\App\\Livewire\\QuickCreateMenu::class)'),
            )
            ->plugins([
                SkeletonLoadingPlugin::make()
                    ->pageLoader()
                    ->pageLoaderSymbol(fn () => view('filament.partials.contiu-symbol'))
                    ->pageLoaderLabel(fn (): string => __('Loading…')),
                PageHeaderPlugin::make(),
                // Palettes per user, the logo that goes with each one, and the panel's shell: tabs
                // with a line under the active one and, on a phone, lists instead of tables,
                // edge-to-edge cards and a bar of shortcuts at the bottom of the screen.
                AppearancePlugin::make()
                    ->palettes(fn (): array => ContiuPalettes::all())
                    ->defaultPalette(ContiuPalettes::DEFAULT)
                    ->customPalettes()
                    // Black on a light surface, white on a dark one: the sign-in pages, and the
                    // navigation bar of every palette without a logo of its own.
                    ->brandLogo(
                        fn (): string => asset('brand/contiu/contiu-logo-black.svg'),
                        dark: fn (): string => asset('brand/contiu/contiu-logo-white.svg'),
                    )
                    ->collapsedBrandLogo(
                        fn (): string => asset('brand/contiu/contiu-symbol-black.svg'),
                        dark: fn (): string => asset('brand/contiu/contiu-symbol-white.svg'),
                    )
                    ->topbarSearchAtStart()
                    ->tableSearchAtStart()
                    ->underlinedTabs()
                    ->mobileTables()
                    ->mobileEdgeToEdge()
                    ->mobileTabBar(fn (): bool => static::showsMobileTabBar())
                    ->mobileTabBarItems(fn (): array => [
                        NavigationItem::make(__('Today'))->icon(Phosphor::House)->url(Dashboard::getUrl()),
                        NavigationItem::make(__('To collect'))
                            ->icon(Phosphor::HourglassMedium)
                            ->url(ReceivableResource::getUrl())
                            ->isActiveWhen(fn (): bool => request()->fullUrlIs(ReceivableResource::getUrl().'*')),
                        NavigationItem::make(__('Expenses'))
                            ->icon(Phosphor::Receipt)
                            ->url(ExpenseResource::getUrl())
                            ->isActiveWhen(fn (): bool => request()->fullUrlIs(ExpenseResource::getUrl().'*')),
                    ])
                    // The round "+" in the middle of the bar: the "Novo" menu, opening upwards.
                    ->mobileTabBarAction(fn (): string => Blade::render(
                        "@livewire(\\App\\Livewire\\QuickCreateMenu::class, ['tabBar' => true], key('quick-create-tab-bar'))",
                    )),
                CompleteUserProfilePlugin::make()
                    // As in the mock and the other pages: "A minha conta" on the page itself (no
                    // card, avatar or breadcrumbs), the tabs under it, across the full width.
                    ->maxContentWidth(Width::Full)
                    ->modifyHeaderUsing(fn (Header $header): Header => $header
                        ->contained(false)
                        ->heading(__('My account'))
                        ->avatar(null)
                        ->initials(null)
                        ->breadcrumbs(BreadcrumbPosition::Hidden))
                    ->profile(fn (Profile $profile): Profile => $profile->locale(false)
                        ->fields(fn (): array => [
                            Select::make('language')->label(__('Language'))->options(Language::options())
                                ->required()->in(array_keys(Language::options())),
                        ]))
                    // After "Segurança" (30): light/dark mode and the colour palette.
                    ->section(
                        AccountSection::make('appearance')
                            ->label(fn (): string => __('Appearance'))
                            ->description(fn (): string => __('Choose the colors and appearance mode.'))
                            ->sort(35)
                            ->schema([PalettePicker::make()]),
                    ),
                FilamentShieldPlugin::make()
                    // Roles are global (no Shield teams); RoleResource lets only super admins in.
                    ->scopeToTenant(false)
                    ->navigationIcon(Phosphor::IdentificationBadge)
                    ->activeNavigationIcon(Phosphor::IdentificationBadge->fill())
                    ->navigationGroup(fn (): string => __('Settings'))
                    ->navigationSort(10),
                FilamentAuditingPlugin::make(),
                FilamentSpatieLaravelBackupPlugin::make()
                    // A dump holds every account's data.
                    ->authorize(fn (): bool => User::loggedInIsSuperAdmin())
                    // This plugin casts enums to ->value ("hard-drives"), so pass the full icon name.
                    ->navigationIcon(Phosphor::HardDrives->getLabel())
                    ->navigationGroup(fn (): string => __('Settings'))
                    ->navigationSort(30),
                FilamentUnsavedChangesModalPlugin::make(),
                // Not ->shouldScrollToTopOnPageChanged(): it scrolls to the first table on EVERY
                // Livewire request (widget polling, notifications…), so pages jumped on their own.
                StickyTableHeaderPlugin::make(),
            ])
            ->profile(EditUserProfile::class, isSimple: false)
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
            ])
            // Also on Livewire updates from those pages (e.g. saving /profile).
            ->persistentMiddleware([UseDefaultAccountOutsideTenantRoutes::class])
            ->tenantMiddleware([RestrictAccountantRoutes::class], isPersistent: true)
            ->tenantRoutes(function (): void {
                Route::get('invoice-benchmark/preview/{document}', InvoiceBenchmarkPreviewController::class)
                    ->whereUuid('document')->middleware([Authenticate::class, 'throttle:60,1'])->name('invoice-benchmark.preview');
            });
    }

    /**
     * Inside an account, for whoever uses the whole panel: not for an accountant in review-only
     * mode (their menu is a single screen). The plugin leaves it out of the sign-in pages.
     */
    private static function showsMobileTabBar(): bool
    {
        return Filament::getTenant() !== null && ! AccountantAccess::isReviewOnly();
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
            'owner' => __('Owner'),
            'member' => __('Member'),
            AccountantAccess::ROLE => __('Accountant'),
            null => $account instanceof Account && $user->isSuperAdmin() ? __('Super admin') : null,
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
