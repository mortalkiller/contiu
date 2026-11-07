# Filament Skeleton Loading

Skeleton loading states for Filament panels. Lazy widgets, lazy sections and deferred tables show the shape of what is coming (stat tiles, a chart, table rows) instead of a grey box or a spinner.

## Why

Filament already loads widgets lazily and can defer tables, but while they load it shows an empty pulsing box or a spinner. The page then jumps when the real content arrives, and nothing tells the user what is about to appear.

This package keeps Filament's own loading mechanisms (`placeholder()`, `deferLoading()`, the `loading-section` view) and only changes what is drawn while waiting. It does not add new loading behaviour, polling or JavaScript.

Non-goals: skeletons for forms, infolists or modals, and skeletons while a table filters or paginates (Filament dims the table in place, which reads better than swapping it for a skeleton).

## Contents

- [Why](#why)
- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Usage](#usage)
- [Page loader](#page-loader)
- [Configuration](#configuration)
- [Accessibility](#accessibility)
- [Testing](#testing)
- [License](#license)

## Features

- **Typed widget skeletons** with the `HasSkeletonPlaceholder` trait, detected from the widget class:
  - stats overview: one tile per column;
  - chart: bars, a line or a ring (doughnut, pie, polar area), with the chart's real heading;
  - table widget: header and rows.
- **Relation managers** with the same trait get a table skeleton.
- **Generic skeleton** for every other lazy widget, relation manager or Livewire component, with no code change.
- **Deferred tables** draw skeleton rows instead of Filament's spinner.
- **Database notifications** panel: three placeholder notifications instead of a spinner.
- **Lazy loaded schemas** (`Section::make()->schema(...)->lazyLoaded()`): form rows (label and field) instead of a spinner.
- **Page loader** (opt-in): a full-screen loader between pages with your brand symbol, shown only when the next page is slow.
- **Shimmer** that can be turned off, and that always stands still for `prefers-reduced-motion`.
- Light and dark mode; plain CSS, no build step in the consuming app.

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- Filament 4.12.6+ or 5.8.1+

## Installation

```bash
composer require mortalkiller/filament-skeleton-loading
```

Register the plugin on the panel:

```php
use MortalKiller\FilamentSkeletonLoading\SkeletonLoadingPlugin;

$panel->plugin(SkeletonLoadingPlugin::make());
```

The stylesheet is published with Filament's assets (`php artisan filament:assets`, which `filament:upgrade` already runs after `composer install`).

## Usage

### Widgets

Add the trait to a lazy widget or relation manager (both are lazy by default):

```php
use Filament\Widgets\ChartWidget;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

class RevenueChart extends ChartWidget
{
    use HasSkeletonPlaceholder;

    // ...
}
```

Fine-tune with properties:

```php
protected ?int $skeletonItems = 3; // stat tiles (stats widgets)
protected ?int $skeletonRows = 8;  // rows (table widgets)
```

Widgets without the trait still get the generic skeleton.

### Tables

Use Filament's own deferred loading on a table:

```php
return $table
    ->deferLoading()
    ->columns([/* ... */]);
```

or defer every table of the panel from the plugin (a closure decides per table):

```php
SkeletonLoadingPlugin::make()
    ->deferTables(fn (Table $table): bool => $table->getLivewire() instanceof ListRecords);
```

In Livewire tests, load a deferred table before asserting on its records:

```php
Livewire::test(ListOrders::class)->loadTable()->assertCanSeeTableRecords($orders);
```

## Page loader

Off by default. When on, leaving for another page of the panel (a link or a plain form) shows a full-screen loader, but only if the next page takes longer than the delay, so fast pages never flash. Livewire requests (actions, filters, polling) and downloads never show it, and it hides itself after 10 seconds.

```php
SkeletonLoadingPlugin::make()
    ->pageLoader()                                         // ->pageLoader(false) turns it off
    ->pageLoaderSymbol(fn () => view('brand.loader-symbol')) // optional; a generic symbol otherwise
    ->pageLoaderDelay(300)                                 // milliseconds before it shows
    ->pageLoaderLabel('Loading…');                         // optional; Filament's "Loading" label otherwise
```

The symbol is any markup (a string, an `Htmlable`, a view or a closure returning one). Give the elements that should come in one after the other the class `fsl-loader-piece` (up to six, animated in document order); they take the panel's primary colour, white in dark mode:

```blade
<svg viewBox="0 0 120 96" width="64" height="52" fill="currentColor">
    <rect class="fsl-loader-piece" x="0" y="0" width="120" height="20" rx="10"/>
    <rect class="fsl-loader-piece" x="0" y="38" width="88" height="20" rx="10"/>
</svg>
```

## Configuration

Global settings, in `.env` (or publish the config with `php artisan vendor:publish --tag=filament-skeleton-loading-config`):

| Setting | Env | Default | Effect |
|---|---|---|---|
| `enabled` | `SKELETON_LOADING_ENABLED` | `true` | Off: Filament's own grey placeholder and spinner everywhere, the trait included, and no page loader. |
| `defer_tables` | `SKELETON_LOADING_DEFER_TABLES` | `false` | Every table of the panels using the plugin loads after the page, with skeleton rows. |

Plugin methods, per panel:

| Method | Default | Effect |
|---|---|---|
| `shimmer(bool $condition = true)` | on | Light sweeping across the skeleton. |
| `pageLoader(bool $condition = true)` | off | Full-screen loader between pages (see [Page loader](#page-loader)). |
| `pageLoaderSymbol(string\|Htmlable\|Closure $symbol)` | generic bars | Brand symbol of the page loader. |
| `pageLoaderDelay(int $milliseconds)` | `300` | How long a page may take before the loader shows. |
| `pageLoaderLabel(string\|Closure $label)` | Filament's "Loading" | Text under the symbol, also read by screen readers. |
| `deferTables(bool\|Closure $condition = true)` | the `defer_tables` setting | Calls `deferLoading()` on the panel's tables; a closure decides per table. |

Turning `defer_tables` on in an app with Livewire table tests means calling `->loadTable()` in those tests; pin the setting in `phpunit.xml` to keep tests independent of `.env`.

## Accessibility

Every skeleton is a `role="status"` region with `aria-busy="true"` and Filament's translated "Loading" label for screen readers; the shapes themselves are `aria-hidden`. Animations stop for users who prefer reduced motion.

## Testing

```bash
composer install
composer test
```

## License

MIT. See [LICENSE.md](LICENSE.md).
