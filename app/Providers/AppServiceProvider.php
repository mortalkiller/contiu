<?php

namespace App\Providers;

use App\Exceptions\AccountIsReadOnly;
use App\Filament\Support\PhosphorIconAliases;
use App\Models\Account;
use App\Policies\AuditPolicy;
use App\Support\AccountMemberAccess;
use App\Support\CurrentAccount;
use App\Support\ReadOnlyAccountAccess;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentIcon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
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
        $this->app->scoped(AccountMemberAccess::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerPhosphorIcons();
        $this->configureFormatting();
        $this->configureBilling();
        $this->registerAccountGates();
        $this->showReadOnlyAccountAsNotification();

        // tapp's Audit model lives in vendor, so Laravel cannot discover its Shield-generated policy.
        Gate::policy(Audit::class, AuditPolicy::class);
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
     * Money and dates as used in Portugal: "1 965,50 €" and "28/08/2026".
     * The app locale stays "pt" (Filament translations); ICU "pt" would format money the Brazilian way.
     */
    private function configureFormatting(): void
    {
        Table::configureUsing(fn (Table $table) => $table
            ->defaultNumberLocale('pt_PT')
            ->defaultDateDisplayFormat('d/m/Y')
            ->defaultDateTimeDisplayFormat('d/m/Y H:i'));

        Schema::configureUsing(fn (Schema $schema) => $schema
            ->defaultNumberLocale('pt_PT')
            ->defaultDateDisplayFormat('d/m/Y')
            ->defaultDateTimeDisplayFormat('d/m/Y H:i'));
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
