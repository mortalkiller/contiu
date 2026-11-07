<?php

namespace App\Providers;

use App\Filament\Support\PhosphorIconAliases;
use App\Policies\AuditPolicy;
use Closure;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentIcon;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use pxlrbt\FilamentExcel\Actions\ExportAction;
use pxlrbt\FilamentExcel\Actions\ExportBulkAction;
use Tapp\FilamentAuditing\Filament\Actions\RestoreAuditAction;
use Tapp\FilamentAuditing\Filament\Resources\Audits\AuditResource;
use Tapp\FilamentAuditing\Models\Audit;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerPhosphorIcons();
        $this->configureFormatting();

        // tapp's Audit model lives in vendor, so Laravel cannot discover its Shield-generated policy.
        Gate::policy(Audit::class, AuditPolicy::class);
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

        // tapp/filament-auditing exposes no setter for its navigation icon, and its pages are bound
        // to its own resource class, so subclassing it would break the routes.
        Closure::bind(static function (): void {
            AuditResource::$navigationIcon = Phosphor::ClockCounterClockwise;
            AuditResource::$navigationGroup = 'Definições';
            AuditResource::$navigationSort = 20;
        }, null, AuditResource::class)();
    }
}
