<?php

namespace App\Filament\Pages;

use App\Actions\ConnectInvoicingProvider;
use App\Actions\DisconnectInvoicingProvider;
use App\Enums\FiscalDocumentType;
use App\Enums\IntegrationRecordState;
use App\Enums\Language;
use App\Enums\PortugueseVatRegion;
use App\Exceptions\InvoicingProviderException;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Models\FiscalDocument;
use App\Models\IntegrationConnection;
use App\Models\IntegrationRecord;
use App\Support\AccountantAccess;
use App\Support\CurrentAccount;
use App\Support\Invoicing\Invoicing;
use App\Support\Invoicing\InvoicingCapabilities;
use App\Support\Invoicing\InvoicingCatalog;
use App\Support\Invoicing\InvoicingProvider;
use App\Support\Invoicing\InvoicingProviders;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use MortalKiller\FilamentPageHeader\Components\Header;
use MortalKiller\FilamentPageHeader\Concerns\HasPageHeader;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * One invoicing provider's own page under Integrations. Before it is connected it only asks for
 * the credentials; once it is, it shows the series and taxes read from the provider, what stays
 * in sync and the automations. A secret credential is only ever read from the form on its way to
 * the provider: it is never sent back to the browser and a stored one is never read here.
 */
class InvoicingProviderPage extends Page
{
    use HasPageHeader;

    /** Stands for a stored secret, whatever it is. */
    private const string SECRET_PLACEHOLDER = '••••••••';

    protected static ?string $slug = 'integracoes/faturacao/{provider}';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.invoicing-provider';

    #[Locked]
    public string $provider = '';

    #[Locked]
    public int $accountId;

    /**
     * What is typed in the connection form. Secrets are dropped before every response.
     *
     * @var array<string, mixed>
     */
    public array $credentials = [];

    /**
     * The connection's settings as the toggles and the series choice show them.
     *
     * @var array<string, mixed>
     */
    public array $settings = [];

    /** @var array{catalog: ?InvoicingCatalog, error: ?string}|null */
    private ?array $catalogResult = null;

    /** @var array<string, int>|null */
    private ?array $syncCounts = null;

    #[Unexposed]
    public static function canAccess(): bool
    {
        $account = CurrentAccount::get();

        return $account !== null && AccountantAccess::canManage($account);
    }

    public function mount(string $provider): void
    {
        abort_unless(array_key_exists($provider, InvoicingProviders::all()), 404);

        $this->provider = $provider;
        $this->accountId = CurrentAccount::id();
        $this->fillSettings();
    }

    /** Every request, not only the first, comes from the owner of the account the page was opened in. */
    public function booted(): void
    {
        abort_unless(static::canAccess() && $this->accountId === CurrentAccount::id(), 403);
    }

    /** Whatever the request did, a secret typed in the form does not go back to the browser. */
    public function dehydrate(): void
    {
        $this->forgetSecrets();
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return $this->label();
    }

    /** @return array<string, string> */
    public function getBreadcrumbs(): array
    {
        return [IntegrationsPage::getUrl() => __('Integrations'), $this->label()];
    }

    /** The provider's name, whether it is connected and, under it, what the connection is for. */
    public function headerSchema(Schema $schema): Schema
    {
        return $schema->components([
            Header::make()
                ->contained(false)
                ->heading(fn (): string => $this->label())
                ->badges([
                    Text::make(fn (): string => $this->isConnected() ? __('Connected') : __('Not connected'))
                        ->badge()
                        ->color(fn (): string => $this->isConnected() ? 'success' : 'gray'),
                ])
                ->description(fn (): string => $this->summary()),
        ]);
    }

