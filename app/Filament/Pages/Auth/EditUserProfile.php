<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use Filament\Facades\Filament;
use Mortalkiller\FilamentCompleteUserProfile\Pages\CompleteUserProfile;

class EditUserProfile extends CompleteUserProfile
{
    protected function getRedirectUrl(): ?string
    {
        $user = $this->getUser();

        if ($user instanceof User && $user->preferredLocale() !== app()->getLocale()) {
            return Filament::getProfileUrl(['section' => 'profile']);
        }

        return parent::getRedirectUrl();
    }
}
