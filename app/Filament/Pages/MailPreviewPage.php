<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Support\MailPreviews\MailPreviewRegistry;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Support\Components\Attributes\Unexposed;
use Livewire\Attributes\Url;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class MailPreviewPage extends Page
{
    protected string $view = 'filament.pages.mail-preview-page';

    protected static bool $isDiscovered = false;

    protected static ?string $slug = 'email-preview';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::Envelope;

    #[Url]
    public string $template = 'trial-ending-soon';

    #[Url]
    public string $locale = 'pt_PT';

    #[Url]
    public string $theme = 'light';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return app()->environment(['local', 'testing'])
            && $user instanceof User && $user->isSuperAdmin() && $user->hasVerifiedEmail();
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Email previews');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('openPreview')
                ->label(__('Open preview'))
                ->color('gray')
                ->icon(Phosphor::ArrowSquareOut)
                ->url(fn (): string => $this->previewUrl(app(MailPreviewRegistry::class)))
                ->openUrlInNewTab(),
            Action::make('sendPreview')
                ->label(__('Send test email'))
                ->icon(Phosphor::PaperPlaneTilt)
                ->modalDescription(__('Send the selected template with sample data to the address below.'))
                ->fillForm(fn (): array => ['email' => auth()->user()->email])
                ->schema([TextInput::make('email')->label(__('Recipient email'))->email()->required()->maxLength(254)])
                ->rateLimit(5)
                ->successNotificationTitle(__('Test email sent'))
                ->action(function (array $data, Action $action, MailPreviewRegistry $previews): void {
                    abort_unless(static::canAccess(), 403);

                    $previews->preview($this->template, $this->locale)->sendTo($data['email']);
                    $action->success();
                }),
        ];
    }

    protected function getViewData(): array
    {
        $previews = app(MailPreviewRegistry::class);
        $preview = $previews->preview($this->template, $this->locale);

        return [
            'templates' => $previews->templateOptions(),
            'locales' => $previews->localeOptions(),
            'themes' => $previews->themeOptions(),
            'subject' => $preview->subject,
            'previewUrl' => $this->previewUrl($previews),
            'previewTheme' => $this->theme === 'dark' ? 'dark' : 'light',
        ];
    }

    private function previewUrl(MailPreviewRegistry $previews): string
    {
        return route('filament.tools.mail-preview.render', [
            'tenant' => Filament::getTenant(),
            'template' => $previews->normalizeTemplate($this->template),
            'locale' => $previews->normalizeLocale($this->locale),
        ], absolute: false);
    }
}
