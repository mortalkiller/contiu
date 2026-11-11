<?php

namespace App\Filament\Resources\Suppliers\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Resources\Suppliers\Widgets\SupplierSummary;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use MortalKiller\FilamentPageHeader\Concerns\HasPageHeader;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * A supplier's own page, as in the mock: who they are, four figures, what you bought from them
 * and, beside it, their details and the statement lines tied to those expenses.
 */
class ViewSupplier extends ViewRecord
{
    use HasPageHeader;

    protected static string $resource = SupplierResource::class;

    /** "Suppliers › Loja Demo", as in the mock: the page being the supplier's own is said by its title. */
    public function getBreadcrumbs(): array
    {
        return array_slice(parent::getBreadcrumbs(), 0, -1, preserve_keys: true);
    }

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                DeleteAction::make()
                    ->visible(fn (): bool => $this->record->expenses()->doesntExist())
                    ->successRedirectUrl(SupplierResource::getUrl()),
            ]),
            EditAction::make()
                ->color('gray')
                ->modalWidth(Width::TwoExtraLarge)
                ->schema(fn (Schema $schema): Schema => SupplierResource::form($schema)),
            Action::make('newExpense')
                ->label(__('New expense'))
                ->icon(Phosphor::Plus)
                ->visible(fn (): bool => ExpenseResource::canCreate())
                ->url(fn (): string => ExpenseResource::getUrl('create', ['supplier' => $this->record->getKey()])),
        ];
    }

    /** The four figures, between the name and the records. */
    protected function getHeaderWidgets(): array
    {
        return [SupplierSummary::class];
    }

    /**
     * As in the mock: the records (expenses, recurring costs) take the width of the page, with
     * the supplier's details and its statement lines in a narrower column beside them.
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
