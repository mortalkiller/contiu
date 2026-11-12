<?php

namespace App\Providers;

use App\Enums\Language;
use App\Exceptions\AccountIsReadOnly;
use App\Filament\Support\PhosphorIconAliases;
use App\Filament\Support\TableEmptyState;
use App\Http\Middleware\ApplyUserLanguage;
use App\Http\Responses\EmailVerificationResponse;
use App\Models\Account;
use App\Policies\AuditPolicy;
use App\Support\AccountMemberAccess;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use App\Support\Invoicing\Invoicing;
use App\Support\ReadOnlyAccountAccess;
use Filament\Auth\Http\Responses\Contracts\EmailVerificationResponse as EmailVerificationResponseContract;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Pages\BasePage as Page;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentIcon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use Mortalkiller\FilamentCompleteUserProfile\Http\Middleware\SetUserLocale;
use pxlrbt\FilamentExcel\Actions\ExportAction;
use pxlrbt\FilamentExcel\Actions\ExportBulkAction;
use Tapp\FilamentAuditing\Filament\Actions\RestoreAuditAction;
use Tapp\FilamentAuditing\Models\Audit;
use Throwable;
use ToneGabes\Filament\Icons\Enums\Phosphor;

use function Livewire\on;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(CurrentAccount::class);
        $this->app->scoped(FinanceSettings::class);
        $this->app->scoped(AccountMemberAccess::class);
        $this->app->scoped(Invoicing::class);

        // The profile package registers this middleware on the panel's authenticated routes.
        $this->app->bind(SetUserLocale::class, ApplyUserLanguage::class);

        // "Email confirmed" after the confirmation link, before the panel.
        $this->app->bind(EmailVerificationResponseContract::class, EmailVerificationResponse::class);

        // Its routes are registered in routes/stripe.php, where the webhook fails closed.
        Cashier::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app['translator']->determineLocalesUsing(fn (array $locales): array => array_merge(...array_map(
            fn (string $locale): array => match ($locale) {
                'pt_PT' => ['pt_PT', 'pt'],
                'en_US' => ['en_US', 'en'],
                default => [$locale],
            },
            $locales,
        )));
        $this->detectLazyLoading();
        $this->registerPhosphorIcons();
        $this->configureFormatting();
        $this->configureFormActions();
        $this->configureBilling();
        $this->registerAccountGates();
        $this->showReadOnlyAccountAsNotification();

        // tapp's Audit model lives in vendor, so Laravel cannot discover its Shield-generated policy.
        Gate::policy(Audit::class, AuditPolicy::class);
    }

    private function detectLazyLoading(): void
    {
        if ($this->app->isLocal()) {
            Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation): void {
                Log::warning(sprintf('Possible N+1: %s::%s was lazy loaded.', $model::class, $relation));
            });
        }
    }

    /**
     * Account-level gate rules. Gate::before callbacks run in registration order and the first
     * non-null answer wins, so a rule that must deny (e.g. read-only accounts) goes ABOVE the
     * membership grant. Shield's super-admin grant and Spatie's permission check are registered
     * earlier by their packages and only answer true or null: a deny here does not reach a super
     * admin, nor a permission given directly to a user.
     */
    private function registerAccountGates(): void
    {
        Gate::before(fn ($user, string $ability): ?bool => ReadOnlyAccountAccess::before($ability));
        Gate::before(fn ($user, string $ability, array $arguments): ?bool => app(AccountMemberAccess::class)->before($user, $ability, $arguments));
    }

    /**
     * The account pays (not the user). A past_due subscription keeps access while Stripe retries
     * the charge: whoever is paying is not locked out (spec, "Regras da assinatura").
     */
    private function configureBilling(): void
    {
        Cashier::useCustomerModel(Account::class);
        Cashier::keepPastDueSubscriptionsActive();
    }

    /**
     * A write refused in a read-only account (BelongsToAccount) reaches the user as a notification
     * on any panel action, instead of an error page.
     */
    private function showReadOnlyAccountAsNotification(): void
    {
        on('exception', function ($target, Throwable $exception, callable $stopPropagation): void {
            if (! $exception instanceof AccountIsReadOnly) {
                return;
            }

            Notification::make()->title($exception->getMessage())->danger()->send();
            $stopPropagation();
        });
    }

    /**
     * As in the mock ("Página de formulário"): a form's buttons stay in view at the bottom of
     * the screen, at its end. Their order and labels are in HasSaveBar.
     */
    private function configureFormActions(): void
    {
        Page::stickyFormActions();
        Page::alignFormActionsEnd();
    }

    private function configureFormatting(): void
    {
        $dateFormat = fn (): string => Language::resolve(app()->getLocale())->dateFormat();

        // No table sets empty-state actions of its own: every one gets "Limpar filtros", shown only
        // when a search or a filter hides all its records (App\Filament\Support\TableEmptyState).
        Table::configureUsing(fn (Table $table) => $table
            ->emptyStateActions([TableEmptyState::clearAction()])
            ->defaultNumberLocale(fn (): string => app()->getLocale())
            ->defaultDateDisplayFormat($dateFormat)
            ->defaultDateTimeDisplayFormat(fn (): string => $dateFormat().' H:i'));

        Schema::configureUsing(fn (Schema $schema) => $schema
            ->defaultNumberLocale(fn (): string => app()->getLocale())
            ->defaultDateDisplayFormat($dateFormat)
            ->defaultDateTimeDisplayFormat(fn (): string => $dateFormat().' H:i'));

        FileUpload::configureUsing(fn (FileUpload $upload) => $upload
            ->placeholder(fn (): string => __('Drag and drop your files or <span class="filepond--label-action">browse</span>')));
    }

    /**
     * Phosphor replaces Heroicons everywhere Filament or a plugin lets us choose the icon.
     */
    private function registerPhosphorIcons(): void
    {
        FilamentIcon::register(PhosphorIconAliases::map());

        // Plugin actions that hardcode a Heroicon in setUp(). Registered as "important" so they run
        // after every class's setUp(): pxlrbt's Tables\ExportAction runs setUp() below the base class.
        ExportAction::configureUsing(fn (ExportAction $action) => $action->icon(Phosphor::DownloadSimple), isImportant: true);
        ExportBulkAction::configureUsing(fn (ExportBulkAction $action) => $action->icon(Phosphor::DownloadSimple), isImportant: true);
        RestoreAuditAction::configureUsing(fn (RestoreAuditAction $action) => $action->icon(Phosphor::ArrowCounterClockwise), isImportant: true);
    }
}