    public function connectForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('credentials')
            ->components(collect($this->driver()::credentialFields())
                ->map(fn (array $field, string $name): TextInput => TextInput::make($name)
                    ->label($field['label'])
                    ->helperText($field['help'] ?? null)
                    ->suffix($field['suffix'] ?? null)
                    ->password($field['secret'] ?? false)
                    // Keeps the browser from offering the app's own sign-in here.
                    ->autocomplete(($field['secret'] ?? false) ? 'new-password' : 'off')
                    ->markAsRequired()
                    ->maxLength(1024)
                    ->disabled(fn (): bool => ! Invoicing::canIssue()))
                ->values()
                ->all());
    }

    public function seriesForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('settings')
            ->components([
                Select::make('series')
                    ->label(__('Series for new documents'))
                    ->options(fn (): array => collect($this->catalog()['catalog']?->series ?? [])->pluck('name', 'id')->all())
                    ->placeholder(fn (): string => __('The provider\'s default series'))
                    ->live()
                    ->disabled(fn (): bool => ! Invoicing::canIssue()),
            ]);
    }

    public function automationsForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('settings')
            ->components([
                Toggle::make('auto_sync')
                    ->label(__('Sync every hour'))
                    ->helperText(__('Brings in what was issued outside Contiu.'))
                    ->live()
                    ->disabled(fn (): bool => ! Invoicing::canIssue()),
                Toggle::make('issue_from_app')
                    ->label(__('Issue from Contiu'))
                    ->helperText(fn (): string => __('When off, Contiu only creates the drafts and you issue them at :provider.', ['provider' => $this->label()]))
                    ->live()
                    ->disabled(fn (): bool => ! Invoicing::canIssue()),
            ]);
    }

    /**
     * As in the mocks: not connected, the credentials and what follows beside what the provider
     * does; connected, what was read from the provider beside the sync and the automations.
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Callout::make(fn (): string => __(':other is connected. Disconnect it before connecting :provider.', [
                'other' => (string) Invoicing::providerLabel(),
                'provider' => $this->label(),
            ]))
                ->description(__('Only one invoicing provider can be connected at a time.'))
                ->icon(Phosphor::WarningCircle)
                ->status('warning')
                ->actions([
                    Action::make('openConnectedProvider')
                        ->label(fn (): string => __('Open :provider', ['provider' => (string) Invoicing::providerLabel()]))
                        ->link()
                        ->visible(fn (): bool => array_key_exists((string) Invoicing::connection()?->provider, InvoicingProviders::all()))
                        ->url(fn (): string => static::getUrl(['provider' => Invoicing::connection()->provider])),
                ])
                ->visible(fn (): bool => $this->anotherProviderIsConnected()),
            Grid::make(['default' => 1, 'xl' => 3])
                ->visible(fn (): bool => ! $this->isConnected())
                ->schema([
                    Group::make([
                        $this->connectSection()->hidden(fn (): bool => $this->anotherProviderIsConnected()),
                        $this->nextStepsSection(),
                    ])->columnSpan(['default' => 1, 'xl' => 2]),
                    Group::make([
                        $this->capabilitiesSection(),
                        Callout::make(__('Until you connect, you can record documents issued in another program.'))
                            ->icon(Phosphor::Info)
                            ->actions([
                                Action::make('recordDocument')
                                    ->label(__('Record document'))
                                    ->link()
                                    ->url(fn (): string => FiscalDocumentResource::getUrl('create')),
                            ])
                            ->visible(fn (): bool => FiscalDocumentResource::canCreate()),
                    ]),
                ]),
            Grid::make(['default' => 1, 'xl' => 3])
                ->visible(fn (): bool => $this->isConnected())
                ->schema([
                    Group::make([
                        $this->connectionSection(),
                        $this->seriesSection(),
                        $this->taxesSection(),
                    ])->columnSpan(['default' => 1, 'xl' => 2]),
                    Group::make([
                        $this->syncSection(),
                        Section::make(__('Automations'))->schema([EmbeddedSchema::make('automationsForm')]),
                        $this->capabilitiesSection(),
                    ]),
                ]),
        ]);
    }

    /** Proves the credentials at the provider and, when they work, stores the connection. */
    public function connect(): void
    {
        abort_unless(Invoicing::canIssue(), 403);

        $fields = $this->driver()::credentialFields();
        $input = array_map(
            fn (mixed $value): string => is_string($value) ? $value : '',
            array_intersect_key($this->credentials, $fields),
        );
        $this->forgetSecrets();
        $this->resetErrorBag();

        try {
            app(ConnectInvoicingProvider::class)->handle($this->provider, $input);
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                if (array_key_exists($field, $fields)) {
                    $this->addError("credentials.{$field}", $messages[0]);
                } else {
                    Notification::make()->danger()->title($messages[0])->send();
                }
            }

            return;
        } catch (InvoicingProviderException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return;
        }

        $this->credentials = [];
        $this->forgetWhatWasRead();
        $this->fillSettings();

        Notification::make()->success()->title(__('Connected to :provider', ['provider' => $this->label()]))->send();
    }

    /**
     * Saves a toggle or the series as soon as it changes. Only the settings a connection is known
     * to have are kept; anything else the browser sends is dropped.
     */
    public function updatedSettings(mixed $value = null, ?string $key = null): void
    {
        abort_unless(Invoicing::canIssue(), 403);

        $connection = $this->ownConnection();
        abort_if($connection === null, 404);

        $submitted = array_intersect_key($this->settings, ConnectInvoicingProvider::DEFAULT_SETTINGS);

        if ($key !== null) {
            $submitted = array_intersect_key($submitted, [$key => true]);
        }

        if ($submitted !== []) {
            $changes = [];

            foreach ($submitted as $name => $state) {
                $changes[$name] = $name === 'series' ? $this->seriesToStore($state, $connection) : (bool) $state;
            }

            // The catalogue is cached against the connection's last change, which a setting is not.
            IntegrationConnection::withoutTimestamps(
                fn (): bool => $connection->update(['settings' => [...($connection->settings ?? []), ...$changes]]),
            );
            Invoicing::forget();
        }

        $this->fillSettings();
    }

    private function connectSection(): Section
    {
        return Section::make(__('Connection'))
            ->schema([
                Form::make([EmbeddedSchema::make('connectForm')])
                    ->id('connectForm')
                    ->livewireSubmitHandler('connect')
                    ->footer([
                        Actions::make([
                            Action::make('connect')
                                ->label(fn (): string => __('Connect to :provider', ['provider' => $this->label()]))
                                ->submit('connect')
                                ->disabled(fn (): bool => ! Invoicing::canIssue())
                                ->tooltip(fn (): ?string => CurrentAccount::readOnly() ? __('The account is read-only') : null),
                        ]),
                        Text::make(__('Contiu tests the connection before saving.'))->color('gray'),
                    ]),
            ]);
    }

    private function nextStepsSection(): Section
    {
        $steps = [
            [__('Contiu reads your series and taxes'), __('They stay on this page, so you can see which number each document gets.')],
            [__('You bring in what you have already issued'), __('You import a period and link the invoices to the receivables you already have.')],
            [__('You start creating documents in Contiu'), __('The draft shows up at :provider right away, for you to check before issuing.', ['provider' => $this->label()])],
        ];

        return Section::make(__('After you connect'))
            ->schema(collect($steps)
                ->map(fn (array $step, int $index): TextEntry => TextEntry::make("step_{$index}")
                    ->label(($index + 1).'. '.$step[0])
                    ->state($step[1])
                    ->color('gray'))
                ->all());
    }

    /** What the provider says it does: the same list before and after connecting. */
    private function capabilitiesSection(): Section
    {
        return Section::make(__('What this provider does'))
            ->inlineLabel()
            ->extraAttributes(['class' => 'fap-key-value'])
            ->schema([
                ...collect($this->capabilityRows())
                    ->map(fn (array $row, int $index): TextEntry => TextEntry::make("capability_{$index}")
                        ->label($row['label'])
                        ->state($row['state'])
                        ->badge()
                        ->color($row['supported'] ? 'success' : 'gray'))
                    ->all(),
                Text::make(__('Each provider says what it can do. Contiu only shows the document types it supports.'))->color('gray'),
            ]);
    }

    /** The account the connection is for and, in place of each secret, a fixed mask. */
    private function connectionSection(): Section
    {
        return Section::make(__('Connection'))
            ->inlineLabel()
            ->extraAttributes(['class' => 'fap-key-value'])
            ->schema([
                ...collect($this->driver()::credentialFields())
                    ->map(fn (array $field, string $name): TextEntry => ($field['secret'] ?? false)
                        ? TextEntry::make("credential_{$name}")
                            ->label($field['label'])
                            ->state(self::SECRET_PLACEHOLDER)
                        : TextEntry::make("credential_{$name}")
                            ->label($field['label'])
                            ->state(fn (): string => ($this->ownConnection()?->credentials[$name] ?? '').($field['suffix'] ?? ''))
                            ->fontFamily('mono'))
                    ->values()
                    ->all(),
                Actions::make([
                    Action::make('testConnection')
                        ->label(__('Test connection'))
                        ->color('gray')
                        ->action(fn () => $this->testConnection()),
                    Action::make('disconnect')
                        ->label(__('Disconnect'))
                        ->color('danger')
                        ->outlined()
                        ->requiresConfirmation()
                        ->modalHeading(fn (): string => __('Disconnect :provider?', ['provider' => $this->label()]))
                        ->modalDescription(__('Your documents stay in Contiu. Drafts still waiting at the provider have to be issued or deleted first.'))
                        ->modalSubmitActionLabel(__('Disconnect'))
                        ->disabled(fn (): bool => ! Invoicing::canIssue())
                        ->tooltip(fn (): ?string => CurrentAccount::readOnly() ? __('The account is read-only') : null)
                        ->action(fn () => $this->disconnect()),
                ])->key('connectionActions'),
            ]);
    }

    private function seriesSection(): Section
    {
        return Section::make(__('Series and numbering'))
            ->key('series')
            ->headerActions([
                Action::make('refreshCatalog')
                    ->label(fn (): string => __('Update from :provider', ['provider' => $this->label()]))
                    ->link()
                    ->action(fn () => $this->refreshCatalog()),
            ])
            ->schema([
                Callout::make(fn (): string => (string) $this->catalog()['error'])
                    ->icon(Phosphor::WarningCircle)
                    ->status('danger')
                    ->visible(fn (): bool => $this->catalog()['error'] !== null),
                RepeatableEntry::make('seriesRows')
                    ->label(__('Series and numbering'))
                    ->hiddenLabel()
                    ->state(fn (): array => $this->seriesRows())
                    ->table([
                        TableColumn::make(__('Document')),
                        TableColumn::make(__('Series')),
                        TableColumn::make(__('Next number')),
                    ])
                    ->schema([
                        TextEntry::make('document')->hiddenLabel(),
                        TextEntry::make('series')->hiddenLabel(),
                        TextEntry::make('next')->hiddenLabel()->fontFamily('mono')->placeholder('—'),
                    ])
                    ->visible(fn (): bool => $this->seriesRows() !== []),
                Text::make(__('No series was found at the provider.'))
                    ->color('gray')
                    ->visible(fn (): bool => $this->catalog()['catalog'] !== null && $this->seriesRows() === []),
                EmbeddedSchema::make('seriesForm')
                    ->visible(fn (): bool => count($this->catalog()['catalog']?->series ?? []) > 1),
                Text::make(fn (): string => __('Series are created at :provider, which reports them to the tax authority. Contiu only reads them.', ['provider' => $this->label()]))
                    ->color('gray'),
            ]);
    }

    private function taxesSection(): Section
    {
        return Section::make(__('Taxes and exemptions'))
            ->schema([
                RepeatableEntry::make('taxRows')
                    ->label(__('Taxes and exemptions'))
                    ->hiddenLabel()
                    ->state(fn (): array => $this->taxRows())
                    ->table([
                        TableColumn::make(__('In Contiu')),
                        TableColumn::make(fn (): string => __('At :provider', ['provider' => $this->label()])),
                    ])
                    ->schema([
                        TextEntry::make('local')->hiddenLabel(),
                        TextEntry::make('remote')->hiddenLabel()->fontFamily('mono'),
                    ])
                    ->visible(fn (): bool => $this->taxRows() !== []),
                Text::make(fn (): string => $this->catalog()['error'] !== null
                    ? __('The taxes could not be read from the provider.')
                    : __('No tax was found at the provider.'))
                    ->color('gray')
                    ->visible(fn (): bool => $this->taxRows() === []),
            ]);
    }

    private function syncSection(): Section
    {
        return Section::make(__('Synchronisation'))
            ->key('sync')
            ->headerActions([
                Action::make('viewSync')
                    ->label(__('View synchronisation'))
                    ->link()
                    ->url(fn (): string => InvoicingSyncPage::getUrl()),
            ])
            ->inlineLabel()
            ->extraAttributes(['class' => 'fap-key-value'])
            ->schema([
                TextEntry::make('lastSyncedAt')
                    ->label(__('Last synchronisation'))
                    ->state(fn () => $this->ownConnection()?->last_synced_at)
                    ->since()
                    ->dateTimeTooltip(fn (): string => Language::resolve(app()->getLocale())->dateFormat().' H:i')
                    ->placeholder(__('Never')),
                TextEntry::make('inSync')
                    ->label(__('In sync'))
                    ->state(fn (): string => $this->documentCount(IntegrationRecordState::InSync)),
                TextEntry::make('differs')
                    ->label(__('With differences'))
                    ->state(fn (): string => $this->documentCount(IntegrationRecordState::Differs))
                    ->color(fn (): ?string => ($this->syncCounts()[IntegrationRecordState::Differs->value] ?? 0) > 0 ? 'warning' : null),
                Callout::make(fn (): string => (string) $this->ownConnection()?->last_error)
                    ->icon(Phosphor::WarningCircle)
                    ->status('danger')
                    ->visible(fn (): bool => filled($this->ownConnection()?->last_error)),
            ]);
    }

    private function testConnection(): void
    {
        abort_unless(static::canAccess() && $this->isConnected(), 403);

        try {
            Invoicing::provider()->test();
        } catch (InvoicingProviderException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return;
        }

        Notification::make()->success()->title(__('The connection to :provider works.', ['provider' => $this->label()]))->send();
    }

    private function disconnect(): void
    {
        abort_unless(Invoicing::canIssue() && $this->isConnected(), 403);

        try {
            app(DisconnectInvoicingProvider::class)->handle();
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title($exception->validator->errors()->first())->send();

            return;
        }

        $this->forgetWhatWasRead();
        $this->fillSettings();

        Notification::make()->success()->title(__('Disconnected from :provider', ['provider' => $this->label()]))->send();
    }

    private function refreshCatalog(): void
    {
        abort_unless(static::canAccess() && $this->isConnected(), 403);

        $this->catalogResult = $this->readCatalog(refresh: true);

        if ($this->catalogResult['error'] !== null) {
            Notification::make()->danger()->title($this->catalogResult['error'])->send();

            return;
        }

        $this->fillSettings();

        Notification::make()->success()->title(__('Series and taxes updated'))->send();
    }

    private function label(): string
    {
        return InvoicingProviders::label($this->provider);
    }

    /** @return class-string<InvoicingProvider> */
    private function driver(): string
    {
        return InvoicingProviders::driver($this->provider);
    }

    /** The account's connection, when it is to this page's provider. */
    private function ownConnection(): ?IntegrationConnection
    {
        $connection = Invoicing::connection();

        return $connection?->provider === $this->provider ? $connection : null;
    }

    private function isConnected(): bool
    {
        return $this->ownConnection() !== null;
    }

    private function anotherProviderIsConnected(): bool
    {
        return Invoicing::connected() && ! $this->isConnected();
    }

    private function summary(): string
    {
        $connection = $this->ownConnection();

        if ($connection === null) {
            return __('In Portugal, invoices have to come from a program certified by the tax authority. Connect your :provider account and Contiu issues through it.', ['provider' => $this->label()]);
        }

        $what = $connection->setting('issue_from_app', true)
            ? __('Contiu creates the drafts and issues your documents through this account.')
            : __('Contiu creates the drafts in this account and you issue them at :provider.', ['provider' => $this->label()]);

        return $connection->created_at === null ? $what : $what.' '.__('Connected since :date.', [
            'date' => $connection->created_at->format(Language::resolve(app()->getLocale())->dateFormat()),
        ]);
    }

    private function fillSettings(): void
    {
        $connection = $this->ownConnection();
        $this->settings = [];

        if ($connection === null) {
            return;
        }

        foreach (ConnectInvoicingProvider::DEFAULT_SETTINGS as $name => $default) {
            $this->settings[$name] = $connection->setting($name, $default);
        }
    }

    private function forgetSecrets(): void
    {
        foreach ($this->driver()::credentialFields() as $name => $field) {
            if ($field['secret'] ?? false) {
                unset($this->credentials[$name]);
            }
        }
    }

    /** After connecting or disconnecting, what was read for the previous state no longer holds. */
    private function forgetWhatWasRead(): void
    {
        $this->catalogResult = null;
        $this->syncCounts = null;
    }

    /** The series to keep: one the provider has, or none, which leaves the provider's default. */
    private function seriesToStore(mixed $state, IntegrationConnection $connection): ?string
    {
        $catalog = $this->catalog()['catalog'];

        if ($catalog === null) {
            return $connection->setting('series');
        }

        return is_scalar($state) && filled($state) && $catalog->series((string) $state) !== null ? (string) $state : null;
    }

    /**
     * The provider's series and taxes, read once per request. A provider that fails gives its
     * message instead, so the rest of the page still shows.
     *
     * @return array{catalog: ?InvoicingCatalog, error: ?string}
     */
    private function catalog(): array
    {
        return $this->catalogResult ??= $this->readCatalog(refresh: false);
    }

    /** @return array{catalog: ?InvoicingCatalog, error: ?string} */
    private function readCatalog(bool $refresh): array
    {
        $provider = $this->isConnected() ? Invoicing::provider() : null;

        if ($provider === null) {
            return ['catalog' => null, 'error' => null];
        }

        try {
            return ['catalog' => $refresh ? $provider->refreshCatalog() : $provider->catalog(), 'error' => null];
        } catch (InvoicingProviderException $exception) {
            return ['catalog' => null, 'error' => $exception->getMessage()];
        }
    }

    /**
     * What the provider can do, without asking it anything: its capabilities are the driver's.
     */
    private function capabilities(): InvoicingCapabilities
    {
        return InvoicingProviders::for(new IntegrationConnection(['provider' => $this->provider, 'credentials' => []]))->capabilities();
    }

    /**
     * The series documents are numbered in, one row per type the provider issues.
     *
     * @return list<array{document: string, series: string, next: ?string}>
     */
    private function seriesRows(): array
    {
        $catalog = $this->catalog()['catalog'];
        $chosen = $this->ownConnection()?->setting('series');
        $series = $catalog?->series($chosen) ?? $catalog?->series(null);

        if ($catalog === null || $series === null) {
            return [];
        }

        $types = array_filter(array_merge(...array_column($this->documentGroups(), 1)), $this->capabilities()->supports(...));

        return array_values(array_map(fn (FiscalDocumentType $type): array => [
            'document' => $type->getLabel(),
            'series' => $series['name'],
            'next' => $catalog->nextNumber($type, $series['id']),
        ], $types));
    }

    /**
     * The provider's taxes beside how the app names the same rate.
     *
     * @return list<array{local: string, remote: string}>
     */
    private function taxRows(): array
    {
        return array_map(function (array $tax): array {
            $region = $tax['region'] === null ? null : (PortugueseVatRegion::tryFrom($tax['region'])?->label() ?? $tax['region']);
            $rate = $tax['basisPoints'] === 0
                ? __('VAT exempt')
                : __('VAT at :rate', ['rate' => Number::percentage($tax['basisPoints'] / 100, maxPrecision: 2, locale: app()->getLocale())]);

            return [
                'local' => $region === null || $tax['region'] === PortugueseVatRegion::Mainland->value ? $rate : "{$rate} · {$region}",
                'remote' => $tax['name'],
            ];
        }, $this->catalog()['catalog']?->taxes ?? []);
    }

    /**
     * What the provider says it does, grouped as people name the documents.
     *
     * @return list<array{label: string, state: string, supported: bool}>
     */
    private function capabilityRows(): array
    {
        $capabilities = $this->capabilities();

        $rows = array_map(function (array $group) use ($capabilities): array {
            $supported = array_values(array_filter($group[1], $capabilities->supports(...)));

            return [
                'label' => $group[0],
                'state' => match (count($supported)) {
                    count($group[1]) => __('Yes'),
                    0 => __('No'),
                    default => implode(', ', array_map(fn (FiscalDocumentType $type): string => $type->getLabel(), $supported)),
                },
                'supported' => $supported !== [],
            ];
        }, $this->documentGroups());

        return [
            ...$rows,
            ['label' => __('Drafts to check before issuing'), 'state' => $capabilities->drafts ? __('Yes') : __('No'), 'supported' => $capabilities->drafts],
            ['label' => __('Reporting to the tax authority'), 'state' => $capabilities->reportsToTaxAuthority ? __('Yes') : __('No'), 'supported' => $capabilities->reportsToTaxAuthority],
        ];
    }

    /**
     * Every document type, grouped and ordered as people name them. A type added later that no
     * group has yet gets a row of its own.
     *
     * @return list<array{0: string, 1: list<FiscalDocumentType>}>
     */
    private function documentGroups(): array
    {
        $groups = [
            [__('Invoices, invoice receipts and simplified invoices'), FiscalDocumentType::invoices()],
            [__('Credit and debit notes'), [FiscalDocumentType::CreditNote, FiscalDocumentType::DebitNote]],
            [__('Receipts'), [FiscalDocumentType::Receipt]],
            [__('Quotes and pro forma invoices'), [FiscalDocumentType::Quote, FiscalDocumentType::ProForma]],
            [__('Transport guides'), [FiscalDocumentType::TransportGuide]],
        ];

        $grouped = array_merge(...array_column($groups, 1));

        foreach (FiscalDocumentType::cases() as $type) {
            if (! in_array($type, $grouped, true)) {
                $groups[] = [$type->getLabel(), [$type]];
            }
        }

        return $groups;
    }

    /**
     * How many of the account's fiscal documents the provider holds in each state, in one query.
     *
     * @return array<string, int>
     */
    private function syncCounts(): array
    {
        $connection = $this->ownConnection();

        if ($connection === null) {
            return [];
        }

        return $this->syncCounts ??= IntegrationRecord::query()
            ->where('integration_connection_id', $connection->getKey())
            ->where('record_type', (new FiscalDocument)->getMorphClass())
            ->whereIn('state', [IntegrationRecordState::InSync, IntegrationRecordState::Differs])
            ->groupBy('state')
            ->selectRaw('state, count(*) as total')
            ->toBase()
            ->pluck('total', 'state')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();
    }

    private function documentCount(IntegrationRecordState $state): string
    {
        return trans_choice('{0} No documents|{1} 1 document|[2,*] :count documents', $this->syncCounts()[$state->value] ?? 0);
    }
}
