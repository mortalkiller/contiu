<?php

declare(strict_types=1);

namespace MortalKiller\FilamentSkeletonLoading;

use Closure;
use Filament\Contracts\Plugin;
use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;

final class SkeletonLoadingPlugin implements Plugin
{
    public const ID = 'mortalkiller-skeleton-loading';

    public const PACKAGE = 'mortalkiller/filament-skeleton-loading';

    private bool $shimmer = true;

    /** @var bool|Closure(Table): bool|null null: the `defer_tables` setting decides */
    private bool|Closure|null $deferTables = null;

    public static function make(): self
    {
        return app(self::class);
    }

    /** The plugin registered on the current panel, if any. */
    public static function current(): ?self
    {
        $panel = Filament::getCurrentPanel();
        $plugin = $panel?->hasPlugin(self::ID) ? $panel->getPlugin(self::ID) : null;

        return $plugin instanceof self ? $plugin : null;
    }

    /** The global switch (`filament-skeleton-loading.enabled`, env SKELETON_LOADING_ENABLED). */
    public static function isEnabled(): bool
    {
        return (bool) config('filament-skeleton-loading.enabled', true);
    }

    public function getId(): string
    {
        return self::ID;
    }

    /** A light sweeping across the skeleton (on by default; always still for reduced motion). */
    public function shimmer(bool $condition = true): self
    {
        $this->shimmer = $condition;

        return $this;
    }

    public function hasShimmer(): bool
    {
        return $this->shimmer;
    }

    /**
     * Load every table of the panel after the page (Filament's `deferLoading()`), showing skeleton
     * rows meanwhile. A closure receives the table and decides per table. Not called: the
     * `defer_tables` setting decides (env SKELETON_LOADING_DEFER_TABLES).
     *
     * @param  bool|Closure(Table): bool  $condition
     */
    public function deferTables(bool|Closure $condition = true): self
    {
        $this->deferTables = $condition;

        return $this;
    }

    public function register(Panel $panel): void
    {
        if (self::isEnabled() && ! $this->shimmer) {
            $panel->renderHook(
                PanelsRenderHook::HEAD_END,
                fn (): string => '<style>.fsl-bone::after,.fi-ta-table-loading-ctn::after,.fi-no-notifications-loading-ctn::after,.fi-sc-loading::after{display:none!important}</style>',
            );
        }
    }

    public function boot(Panel $panel): void
    {
        if (! self::isEnabled()) {
            return;
        }

        // Read at each table, so the setting and later plugin calls are honoured.
        Table::configureUsing(function (Table $table) use ($panel): void {
            if (Filament::getCurrentPanel()?->getId() !== $panel->getId()) {
                return;
            }

            $condition = $this->deferTables ?? (bool) config('filament-skeleton-loading.defer_tables', false);

            if ($condition instanceof Closure) {
                $condition = (bool) $condition($table);
            }

            if ($condition) {
                $table->deferLoading();
            }
        });
    }
}
