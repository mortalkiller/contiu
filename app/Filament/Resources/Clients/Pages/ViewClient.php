<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Filament\Actions\RecordPaymentAction;
use App\Filament\Actions\SendPaymentReminderAction;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Projects\ProjectResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use MortalKiller\FilamentPageHeader\Concerns\HasPageHeader;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class ViewClient extends ViewRecord
{
    use HasPageHeader;

    protected static string $resource = ClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            RecordPaymentAction::make(),
            SendPaymentReminderAction::make(),
            static::addClientEmailAction(),
            Action::make('statement')
                ->label('Extrato')
                ->icon(Phosphor::Scroll)
                ->color('gray')
                ->url(fn () => ClientResource::getUrl('statement', ['record' => $this->record])),
            Action::make('newProject')
                ->label('Novo trabalho')
                ->icon(Phosphor::Plus)
                ->color('gray')
                ->url(fn () => ProjectResource::getUrl('create', ['client' => $this->record->getKey()])),
            EditAction::make(),
        ];
    }

    /** Shown only when the client has no email, so reminders and statements can be sent. */
    public static function addClientEmailAction(): Action
    {
        return Action::make('addClientEmail')
            ->label('Adicionar email')
            ->icon(Phosphor::EnvelopeSimple)
            ->color('warning')
            ->tooltip('Sem email não é possível enviar lembretes nem extratos')
            ->visible(fn ($livewire) => blank($livewire->getRecord()->email))
            ->url(fn ($livewire) => ClientResource::getUrl('edit', ['record' => $livewire->getRecord()]));
    }
}
