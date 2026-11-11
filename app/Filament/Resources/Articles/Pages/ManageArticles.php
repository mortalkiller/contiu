<?php

namespace App\Filament\Resources\Articles\Pages;

use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Support\Invoicing\Invoicing;
use Filament\Actions\CreateAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;

class ManageArticles extends ManageRecords
{
    protected static string $resource = ArticleResource::class;

    public function getTitle(): string
    {
        return __('Articles');
    }

    /** Articles are reached from the fiscal documents list, so that is where the trail leads back. */
    public function getBreadcrumbs(): array
    {
        return [
            FiscalDocumentResource::getUrl() => __('Fiscal documents'),
            __('Articles'),
        ];
    }

    public function getSubheading(): string
    {
        return __('What you bill often, with its usual price. They are optional: on an invoice you can always write a free line.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('New article'))->modalHeading(__('New article'))
                ->modalWidth('lg')
                ->modalDescription(__('It becomes available on the lines of new documents.')),
        ];
    }

    /**
     * As in the mock: the list, with "Como funcionam" in a narrower column beside it (about
     * 70/30), stacked on smaller screens.
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Grid::make(['default' => 1, 'xl' => 7])->schema([
                Group::make([
                    RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                    EmbeddedTable::make(),
                    RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
                ])->columnSpan(['default' => 1, 'xl' => 5]),
                Section::make(__('How they work'))
                    ->schema([
                        TextEntry::make('fills_the_line')
                            ->label(__('They fill in the line'))
                            ->state(__('When you choose an article, the description, unit and price come filled in. You can change them on that invoice without touching the article.'))
                            ->color('gray'),
                        TextEntry::make('sent_to_the_provider')
                            ->label(__('Contiu deals with the provider'))
                            ->state(fn (): string => __('The article is created at :provider the first time you use it on an invoice.', ['provider' => Invoicing::providerLabel()]))
                            ->color('gray')
                            ->visible(fn (): bool => Invoicing::connected()),
                    ])
                    ->columnSpan(['default' => 1, 'xl' => 2]),
            ]),
        ]);
    }
}
