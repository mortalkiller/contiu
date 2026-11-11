<?php

namespace App\Filament\Resources\Clients\Pages;

use App\Exceptions\InvoiceXpressException;
use App\Filament\Resources\Clients\ClientResource;
use App\Support\InvoiceXpressContactSync;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Validation\ValidationException;

class ListClients extends ListRecords
{
    protected static string $resource = ClientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('New client'))->modalWidth('2xl'),
            Action::make('importInvoiceXpressClient')
                ->label(__('Import from InvoiceXpress'))
                ->visible(fn (): bool => InvoiceXpressContactSync::available())
                ->modalHeading(__('Import client from InvoiceXpress'))
                ->modalDescription(__('If the tax ID already exists in Contiu, the fields filled in on InvoiceXpress update the existing record.'))
                ->modalSubmitActionLabel(__('Import client'))
                ->schema([TextInput::make('nif')->label(__('Portuguese tax ID'))->required()->maxLength(24)])
                ->action(function (array $data, InvoiceXpressContactSync $sync, Action $action): void {
                    try {
                        $sync->pull($data['nif']);
                    } catch (InvoiceXpressException|ValidationException $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();
                        $action->halt();
                    }

                    Notification::make()->success()->title(__('Client synced to Contiu'))->send();
                }),
        ];
    }
}
