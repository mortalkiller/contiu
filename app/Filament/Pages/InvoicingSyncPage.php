<?php

namespace App\Filament\Pages;

use App\Actions\ImportRemoteDocument;
use App\Actions\SyncInvoicingDocuments;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Enums\IntegrationOperationStatus;
use App\Enums\IntegrationRecordState;
use App\Enums\Language;
use App\Exceptions\InvoicingProviderException;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Widgets\InvoicingSyncStats;
use App\Models\FiscalDocument;
use App\Models\IntegrationOperation;
use App\Models\IntegrationRecord;
use App\Models\Receivable;
use App\Support\AccountantAccess;
use App\Support\CurrentAccount;
use App\Support\Invoicing\Invoicing;
use App\Support\Invoicing\RemoteDocumentState;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\EmptyState;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * How the app's documents compare with the invoicing provider's, and what needs the user: the
 * documents that differ, the ones that only exist at the provider and the drafts still to issue.
 * From here the owner synchronises now, looks for the documents of a period and imports them.
 */
class InvoicingSyncPage extends Page implements HasTable
{
    use InteractsWithTable;

    private const string DRAFT = 'draft';

    private const string REMOTE_ONLY = 'remote_only';

    private const string CANCELED_AT_PROVIDER = 'canceled_at_provider';

    private const string DIFFERS = 'differs';

    /** How many of the latest operations the side column lists. */
    private const int OPERATIONS_SHOWN = 6;

    protected static ?string $slug = 'faturacao/sincronizacao';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.invoicing-sync';

    #[Locked]
    public int $accountId;

    /** @var array{from?: ?string, until?: ?string}|null */
    public ?array $period = [];

    /** @var array<int, array<int, string>> the receivables suggested for each record, asked for once per request */
    private array $receivableOptions = [];

    /** @var Collection<int, IntegrationOperation>|null */
    private ?Collection $latestOperations = null;

    #[Unexposed]
    public static function canAccess(): bool
    {
        $account = CurrentAccount::get();

        return $account !== null && AccountantAccess::canManage($account);
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
        $this->accountId = CurrentAccount::id();

        $this->form->fill([
            'from' => today()->startOfYear()->toDateString(),
            'until' => today()->toDateString(),
        ]);
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return __('Synchronisation');
    }

    public function getSubheading(): ?string
    {
        $provider = Invoicing::providerLabel();

        return $provider === null ? null : __('Contiu keeps the documents and their lines. :provider receives the draft, issues it and returns the number, the ATCUD and the PDF.', ['provider' => $provider]);
    }

    /** @return array<int|string, string> */
    public function getBreadcrumbs(): array
    {
        return [
            FiscalDocumentResource::getUrl() => __('Fiscal documents'),
            $this->getTitle(),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('provider')
                ->label(__('Invoicing provider'))
                ->color('gray')
                ->visible(fn (): bool => Invoicing::connected())
                ->url(fn (): string => InvoicingProviderPage::getUrl(['provider' => Invoicing::connection()->provider])),
            $this->writeAction('sync')
                ->label(__('Synchronise now'))
                ->visible(fn (): bool => Invoicing::connected())
                ->action(fn () => $this->synchronise()),
        ];
    }

