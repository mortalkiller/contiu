<?php

declare(strict_types=1);

namespace MortalKiller\FilamentSkeletonLoading\Tests\Fixtures;

use Filament\Widgets\Widget;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

final class Plain extends Widget
{
    use HasSkeletonPlaceholder;

    protected string $view = 'filament-skeleton-loading::placeholder';
}
