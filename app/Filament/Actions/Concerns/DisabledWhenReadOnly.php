<?php

namespace App\Filament\Actions\Concerns;

use App\Support\CurrentAccount;
use Illuminate\Contracts\Support\Htmlable;

/**
 * For a write action no policy covers, so the read-only gate cannot hide it: disabled while the
 * account is read-only, with the reason as its tooltip. Added on top of whatever disabled() or
 * tooltip() a call site sets, never in place of it. (BelongsToAccount still refuses the write.)
 */
trait DisabledWhenReadOnly
{
    public function isDisabled(): bool
    {
        return CurrentAccount::readOnly() || parent::isDisabled();
    }

    /**
     * Always carries a disabled condition (the read-only one): keeps Filament off its optimized
     * menu-item rendering, which reads the configured state and never calls isDisabled().
     */
    public function hasDisabled(): bool
    {
        return true;
    }

    public function getTooltip(): string|Htmlable|null
    {
        return CurrentAccount::readOnly() ? 'A conta está só de leitura' : parent::getTooltip();
    }
}
