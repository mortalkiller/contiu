<?php

namespace App\Filament\Pages\Auth\Concerns;

use Illuminate\Contracts\Support\Htmlable;

/**
 * The sign-in pages in the split layout of the "Autenticação" mocks: the form on the left, Contiu's
 * showcase on the right (filament.pages.auth.auth-layout). The layout draws the logo, so the page
 * header does not; a page can add a "back" link above its heading and a line under its form.
 */
trait UsesContiuAuthLayout
{
    public function hasLogo(): bool
    {
        return false;
    }

    /**
     * @return array{backLink: array{url: string, label: string}|null, closingLine: string|Htmlable|null}
     */
    protected function getViewData(): array
    {
        return [
            'backLink' => $this->getBackLink(),
            'closingLine' => $this->getClosingLine(),
        ];
    }

    /**
     * @return array{url: string, label: string}|null
     */
    protected function getBackLink(): ?array
    {
        return null;
    }

    protected function getClosingLine(): string|Htmlable|null
    {
        return null;
    }
}
