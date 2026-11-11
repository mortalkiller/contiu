<?php

namespace App\Filament\Pages;

use App\Enums\PortugueseVatRegion;
use App\Exceptions\InvoiceXpressException;
use App\Filament\Pages\Concerns\HasInvoiceXpressPilotSettings;
use App\Models\InvoiceXpressConfig;
use App\Support\CurrentAccount;
use App\Support\InvoiceXpressClient;
use App\Support\InvoiceXpressIssue;
use App\Support\InvoiceXpressIssueRepository;
use App\Support\InvoiceXpressIssueSettings;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class InvoiceXpressSettingsPage extends InvoiceXpressSectionPage
{
    use HasInvoiceXpressPilotSettings;

    protected static string|BackedEnum|null $navigationIcon = Phosphor::Invoice;

    protected static ?int $navigationSort = 6;

    protected static ?string $slug = 'configurar-invoicexpress';

    protected string $view = 'filament.pages.invoice-xpress-settings';

    #[Locked]
    public int $accountId;

    #[Locked]
    public ?string $settingsSubdomain = null;

    /** @var list<string>|null */
    #[Locked]
    public ?array $connectionSeries = null;

    public array $regionalTaxNames = [];

    public static function canAccess(): bool
    {
        return InvoiceXpressImport::canAccess();
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->accountId = CurrentAccount::id();
        $this->fillSettings();
    }

    public static function connectionForm(Closure $canWrite, Closure $onSaved): Action
    {
        return Action::make('configure')
            ->label(__('Configure connection'))
            ->icon(Phosphor::Sliders)
            ->visible($canWrite)
            ->fillForm(fn (): array => ['subdomain' => InvoiceXpressConfig::current()?->subdomain])
            ->schema([
                TextInput::make('subdomain')->label(__('Account subdomain'))
                    ->suffix('.app.invoicexpress.com')
                    ->required()->maxLength(63)->regex(InvoiceXpressConfig::SUBDOMAIN_PATTERN),
                TextInput::make('api_key')->label(__('API key'))
                    ->password()->required()->maxLength(1024)->autocomplete('new-password'),
            ])
            ->modalSubmitActionLabel(__('Save connection'))
            ->action(function (array $data) use ($canWrite, $onSaved): void {
                abort_unless($canWrite(), 403);
                InvoiceXpressConfig::store($data['subdomain'], $data['api_key']);
                $onSaved();
                Notification::make()->success()->title(__('Connection saved'))->send();
            });
    }

    public function testConnection(InvoiceXpressClient $client): void
    {
        abort_unless(static::canAccess() && $this->accountId === CurrentAccount::id(), 403);
        $this->reset('connectionSeries');
        $this->resetValidation('connection');

        try {
            $series = $client->request('GET', '/sequences.json')['sequences'] ?? null;
            if (! is_array($series) || ! array_is_list($series)) {
                throw new InvoiceXpressException(__('InvoiceXpress returned an invalid series list.'));
            }

            $names = [];
            foreach ($series as $entry) {
                $name = is_array($entry) ? ($entry['serie'] ?? null) : null;
                if (! is_string($name) || trim($name) === '') {
                    throw new InvoiceXpressException(__('InvoiceXpress returned an invalid series list.'));
                }

                $names[] = $name;
            }

            $this->connectionSeries = $names;
        } catch (InvoiceXpressException $exception) {
            throw ValidationException::withMessages(['connection' => $exception->getMessage()]);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            self::connectionForm(
                fn (): bool => static::canAccess() && ! CurrentAccount::readOnly() && $this->accountId === CurrentAccount::id(),
                function (): void {
                    $this->reset('catalog', 'connectionSeries');
                    $this->resetValidation();
                    $this->fillSettings();
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
            'catalogChoices' => $subdomain !== null && $subdomain === ($this->catalog['account'] ?? null) ? $this->catalog : null,
            'canWriteDocuments' => InvoiceXpressIssue::canWriteDocuments(),
            'hasPendingAttempts' => $canManagePilot && InvoiceXpressIssueSettings::hasPendingAttempts(),
            'vatRegions' => PortugueseVatRegion::cases(),
            'vatCategories' => [
                'reduced' => __('Reduced rate'),
                'intermediate' => __('Intermediate rate'),
                'standard' => __('Standard rate'),
            ],
        ];
    }

    protected function beforeInvoiceXpressCatalogLoad(): void
    {
        if ($this->settingsSubdomain !== InvoiceXpressConfig::current()?->subdomain) {
            $this->fillSettings();
        }
    }

    private function fillSettings(): void
    {
        $this->settingsSubdomain = InvoiceXpressConfig::current()?->subdomain;
        $this->fillInvoiceXpressPilotSettings();
        $this->regionalTaxNames = [];
        $mappings = InvoiceXpressIssueSettings::regionalTaxNames();
        foreach (PortugueseVatRegion::cases() as $region) {
            foreach (array_keys($region->rates()) as $category) {
                $this->regionalTaxNames[$region->value][$category] = $mappings[$region->value][$category] ?? '';
            }
        }
    }

    protected function regionalTaxNamesForSave(): ?array
    {
        return $this->catalog === null ? null : $this->regionalTaxNames;
    }

    private function authorizeAccount(): void
    {
        abort_unless(InvoiceXpressIssueRepository::ownerCanIssue() && $this->accountId === CurrentAccount::id(), 403);
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return __('InvoiceXpress settings');
    }

    public function getBreadcrumbs(): array
    {
        return [IntegrationsPage::getUrl() => __('Integrations'), $this->getTitle()];
    }

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('InvoiceXpress');
    }

    #[Unexposed]
    public static function getNavigationGroup(): string
    {
        return __('Settings');
    }
}
