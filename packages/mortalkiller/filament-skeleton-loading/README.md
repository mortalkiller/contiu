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
- [Configuration](#configuration)
- [Accessibility](#accessibility)
- [Testing](#testing)
- [License](#license)

## Features

- **Typed widget skeletons** with the `HasSkeletonPlaceholder` trait, detected from the widget class:
  - stats overview: one tile per column;
  - chart: bars, a line or a ring (doughnut, pie, polar area), with the chart's real heading;
  - table widget: header and rows.
- **Generic skeleton** for every other lazy widget or section, with no code change.
- **Deferred tables** draw skeleton rows instead of Filament's spinner.
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

Add the trait to a lazy widget (widgets are lazy by default):

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

## Configuration

Global settings, in `.env` (or publish the config with `php artisan vendor:publish --tag=filament-skeleton-loading-config`):

| Setting | Env | Default | Effect |
|---|---|---|---|
| `enabled` | `SKELETON_LOADING_ENABLED` | `true` | Off: Filament's own grey placeholder and spinner everywhere, the trait included. |
| `defer_tables` | `SKELETON_LOADING_DEFER_TABLES` | `false` | Every table of the panels using the plugin loads after the page, with skeleton rows. |

Plugin methods, per panel:

| Method | Default | Effect |
|---|---|---|
| `shimmer(bool $condition = true)` | on | Light sweeping across the skeleton. |
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
