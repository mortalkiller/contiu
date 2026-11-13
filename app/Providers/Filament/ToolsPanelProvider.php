<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\EmailVerificationPrompt;
use App\Filament\Pages\Dashboard;
use App\Filament\Pages\MailPreviewPage;
use App\Filament\Support\ContiuPanelLayout;
use App\Http\Middleware\ApplyUserLanguage;
use App\Models\Account;
use App\Support\MailPreviews\MailPreviewRegistry;
use App\Support\PanelLocation;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class ToolsPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return ContiuPanelLayout::apply($panel)
            ->id('tools')
            ->path('ferramentas')
            ->domain(PanelLocation::domain())
            ->tenant(Account::class, slugAttribute: 'slug', ownershipRelationship: 'account')
            ->tenantMenu(false)
            ->login(fn (): RedirectResponse => redirect()->route('filament.admin.auth.login'))
            ->emailVerification(EmailVerificationPrompt::class)
            ->pages([
                ...(app()->environment(['local', 'testing']) ? [MailPreviewPage::class] : []),
            ])
            ->brandName(fn (): string => __('Contiu Tools'))
            ->databaseNotifications()
            ->databaseNotificationsPolling('60s')
            ->plugin(ContiuPanelLayout::appearance()
                ->mobileTabBar(fn (): bool => Filament::getCurrentPanel()?->getId() === 'tools' && Filament::getTenant() !== null)
                ->mobileTabBarItems(fn (): array => [
                    NavigationItem::make(__('Back to Contiu'))
                        ->icon(Phosphor::ArrowLeft)
                        ->url(Dashboard::getUrl(panel: 'admin', tenant: Filament::getTenant())),
                ]))
            ->userMenuItems([
                Action::make('backToContiu')
                    ->label(fn (): string => __('Back to Contiu'))
                    ->icon(Phosphor::ArrowLeft)
                    ->url(fn (): string => Dashboard::getUrl(panel: 'admin', tenant: Filament::getTenant())),
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
                ApplyUserLanguage::class,
            ], isPersistent: true)
            ->authenticatedTenantRoutes(function (Panel $panel): void {
                Route::get('/', fn (): RedirectResponse => redirect()->to(match (true) {
                    MailPreviewPage::canAccess() => MailPreviewPage::getUrl(),
                    default => Dashboard::getUrl(panel: 'admin', tenant: Filament::getTenant()),
                }))->middleware($panel->getEmailVerifiedMiddleware())->name('home');

                if (app()->environment(['local', 'testing'])) {
                    Route::get('email-preview/render/{template}', function (Request $request, string $tenant, string $template, MailPreviewRegistry $previews): Response {
                        abort_unless(MailPreviewPage::canAccess(), 403);
                        abort_unless(array_key_exists($template, $previews->templateOptions()), 404);

                        return response($previews->preview($template, $request->string('locale')->toString())->html)
                            ->header('Cache-Control', 'private, no-store');
                    })->middleware($panel->getEmailVerifiedMiddleware())->name('mail-preview.render');
                }
            });
    }
}