    /** The two dates of "Importar um período". */
    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('period')
            ->columns(2)
            ->components([
                DatePicker::make('from')->label(__('From'))->required(),
                DatePicker::make('until')->label(__('Until'))->required(),
            ]);
    }

    /**
     * As in the mock: the four figures, then what needs the user and the period to import, with
     * the connection, who owns what and the latest operations in a narrower column beside them
     * (about 70/30).
     * With no provider connected there is only the way to the integrations.
     */
    public function content(Schema $schema): Schema
    {
        if (! Invoicing::connected()) {
            return $schema->components([
                EmptyState::make(__('No invoicing provider connected'))
                    ->description(__('Connect an invoicing provider to compare its documents with yours and import what was issued there.'))
                    ->icon(Phosphor::Plugs)
                    ->footer([
                        Action::make('integrations')
                            ->label(__('Open integrations'))
                            ->url(fn (): string => IntegrationsPage::getUrl()),
                    ]),
            ]);
        }

        return $schema->components([
            ...$this->getWidgetsSchemaComponents([InvoicingSyncStats::class]),
            Grid::make(['default' => 1, 'xl' => 7])->schema([
                Group::make([
                    EmbeddedTable::make(),
                    $this->periodSection(),
                ])->columnSpan(['default' => 1, 'xl' => 5]),
                Group::make([
                    $this->connectionSection(),
                    $this->ownershipSection(),
                    $this->operationsSection(),
                ])->columnSpan(['default' => 1, 'xl' => 2]),
            ]),
        ]);
    }

    /** "A precisar de ti": one row per document that differs, only exists at the provider or is still a draft. */
    public function table(Table $table): Table
    {
        $money = fn (TextColumn $column): TextColumn => $column->money('EUR', divideBy: 100)->placeholder('—')->alignEnd();

        return $table
            ->heading(__('Needing you'))
            ->query(fn (): Builder => $this->attentionQuery())
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('synced_at')->orderByDesc('id'))
            ->paginated([10, 25, 50])
            ->columns([
                TextColumn::make('document')
                    ->label(__('Document'))
                    ->state(fn (IntegrationRecord $record): string => $this->documentLabel($record))
                    ->description(fn (IntegrationRecord $record): string => $this->partyAndDate($record))
                    ->weight(FontWeight::Medium),
                TextColumn::make('situation')
                    ->label(__('What is going on'))
                    ->badge()
                    ->state(fn (IntegrationRecord $record): string => $this->situationLabel($record))
                    ->color(fn (IntegrationRecord $record): string => match ($this->situation($record)) {
                        self::DRAFT => 'gray',
                        self::REMOTE_ONLY => 'info',
                        default => 'warning',
                    }),
                $money(TextColumn::make('local_total'))
                    ->label(__('In Contiu'))
                    ->state(fn (IntegrationRecord $record): ?int => $this->documentOf($record)?->total_amount),
                $money(TextColumn::make('remote_total'))
                    ->label(fn (): string => __('In :provider', ['provider' => Invoicing::providerLabel()]))
                    ->state(fn (IntegrationRecord $record): ?int => is_int($record->remote['total'] ?? null) ? $record->remote['total'] : null),
            ])
            ->recordActions([
                Action::make('review')
                    ->label(__('Review'))
                    ->button()->color('gray')->size('sm')
                    ->visible(fn (IntegrationRecord $record): bool => in_array($this->situation($record), [self::DIFFERS, self::CANCELED_AT_PROVIDER], true) && $this->documentOf($record) !== null)
                    ->url(fn (IntegrationRecord $record): string => $this->documentUrl($record, 'view')),
                $this->importAction(),
                Action::make('open')
                    ->label(__('Open draft'))
                    ->button()->color('gray')->size('sm')
                    ->visible(fn (IntegrationRecord $record): bool => $this->situation($record) === self::DRAFT)
                    ->url(fn (IntegrationRecord $record): string => $this->documentUrl($record, 'draft')),
            ])
            ->emptyStateHeading(__('Nothing needs you'))
            ->emptyStateDescription(fn (): string => __('Every document is the same in Contiu and in :provider.', ['provider' => Invoicing::providerLabel()]));
    }

    /** The form's button: asks the provider for the documents of the chosen period. */
    public function importPeriod(): void
    {
        $period = $this->form->getState();

        $this->synchronise(CarbonImmutable::parse($period['from']), CarbonImmutable::parse($period['until']), onForm: true);
    }

    /**
     * Compares with the provider and says how it went. What the action refuses about the period
     * goes on the field it is about when the period came from the form.
     */
    private function synchronise(?CarbonInterface $from = null, ?CarbonInterface $until = null, bool $onForm = false): void
    {
        $this->authorizeAccount();

        try {
            $counts = app(SyncInvoicingDocuments::class)->handle($from, $until);
        } catch (ValidationException $exception) {
            if ($onForm) {
                foreach ($exception->errors() as $field => $messages) {
                    $this->addError("period.{$field}", $messages[0]);
                }
            } else {
                $this->notifyFailure(collect($exception->errors())->flatten()->first() ?? $exception->getMessage());
            }

            return;
        } catch (InvoicingProviderException $exception) {
            $this->notifyFailure($exception->getMessage());

            return;
        } finally {
            $this->refreshFigures();
        }

        $summary = __(':in_sync in sync · :differs with differences · :remote_only to import', $counts);

        Notification::make()
            ->title(__('Synchronised'))
            ->body($counts['issued'] > 0
                ? $summary.' · '.trans_choice('{1} 1 draft issued|[2,*] :count drafts issued', $counts['issued'])
                : $summary)
            ->success()
            ->send();
    }

    private function importAction(): Action
    {
        return $this->writeAction('import')
            ->label(__('Import'))
            ->button()->color('gray')->size('sm')
            ->visible(fn (IntegrationRecord $record): bool => $this->situation($record) === self::REMOTE_ONLY)
            ->modalHeading(fn (IntegrationRecord $record): string => __('Import :number', ['number' => $this->documentLabel($record)]))
            ->modalWidth(Width::Large)
            ->modalSubmitActionLabel(__('Import'))
            ->schema(fn (IntegrationRecord $record): array => [
                Group::make([
                    TextEntry::make('remote_party')->label(__('Client'))->state($record->remote['party_name'] ?? null)->placeholder('—'),
                    TextEntry::make('remote_tax_id')->label(__('Tax ID'))->state($record->remote['party_tax_id'] ?? null)->placeholder('—')->fontFamily('mono'),
                    TextEntry::make('remote_date')->label(__('Date'))->state($this->remoteDate($record))->placeholder('—'),
                    TextEntry::make('remote_total')->label(__('Total'))->state(is_int($record->remote['total'] ?? null) ? Money::format($record->remote['total']) : null)->placeholder('—'),
                ])->inlineLabel()->extraAttributes(['class' => 'fap-key-value']),
                Select::make('receivable_id')
                    ->label(__('Receivable'))
                    ->options(fn (): array => $this->receivableOptionsFor($record))
                    ->placeholder(__('None'))
                    ->helperText(__('The charge this document invoices, if you already have it in Contiu.')),
            ])
            ->action(function (IntegrationRecord $record, array $data, Action $action): void {
                $this->authorizeAccount();

                try {
                    $document = app(ImportRemoteDocument::class)->handle($record, filled($data['receivable_id'] ?? null) ? (int) $data['receivable_id'] : null);
                } catch (ValidationException $exception) {
                    $this->notifyFailure(collect($exception->errors())->flatten()->first() ?? $exception->getMessage());
                    $action->halt();
                } catch (InvoicingProviderException $exception) {
                    $this->notifyFailure($exception->getMessage());
                    $action->halt();
                } finally {
                    $this->refreshFigures();
                }

                Notification::make()->title(__(':number imported', ['number' => $document->number]))->success()->send();
            });
    }

    /**
     * A write action no policy covers, as App\Filament\Actions\Concerns\DisabledWhenReadOnly:
     * disabled for whoever cannot issue, with the reason when it is the account being read-only.
     */
    private function writeAction(string $name): Action
    {
        return Action::make($name)
            ->disabled(fn (): bool => ! Invoicing::canIssue())
            ->tooltip(fn (): ?string => CurrentAccount::readOnly() ? __('The account is read-only') : null);
    }

    private function periodSection(): Section
    {
        return Section::make(__('Import a period'))->schema([
            Form::make([EmbeddedSchema::make('form')])
                ->id('period')
                ->livewireSubmitHandler('importPeriod')
                ->footer([
                    Actions::make([
                        $this->writeAction('importPeriod')
                            ->label(__('Find documents'))
                            ->color('gray')
                            ->submit('importPeriod'),
                    ])->key('period-actions'),
                ]),
            Text::make(__('To bring in the history when you connect a provider. Day to day it is not needed: the synchronisation runs by itself.'))->color('gray'),
        ]);
    }

    private function connectionSection(): Section
    {
        $connection = Invoicing::connection();

        return Section::make(__('Connection'))
            ->afterHeader([Text::make(__('Connected'))->badge()->color('success')])
            ->inlineLabel()
            ->extraAttributes(['class' => 'fap-key-value'])
            ->schema([
                TextEntry::make('provider')->label(__('Provider'))->state(fn (): ?string => Invoicing::providerLabel()),
                TextEntry::make('last_synced_at')->label(__('Last synchronisation'))
                    ->state(fn (): string => $connection?->last_synced_at === null ? __('Never') : $this->formatMoment($connection->last_synced_at)),
                TextEntry::make('next_sync')->label(__('Next synchronisation'))
                    ->state(fn (): string => $connection?->setting('auto_sync', true) ? __('Every hour') : __('Only when you ask')),
                TextEntry::make('last_error')->label(__('Last error'))
                    ->state(fn (): ?string => $connection?->last_error)
                    ->color('danger')
                    ->inlineLabel(false)
                    ->visible(fn (): bool => filled($connection?->last_error)),
            ]);
    }

    /** "Quem manda em quê": what the app decides and what the provider does. */
    private function ownershipSection(): Section
    {
        $provider = Invoicing::providerLabel();

        return Section::make(__('Who owns what'))
            ->inlineLabel()
            ->extraAttributes(['class' => 'fap-key-value'])
            ->schema([
                TextEntry::make('owns_documents')->label(__('Documents and lines'))->state(__('Contiu')),
                TextEntry::make('owns_clients')->label(__('Clients and articles'))->state(__('Contiu')),
                TextEntry::make('owns_numbers')->label(__('Number, ATCUD and PDF'))->state($provider),
                TextEntry::make('owns_series')->label(__('Series and taxes'))->state($provider),
            ]);
    }

    private function operationsSection(): Section
    {
        return Section::make(__('Latest operations'))->schema(function (): array {
            $operations = $this->latestOperations();

            if ($operations->isEmpty()) {
                return [Text::make(__('Nothing was asked of the provider yet.'))->color('gray')];
            }

            return $operations->map(function (IntegrationOperation $operation): TextEntry {
                $failed = $operation->status === IntegrationOperationStatus::Failed;

                return TextEntry::make("operation_{$operation->getKey()}")
                    ->label(__('Operation'))
                    ->hiddenLabel()
                    ->state($operation->summary)
                    ->weight(FontWeight::Medium)
                    ->belowContent(Text::make(implode(' · ', array_filter([
                        $this->formatMoment($operation->created_at),
                        $failed ? $operation->detail : null,
                    ])))->color('gray')->size(TextSize::Small))
                    ->afterContent($failed ? Text::make(__('Failed'))->badge()->color('danger') : null);
            })->all();
        });
    }

    /**
     * The connection's records that need the user: the ones that differ, the ones with nothing
     * here and the ones whose document is still a draft. The document comes with them.
     *
     * @return Builder<IntegrationRecord>
     */
    private function attentionQuery(): Builder
    {
        $this->authorizeAccount();
        $documents = (new FiscalDocument)->getMorphClass();

        return IntegrationRecord::query()
            ->where('integration_connection_id', Invoicing::connection()?->getKey() ?? 0)
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->whereNull('record_type')->where('state', IntegrationRecordState::RemoteOnly))
                ->orWhere(fn (Builder $query) => $query->where('record_type', $documents)->where('state', IntegrationRecordState::Differs))
                ->orWhereHasMorph('record', [FiscalDocument::class], fn (Builder $query) => $query->where('status', FiscalDocumentStatus::Draft)))
            ->with('record');
    }

    private function documentOf(IntegrationRecord $entry): ?FiscalDocument
    {
        return $entry->record instanceof FiscalDocument ? $entry->record : null;
    }

    /** Which of the four reasons puts this record on the list. */
    private function situation(IntegrationRecord $entry): string
    {
        $document = $this->documentOf($entry);

        return match (true) {
            $document?->isDraft() === true => self::DRAFT,
            $document === null && $entry->state === IntegrationRecordState::RemoteOnly => self::REMOTE_ONLY,
            ($entry->remote['state'] ?? null) === RemoteDocumentState::Canceled->value && $document?->status === FiscalDocumentStatus::Active => self::CANCELED_AT_PROVIDER,
            default => self::DIFFERS,
        };
    }

    private function situationLabel(IntegrationRecord $entry): string
    {
        $provider = ['provider' => Invoicing::providerLabel()];

        return match ($this->situation($entry)) {
            self::DRAFT => __('Draft to issue'),
            self::REMOTE_ONLY => __('Only in :provider', $provider),
            self::CANCELED_AT_PROVIDER => __('Cancelled in :provider', $provider),
            default => __('Different total'),
        };
    }

    /** The number here, else the number at the provider, else "Fatura por emitir". */
    private function documentLabel(IntegrationRecord $entry): string
    {
        $document = $this->documentOf($entry);
        $type = $document?->type ?? FiscalDocumentType::tryFrom((string) ($entry->remote['type'] ?? ''));
        $typeLabel = $type?->getLabel() ?? __('Document');

        return match (true) {
            filled($document?->number) => $document->number,
            filled($entry->remote['number'] ?? null) => $entry->remote['number'],
            $document?->isDraft() === true => __(':type to issue', ['type' => $typeLabel]),
            default => $typeLabel,
        };
    }

    /** "Estúdio Atlântico · 01/10/2026", from the document here or from what was read at the provider. */
    private function partyAndDate(IntegrationRecord $entry): string
    {
        $document = $this->documentOf($entry);

        return implode(' · ', array_filter([
            $document === null ? ($entry->remote['party_name'] ?? null) : $document->party_name,
            $document === null ? $this->remoteDate($entry) : $document->document_date?->format($this->dateFormat()),
        ]));
    }

    private function remoteDate(IntegrationRecord $entry): ?string
    {
        return filled($entry->remote['date'] ?? null) ? CarbonImmutable::parse($entry->remote['date'])->format($this->dateFormat()) : null;
    }

    /** The document's own page when the resource has it ($page), else the page where it is edited. */
    private function documentUrl(IntegrationRecord $entry, string $page): string
    {
        return FiscalDocumentResource::getUrl(
            array_key_exists($page, FiscalDocumentResource::getPages()) ? $page : 'edit',
            ['record' => $entry->record_id],
        );
    }

    /**
     * The receivables this document may be charging: "description · due date · amount".
     *
     * @return array<int, string>
     */
    private function receivableOptionsFor(IntegrationRecord $entry): array
    {
        return $this->receivableOptions[$entry->getKey()] ??= app(ImportRemoteDocument::class)->suggestions($entry)
            ->mapWithKeys(fn (Receivable $receivable): array => [$receivable->getKey() => implode(' · ', array_filter([
                $receivable->description,
                $receivable->due_on?->format($this->dateFormat()),
                Money::format($receivable->amount),
            ]))])
            ->all();
    }

    /** @return Collection<int, IntegrationOperation> */
    private function latestOperations(): Collection
    {
        return $this->latestOperations ??= IntegrationOperation::query()
            ->where('integration_connection_id', Invoicing::connection()->getKey())
            ->latest()
            ->latest('id')
            ->limit(self::OPERATIONS_SHOWN)
            ->get();
    }

    /** After something changed at the provider's side of things: the figures and the log are read again. */
    private function refreshFigures(): void
    {
        $this->latestOperations = null;
        $this->receivableOptions = [];
        $this->dispatch(InvoicingSyncStats::REFRESH_EVENT);
    }

    private function notifyFailure(string $message): void
    {
        Notification::make()->title($message)->danger()->send();
    }

    /** "Hoje às 10:30" or "11/11/2026 às 16:20". */
    private function formatMoment(CarbonInterface $moment): string
    {
        return $moment->isToday()
            ? __('Today at :time', ['time' => $moment->format('H:i')])
            : __(':date at :time', ['date' => $moment->format($this->dateFormat()), 'time' => $moment->format('H:i')]);
    }

    private function dateFormat(): string
    {
        return Language::resolve(app()->getLocale())->dateFormat();
    }

    private function authorizeAccount(): void
    {
        abort_unless(static::canAccess() && $this->accountId === CurrentAccount::id(), 403);
    }
}
