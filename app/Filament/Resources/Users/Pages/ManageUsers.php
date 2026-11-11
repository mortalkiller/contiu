<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageUsers extends ManageRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('New user'))
                // The panel stays shut until the address is confirmed, so the new user gets the link
                // (only Filament's sign-up page sends it on its own).
                ->after(fn (User $record) => $record->sendEmailVerificationNotification()),
        ];
    }
}
