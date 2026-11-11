<?php

namespace App\Filament\Pages;

use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use Filament\Navigation\NavigationItem;
use Filament\Pages\Enums\SubNavigationPosition;
use Filament\Pages\Page;
use Filament\Pages\PageConfiguration;
use Filament\Panel;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Support\Facades\Route;

abstract class InvoiceXpressSectionPage extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected static ?SubNavigationPosition $subNavigationPosition = SubNavigationPosition::Top;

    private const array Tabs = [
        InvoiceXpressIssuePage::class => 'Invoices',
        InvoiceXpressReceiptPage::class => 'Receipts',
        InvoiceXpressNoteIssuePage::class => 'Credit and debit notes',
        FiscalDocumentDraftsPage::class => 'Drafts',
        InvoiceXpressImport::class => 'Synchronize',
    ];

    #[Unexposed]
    public static function getDefaultSlug(): string
    {
        return match (static::class) {
            InvoiceXpressIssuePage::class => 'documentos-fiscais/faturas/nova',
            InvoiceXpressReceiptPage::class => 'documentos-fiscais/recibos',
            InvoiceXpressNoteIssuePage::class => 'documentos-fiscais/notas',
            InvoiceXpressImport::class => 'documentos-fiscais/sincronizar',
            InvoiceXpressSettingsPage::class => 'integracoes/invoicexpress',
            default => parent::getDefaultSlug(),
        };
    }

    #[Unexposed]
    public static function routes(Panel $panel, ?PageConfiguration $configuration = null): void
    {
        parent::routes($panel, $configuration);

        if ($configuration === null && filled(static::$slug) && static::$slug !== static::getDefaultSlug()) {
            Route::get('/'.static::$slug, function () {
                abort_unless(static::canAccess(), 403);

                return redirect()->to(static::getUrl());
            })->middleware(static::getRouteMiddleware($panel))
                ->withoutMiddleware(static::getWithoutRouteMiddleware($panel))
                ->name(static::$slug);
        }
    }

    public static function fiscalDocumentRoutes(): array
    {
        return array_map(fn (string $page): string => $page::getRouteName(), array_keys(self::Tabs));
    }

    /** @return list<NavigationItem> */
    #[Unexposed]
    public function getSubNavigation(): array
    {
        if (static::class === InvoiceXpressSettingsPage::class) {
            return [];
        }

        $items = [
            NavigationItem::make(__('Fiscal documents'))->url(FiscalDocumentResource::getUrl())->sort(0),
        ];

        foreach (self::Tabs as $page => $label) {
            if ($page::canAccess()) {
                $items[] = NavigationItem::make(__($label))
                    ->sort(count($items))
                    ->url($page::getUrl())
                    ->activeWhen(fn (): bool => static::class === $page);
            }
        }

        return $items;
    }
}
