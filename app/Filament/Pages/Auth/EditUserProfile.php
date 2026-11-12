<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms\Components\Toggle;
use Mortalkiller\FilamentCompleteUserProfile\Pages\CompleteUserProfile;
use SensitiveParameter;

class EditUserProfile extends CompleteUserProfile
{
    /** The news-by-email choice of this save, applied after the profile is saved. */
    private ?bool $marketing = null;

    /**
     * The profile fields, plus the news by email: the consent given (or not) at sign-up can be
     * withdrawn, or given, here at any time.
     */
    public function getProfileFormComponents(): array
    {
        return [
            ...parent::getProfileFormComponents(),
            Toggle::make('marketing')
                ->label(__('I want news and tips from Contiu by email'))
                ->helperText(__('Optional. Emails about your account, taxes and payments arrive either way.')),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $user = $this->getUser();

        return [
            ...parent::mutateFormDataBeforeFill($data),
            'marketing' => $user instanceof User && $user->marketing_opt_in_at !== null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(#[SensitiveParameter] array $data): array
    {
        $this->marketing = array_key_exists('marketing', $data) ? (bool) $data['marketing'] : null;
        unset($data['marketing']);

        return parent::mutateFormDataBeforeSave($data);
    }

    /** Keeps the date of the first consent while it stands; withdrawing clears it. */
    protected function afterSave(): void
    {
        $user = $this->getUser();

        if ($user instanceof User && $this->marketing !== null) {
            $user->forceFill([
                'marketing_opt_in_at' => $this->marketing ? ($user->marketing_opt_in_at ?? now()) : null,
            ])->save();
        }

        parent::afterSave();
    }

    protected function getRedirectUrl(): ?string
    {
        $user = $this->getUser();

        if ($user instanceof User && $user->preferredLocale() !== app()->getLocale()) {
            return Filament::getProfileUrl(['section' => 'profile']);
        }

        return parent::getRedirectUrl();
    }
}
