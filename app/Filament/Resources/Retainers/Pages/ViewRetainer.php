<?php

namespace App\Filament\Resources\Retainers\Pages;

use App\Filament\Actions\RecordPaymentAction;
use App\Filament\Pages\InvoiceXpressIssuePage;
use App\Filament\Resources\Retainers\RetainerResource;
use App\Filament\Resources\Retainers\Widgets\RetainerSummary;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use MortalKiller\FilamentPageHeader\Concerns\HasPageHeader;

class ViewRetainer extends ViewRecord
{
    use HasPageHeader;

    protected static string $resource = RetainerResource::class;

    /** "Retainers › AV-03", as in the mock: the page being the retainer's own is said by its title. */
    public function getBreadcrumbs(): array
    {
        return array_slice(parent::getBreadcrumbs(), 0, -1, preserve_keys: true);
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->color('gray'),
            Action::make('issueInvoice')
                ->label(__('Issue invoice'))
                ->color('gray')
                ->url(fn (): string => InvoiceXpressIssuePage::getUrl())
                ->visible(fn (): bool => $this->record->outstanding_amount > 0 && InvoiceXpressIssuePage::canAccess()),
            RecordPaymentAction::make()->icon(null)->after(fn () => $this->record->refresh()),
        ];
    }

    /** The four figures, between the title and the records. */
    protected function getHeaderWidgets(): array
    {
        return [RetainerSummary::class];
    }

    /**
     * As in the mock: the records (amounts per period, payments, costs, history) take the width
     * of the page, with the upcoming periods and the details in a narrower column beside them.
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            // About 70/30, the mock's split.
            Grid::make(['default' => 1, 'xl' => 7])->schema([
                Group::make([$this->getRelationManagersContentComponent()])
                    ->columnSpan(['default' => 1, 'xl' => 5]),
                Group::make([$this->getInfolistContentComponent()])
                    ->columnSpan(['default' => 1, 'xl' => 2]),
            ]),
        ]);
    }
}
