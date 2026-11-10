<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\HasInvoiceXpressPilotSettings;
use App\Models\InvoiceXpressConfig;
use App\Support\CurrentAccount;
use App\Support\InvoiceXpressIssue;
use App\Support\InvoiceXpressIssueRepository;
use App\Support\InvoiceXpressIssueSettings;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Livewire\Attributes\Locked;
use ToneGabes\Filament\Icons\Enums\Phosphor;
use UnitEnum;

class InvoiceXpressSettingsPage extends InvoiceXpressSectionPage
{
    use HasInvoiceXpressPilotSettings;

    protected static bool $shouldRegisterNavigation = true;

    protected static ?string $navigationLabel = 'InvoiceXpress';

    protected static string|UnitEnum|null $navigationGroup = 'Receitas';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::Invoice;

    protected static ?int $navigationSort = 6;

    protected static ?string $title = 'Configuração do InvoiceXpress';

    protected static ?string $slug = 'configurar-invoicexpress';

    protected string $view = 'filament.pages.invoice-xpress-settings';

    #[Locked]
    public int $accountId;

    public static function canAccess(): bool
    {
        return InvoiceXpressImport::canAccess();
    }

    public static function getNavigationUrl(): string
    {
        return InvoiceXpressIssuePage::canAccess() ? InvoiceXpressIssuePage::getUrl() : InvoiceXpressImport::getUrl();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->accountId = CurrentAccount::id();
        $this->fillInvoiceXpressPilotSettings();
    }

    public static function connectionForm(Closure $canWrite, Closure $onSaved): Action
    {
        return Action::make('configure')
            ->label('Configurar ligação')
            ->icon(Phosphor::Sliders)
            ->visible($canWrite)
            ->fillForm(fn (): array => ['subdomain' => InvoiceXpressConfig::current()?->subdomain])
            ->schema([
                TextInput::make('subdomain')->label('Subdomínio da conta')
                    ->suffix('.app.invoicexpress.com')
                    ->required()->maxLength(63)->regex(InvoiceXpressConfig::SUBDOMAIN_PATTERN),
                TextInput::make('api_key')->label('Chave de API')
                    ->password()->required()->maxLength(1024)->autocomplete('new-password'),
            ])
            ->modalSubmitActionLabel('Guardar ligação')
            ->action(function (array $data) use ($canWrite, $onSaved): void {
                abort_unless($canWrite(), 403);
                InvoiceXpressConfig::store($data['subdomain'], $data['api_key']);
                $onSaved();
                Notification::make()->success()->title('Ligação guardada')->send();
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            self::connectionForm(
                fn (): bool => static::canAccess() && ! CurrentAccount::readOnly() && $this->accountId === CurrentAccount::id(),
                function (): void {
                    $this->reset('catalog');
                    $this->resetValidation();
                    $this->fillInvoiceXpressPilotSettings();
                },
            ),
        ];
    }

    protected function getViewData(): array
    {
        abort_unless(static::canAccess() && $this->accountId === CurrentAccount::id(), 403);

        $subdomain = InvoiceXpressConfig::current()?->subdomain;
        $canManagePilot = InvoiceXpressIssueRepository::ownerCanIssue();

        return [
            'subdomain' => $subdomain,
            'canManagePilot' => $canManagePilot,
            'options' => InvoiceXpressIssue::options(),
            'catalogChoices' => $subdomain !== null && $subdomain === ($this->catalog['account'] ?? null) ? $this->catalog : null,
            'canWriteDocuments' => InvoiceXpressIssue::canWriteDocuments(),
            'hasPendingAttempts' => $canManagePilot && InvoiceXpressIssueSettings::hasPendingAttempts(),
        ];
    }

    private function authorizeAccount(): void
    {
        abort_unless(InvoiceXpressIssueRepository::ownerCanIssue() && $this->accountId === CurrentAccount::id(), 403);
    }
}
