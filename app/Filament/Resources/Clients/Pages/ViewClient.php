<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Actions\RecordPaymentAction;
use App\Filament\Actions\SendPaymentReminderAction;
use App\Filament\Actions\SendStatementEmailAction;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Clients\Widgets\ClientSummary;
use App\Filament\Resources\Projects\ProjectResource;
use App\Support\ClientStatement;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\Enums\ContentTabPosition;
use Filament\Resources\Pages\ViewRecord;
use MortalKiller\FilamentPageHeader\Concerns\HasPageHeader;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class ViewClient extends ViewRecord
{
    use HasPageHeader;

    protected static string $resource = ClientResource::class;

    /**
     * As in every detail page: what is done most often is the main button, the two next to it
     * are the other common ones, and the rest is under "More actions".
     */
    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('newProject')
                    ->label(__('New project'))
                    ->icon(Phosphor::Plus)
                    ->url(fn () => ProjectResource::getUrl('create', ['client' => $this->record->getKey()])),
                Action::make('statement')
                    ->label(__('View statement'))
                    ->icon(Phosphor::Scroll)
                    ->url(fn () => ClientResource::getUrl('statement', ['record' => $this->record])),
                SendPaymentReminderAction::make(),
                static::addClientEmailAction(),
            ])
                ->label(__('More actions'))
                ->icon(Phosphor::DotsThree)
                ->color('gray')
                ->iconButton(),
            EditAction::make()->color('gray'),
            SendStatementEmailAction::make()
                ->label(__('Send statement'))
                ->icon(Phosphor::Envelope)
                ->statement(fn (): ClientStatement => new ClientStatement($this->getRecord())),
            RecordPaymentAction::make(),
        ];
    }

    /**
     * The page opens on the first tab of records (the client's projects) rather than on its own
     * details, which are the last tab. A tab named in the address is kept.
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->activeRelationManager ??= (string) array_key_first($this->getRelationManagers());
    }

    /** The four figures, between the client's name and the tabs. */
    protected function getHeaderWidgets(): array
    {
        return [ClientSummary::class];
    }

    /**
     * One row of tabs for everything the client has: its records first, and its own details
     * ("Fiscal details") as the last one, instead of a form above the tabs.
     */
    public function hasCombinedRelationManagerTabsWithContent(): bool
    {
        return true;
    }

    public function getContentTabLabel(): ?string
    {
        return __('Fiscal details');
    }

    public function getContentTabPosition(): ?ContentTabPosition
    {
        return ContentTabPosition::After;
    }

    /** Shown only when the client has no email, so reminders and statements can be sent. */
    public static function addClientEmailAction(): Action
    {
        return Action::make('addClientEmail')
            ->label(__('Add email'))
            ->icon(Phosphor::EnvelopeSimple)
            ->color('warning')
            ->tooltip(__('An email address is required to send reminders and statements'))
            ->visible(fn ($livewire) => blank($livewire->getRecord()->email))
            ->url(fn ($livewire) => ClientResource::getUrl('edit', ['record' => $livewire->getRecord()]));
    }
}
