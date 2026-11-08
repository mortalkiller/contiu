<?php

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Accounts\AccountResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class ViewAccount extends ViewRecord
{
    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('enter')
                ->label('Entrar na conta')
                ->icon(Phosphor::SignIn)
                ->url(fn (): string => Dashboard::getUrl(tenant: $this->getRecord())),
        ];
    }
}
