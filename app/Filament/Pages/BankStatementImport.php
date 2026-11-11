<?php

namespace App\Filament\Pages;

use App\Actions\ApplyBankTransaction;
use App\Actions\ImportBankStatement;
use App\Actions\MatchBankTransactions;
use App\Enums\BankTransactionStatus;
use App\Enums\CategoryType;
use App\Enums\DifferenceExplanation;
use App\Enums\ExpenseKind;
use App\Enums\Language;
use App\Enums\PaymentMethod;
use App\Filament\Actions\OneOffIncomeAction;
use App\Filament\Actions\PlatformPayoutAction;
use App\Filament\Support\QuickCreate;
use App\Filament\Widgets\CompanyAccountStats;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\PlatformPayout;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\RecurringExpense;
use App\Models\Retainer;
use App\Models\Supplier;
use App\Support\BankCandidate;
use App\Support\BankCsvParser;
use App\Support\BankCsvResult;
use App\Support\BankSuggestion;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use App\Support\Money;
use App\Support\PlatformPayoutAmounts;
use App\Support\TransferCover;
use App\Support\TransferCoverage;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Resources\Concerns\HasTabs;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Enums\Width;
use Filament\Tables\Columns\Layout\Split;
use Filament\Tables\Columns\Layout\Stack;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * "Extrato bancário": upload the bank's export (CSV or Excel), then go line by line — accept the suggestion, pick
 * another match, turn a debit into an expense, or ignore it.
 */
class BankStatementImport extends Page implements HasTable
{
    use HasTabs;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Phosphor::Bank;

    protected static ?string $slug = 'extrato-bancario';

    protected static ?int $navigationSort = 5;

    /** Only for an account with a bank account just for the activity (finance settings). */
    public static function canAccess(): bool
    {
        return app(FinanceSettings::class)->hasBusinessAccount();
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    /** In the menu: how many statement lines are still to reconcile. */
    public static function getNavigationBadge(): ?string
    {
        $pending = BankTransaction::pending()->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('Statement lines to reconcile');
    }

    /**
     * Stored upload waiting for a manual column mapping. Locked: it is a path on the disk every
     * account shares, so the browser must never choose it.
     */
    #[Locked]
    public ?string $mappingFile = null;

    /** @var array<int, string> */
    #[Locked]
    public array $mappingHeaders = [];

    /** @var array<int, BankSuggestion> */
    protected array $suggestions = [];

    public function mount(): void
    {
        $this->loadDefaultActiveTab();
    }

    public function getSubheading(): string
    {
        $pending = BankTransaction::pending()->count();

        return $pending === 0
            ? __('Upload a CSV or Excel bank statement to match inflows to receivables and outflows to expenses.')
            : trans_choice('{1} :count line to process. Each bank line is explained by one of your records.|[2,*] :count lines to process. Each bank line is explained by one of your records.', $pending);
    }

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make(__('Unprocessed'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', BankTransactionStatus::Pending))
                ->badge(fn () => BankTransaction::pending()->count() ?: null)
                ->badgeColor('warning'),
            'done' => Tab::make(__('Processed'))
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [BankTransactionStatus::Matched, BankTransactionStatus::OwnTransfer])),
            'ignored' => Tab::make(__('Ignored lines'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', BankTransactionStatus::Ignored)),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            ...$this->getWidgetsSchemaComponents([CompanyAccountStats::make(['withLineProgress' => true, 'withHeading' => false])]),
            $this->getTabsContentComponent(),
            EmbeddedTable::make(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('help')
                ->label(__('How does it work?'))
                ->color('gray')
                ->link()
                ->url(HelpCentre::getUrl().'#extrato'),
            Action::make('autoReconcile')
                ->label(__('Reconcile automatically'))
                ->color('gray')
                ->visible(fn () => BankTransaction::pending()->exists())
                ->disabled(fn () => CurrentAccount::readOnly())
                ->modalHeading(__('Reconcile automatically'))
                ->modalIcon(Phosphor::MagicWand)
                ->modalWidth(Width::TwoExtraLarge)
                ->modalContent(fn () => view('filament.pages.partials.auto-reconcile-preview', ['plan' => $this->autoReconcilePlan()]))
                ->modalSubmitAction(fn (Action $action) => ($count = count($this->autoReconcilePlan()['done'])) === 0
                    ? false
                    : $action->label(trans_choice('{1} Reconcile :count line|[2,*] Reconcile :count lines', $count)))
                ->modalCancelActionLabel(__('Close'))
                ->action(fn () => $this->autoReconcile()),
            $this->uploadAction(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            // Rows load right after the page; a skeleton shows meanwhile (filament-skeleton-loading).
            ->deferLoading()
            ->query(fn () => $this->modifyQueryWithActiveTab(BankTransaction::query()
                ->with(['links.linkable', 'customerRefund.creditNote'])
                ->addSelect(['platform_payout_history_id' => PlatformPayout::query()->select('id')
                    ->whereColumn('bank_transaction_id', 'bank_transactions.id')->latest('id')->limit(1)])))
            ->defaultSort('booked_on', 'desc')
            // As in the mock: one row per line, the date with debit or credit beside the
            // description and the signed amount, the suggestion in a soft box under them, and
            // the line's actions under it all (filament-appearance's fap-actions-below).
            ->extraAttributes(['class' => 'fap-actions-below'])
            ->searchPlaceholder(__('Description or amount'))
            ->columns([
                Split::make([
                    Stack::make([
                        TextColumn::make('booked_on')->label(__('Date'))->date()->weight(FontWeight::Medium),
                        TextColumn::make('direction')
                            ->state(fn (BankTransaction $record): string => $record->is_credit ? __('Credit') : __('Debit'))
                            ->color('gray')
                            ->size(TextSize::Small),
                    ])->grow(false)->extraAttributes(['class' => 'w-24']),
                    Stack::make([
                        Split::make([
                            TextColumn::make('description')
                                ->label(__('Description'))
                                ->weight(FontWeight::Medium)
                                ->wrap()
                                ->searchable(query: fn (Builder $query, string $search): Builder => $this->searchLines($query, $search)),
                            TextColumn::make('amount')
                                ->label(__('Monetary amount'))
                                ->formatStateUsing(fn (BankTransaction $record): string => ($record->is_credit ? '+' : '−').Money::format($record->absolute_amount, locale: app()->getLocale()))
                                ->alignEnd()
                                ->weight(FontWeight::SemiBold)
                                ->color(fn (BankTransaction $record) => $record->is_credit ? 'success' : 'danger')
                                ->grow(false),
                        ]),
                        TextColumn::make('suggestion')
                            ->label(fn () => $this->activeTab === 'pending' ? __('Suggestion') : __('Processed as'))
                            ->state(fn (BankTransaction $record) => $this->describe($record))
                            ->listWithLineBreaks()
                            // On "Por tratar" the suggestion stands out: it is what the user came to confirm.
                            ->icon(fn (BankTransaction $record) => match ($this->suggestionTone($record)) {
                                'match' => Phosphor::CheckCircle,
                                'choose' => Phosphor::ListMagnifyingGlass,
                                'none' => Phosphor::MinusCircle,
                                default => null,
                            })
                            ->extraAttributes(fn (BankTransaction $record): array => array_filter(['data-suggestion' => $this->suggestionTone($record)]))
                            ->color(fn (BankTransaction $record) => $record->status === BankTransactionStatus::Pending && $this->suggestionFor($record)->best() === null ? 'gray' : null)
                            ->wrap(),
                    ])->space(2),
                ]),
            ])
            ->recordActions([
                Action::make('accept')
                    ->label(__('Accept'))
                    ->button()
                    ->size('sm')
                    ->visible(fn (BankTransaction $record) => $record->status === BankTransactionStatus::Pending && $this->suggestionFor($record)->best() !== null)
                    ->disabled(fn () => CurrentAccount::readOnly())
                    ->action(fn (BankTransaction $record) => $this->apply(fn (ApplyBankTransaction $apply) => $apply->accept($record, $this->suggestionFor($record)->best()))),
                Action::make('link')
                    ->label(__('Link…'))
                    ->button()
                    ->size('sm')
                    ->color('gray')
                    ->visible(fn (BankTransaction $record) => $record->status === BankTransactionStatus::Pending)
                    ->disabled(fn () => CurrentAccount::readOnly())
                    ->modalHeading(fn (BankTransaction $record) => __('Link :amount · :date', [
                        'amount' => Money::format($record->amount, locale: app()->getLocale()),
                        'date' => $record->booked_on->format(Language::resolve(app()->getLocale())->dateFormat()),
                    ]))
                    ->modalDescription(fn (BankTransaction $record) => $record->description)
                    ->modalSubmitActionLabel(__('Link transaction'))
                    ->schema(fn (BankTransaction $record): array => $this->linkSchema($record))
                    ->action(fn (BankTransaction $record, array $data) => $this->apply(fn (ApplyBankTransaction $apply) => $apply->applyTargets(
                        $record,
                        $data['targets'],
                        DifferenceExplanation::tryFrom((string) ($data['explanation'] ?? '')),
                    ))),
                Action::make('createIncome')
                    ->label(__('Create income'))
                    ->size('sm')
                    ->color('gray')
                    ->visible(fn (BankTransaction $record) => $record->status === BankTransactionStatus::Pending && $record->is_credit)
                    ->disabled(fn () => CurrentAccount::readOnly())
                    ->modalHeading(fn (BankTransaction $record) => __('One-off income of :amount', [
                        'amount' => Money::format($record->absolute_amount, locale: app()->getLocale()),
                    ]))
                    ->modalDescription(fn (BankTransaction $record) => __(':date · :description. Enter the gross amount from the invoice-receipt and the fee retained by the platform.', [
                        'date' => $record->booked_on->format(Language::resolve(app()->getLocale())->dateFormat()),
                        'description' => $record->description,
                    ]))
                    ->modalSubmitActionLabel(__('Create'))
                    ->fillForm(fn (BankTransaction $record) => [
                        'gross_amount' => $record->absolute_amount,
                        'commission' => 0,
                        'method' => PaymentMethod::Transfer->value,
                        'tax_exempt' => app(FinanceSettings::class)->paymentsExemptByDefault(),
                    ])
                    ->schema(fn (BankTransaction $record) => OneOffIncomeAction::formSchema(withDate: false, received: $record->absolute_amount))
                    ->action(fn (BankTransaction $record, array $data) => $this->apply(fn (ApplyBankTransaction $apply) => $apply->createOneOffIncome($record, $data))),
                PlatformPayoutAction::make()
                    ->icon(null)
                    ->size('sm')
                    ->action(fn (BankTransaction $record, array $data, Action $action) => $this->apply(
                        fn (ApplyBankTransaction $apply) => $apply->createPlatformPayouts(
                            $record,
                            array_map(PlatformPayoutAmounts::minorUnitsFromForm(...), $data['payouts']),
                        ),
                        $action,
                    )),
                Action::make('createExpense')
                    ->label(__('Create expense'))
                    ->size('sm')
                    ->color('gray')
                    ->visible(fn (BankTransaction $record) => $record->status === BankTransactionStatus::Pending && ! $record->is_credit)
                    ->disabled(fn () => CurrentAccount::readOnly())
                    ->modalHeading(fn (BankTransaction $record) => __('New expense of :amount', [
                        'amount' => Money::format($record->absolute_amount, locale: app()->getLocale()),
                    ]))
                    ->modalDescription(fn (BankTransaction $record) => $record->booked_on->format(Language::resolve(app()->getLocale())->dateFormat()).' · '.$record->description)
                    ->fillForm(fn (BankTransaction $record) => [
                        'supplier_id' => $this->suggestionFor($record)->candidates->firstWhere('type', 'supplier')?->model->getKey(),
                        'kind' => ExpenseKind::Overhead->value,
                        'vat_rate' => '0.0000',
                    ])
                    ->schema([
                        QuickCreate::supplier(Select::make('supplier_id')
                            ->label(__('Supplier'))
                            ->options(fn () => Supplier::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required()),
                        QuickCreate::category(Select::make('category_id')
                            ->label(__('Category'))
                            ->options(fn () => Category::query()->expense()->pluck('name', 'id')->all()), CategoryType::Expense),
                        TextInput::make('description')->label(__('Description'))->placeholder(__('E.g. ChatGPT Pro')),
                        Radio::make('kind')
                            ->label(__('Cost type'))
                            ->options(ExpenseKind::class)
                            ->required(),
                        $this->directCostTarget('project_id', __('Job'), Project::query()->orderByDesc('quoted_on')),
                        $this->directCostTarget('retainer_id', __('Retainer'), Retainer::query()->orderBy('code')),
                        ToggleButtons::make('vat_rate')
                            ->label(__('VAT included in the amount'))
                            ->options(Money::vatOptions())
                            ->inline()
                            ->grouped()
                            ->required(),
                    ])
                    ->action(fn (BankTransaction $record, array $data) => $this->apply(fn (ApplyBankTransaction $apply) => $apply->createExpense($record, [
                        ...$data,
                        'kind' => $data['kind'] instanceof ExpenseKind ? $data['kind']->value : $data['kind'],
                    ]))),
                Action::make('ownTransfer')
                    ->label(__('Transfer between my accounts'))
                    ->size('sm')
                    ->color('gray')
                    ->tooltip(__('Transfer between my accounts: money moved between your own accounts, excluded from income and expenses'))
                    ->visible(fn (BankTransaction $record) => $record->status === BankTransactionStatus::Pending)
                    ->disabled(fn () => CurrentAccount::readOnly())
                    ->action(fn (BankTransaction $record) => $this->apply(fn (ApplyBankTransaction $apply) => $apply->markOwnTransfer($record))),
                Action::make('details')
                    ->label(__('View details'))
                    ->size('sm')
                    ->color('gray')
                    ->visible(fn (BankTransaction $record) => $record->platform_payout_history_id !== null
                        || in_array($record->status, [BankTransactionStatus::Matched, BankTransactionStatus::OwnTransfer], true))
                    ->modalHeading(__('Bank statement line details'))
                    ->modalWidth(Width::ThreeExtraLarge)
                    ->modalContent(fn (BankTransaction $record) => view('filament.pages.partials.bank-line-details', ['details' => $this->lineDetails($record)]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('Close')),
                Action::make('undo')
                    ->label(__('Reprocess'))
                    ->size('sm')
                    ->color('gray')
                    ->visible(fn (BankTransaction $record) => $record->status !== BankTransactionStatus::Pending)
                    ->disabled(fn () => CurrentAccount::readOnly())
                    ->requiresConfirmation()
                    ->modalHeading(__('Reprocess this line?'))
                    ->modalDescription(fn (BankTransaction $record) => $this->undoDescription($record))
                    ->modalSubmitActionLabel(__('Reprocess'))
                    ->action(function (BankTransaction $record): void {
                        try {
                            app(ApplyBankTransaction::class)->undo($record);
                        } catch (DomainException $exception) {
                            Notification::make()->title(__('Unable to reprocess the line'))->body($exception->getMessage())->danger()->send();

                            return;
                        }

                        $this->suggestions = [];
                        Notification::make()->title(__('The line is unprocessed again'))->success()->send();
                    }),
                Action::make('ignore')
                    ->label(__('Ignore'))
                    ->size('sm')
                    ->color('gray')
                    ->tooltip(__('Ignore this line (e.g. a transfer between accounts)'))
                    ->visible(fn (BankTransaction $record) => $record->status === BankTransactionStatus::Pending)
                    ->disabled(fn () => CurrentAccount::readOnly())
                    ->action(function (BankTransaction $record): void {
                        app(ApplyBankTransaction::class)->ignore($record);
                        Notification::make()->title(__('Line ignored'))->send();
                    }),
            ])
            // As in the mock: one button for every line whose suggestion is sure, no selection needed.
            ->toolbarActions([
                Action::make('acceptSuggestions')
                    ->label(fn (): string => trans_choice('{1} Accept the right suggestion|[2,*] Accept the :count right suggestions', $this->sureSuggestionCount()))
                    ->icon(Phosphor::Check)
                    ->color('gray')
                    ->size('sm')
                    ->visible(fn (): bool => $this->activeTab === 'pending' && $this->sureSuggestionCount() > 0)
                    ->disabled(fn () => CurrentAccount::readOnly())
                    ->action(fn () => $this->acceptSureSuggestions(BankTransaction::pending()->get())),
            ])
            ->emptyStateHeading(fn () => $this->activeTab === 'pending' ? __('Nothing to process') : __('No lines'))
            ->emptyStateDescription(fn () => $this->activeTab === 'pending' ? __('Upload a CSV or Excel bank statement to get started.') : null)
            ->emptyStateIcon(Phosphor::Bank);
    }

    protected function uploadAction(): Action
    {
        return Action::make('upload')
            ->label(__('Upload statement'))
            ->icon(Phosphor::UploadSimple)
            // Importing and reconciling change the books: not in a read-only account.
            ->disabled(fn () => CurrentAccount::readOnly())
            ->modalHeading(__('Upload bank statement'))
            ->modalDescription(__('Export transactions from your bank’s website as CSV or Excel. Previously uploaded lines are skipped.'))
            ->modalSubmitActionLabel(__('Read statement'))
            ->schema([
                FileUpload::make('file')
                    ->label(__('Statement file'))
                    ->disk('local')
                    ->directory(fn (): string => $this->uploadDirectory())
                    // A path in the field's state comes from the browser: never one it names itself.
                    ->preventFilePathTampering()
                    ->acceptedFileTypes([
                        'text/csv',
                        'text/plain',
                        'application/csv',
                        'application/vnd.ms-excel',
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ])
                    ->required(),
            ])
            ->action(function (array $data): void {
                $path = is_array($data['file']) ? reset($data['file']) : $data['file'];
                $result = $this->readStatement($path);

                if ($result->needsMapping()) {
                    $this->mappingFile = $path;
                    $this->mappingHeaders = $result->headers;
                    $this->replaceMountedAction('mapColumns');

                    return;
                }

                $this->storeRows($result->rows, $path);
            });
    }

    /** Opened by the upload action when the file's columns aren't recognised (not shown in the header). */
    public function mapColumnsAction(): Action
    {
        $columnSelect = fn (string $name, string $label, bool $required = false) => Select::make($name)
            ->label($label)
            ->options(fn () => collect($this->mappingHeaders)->mapWithKeys(fn (string $header, int $index) => [
                $index => $header === ''
                    ? __('Column :number', ['number' => $index + 1])
                    : __('Column :number: :header', ['number' => $index + 1, 'header' => $header]),
            ])->all())
            ->required($required);

        return Action::make('mapColumns')
            ->label(__('Map columns'))
            ->modalHeading(__('The file columns could not be identified'))
            ->modalDescription(__('Choose the column for each field. Use “Amount” for a single signed column, or “Debit” and “Credit” if they are separate.'))
            ->modalSubmitActionLabel(__('Read statement'))
            ->schema([
                $columnSelect('date', __('Date'), required: true),
                $columnSelect('description', __('Description'), required: true),
                $columnSelect('amount', __('Amount (signed)')),
                $columnSelect('debit', __('Debit')),
                $columnSelect('credit', __('Credit')),
                $columnSelect('balance', __('Balance')),
            ])
            ->action(function (array $data, Action $action): void {
                $mapping = array_map('intval', array_filter($data, fn ($value) => $value !== null && $value !== ''));

                if (! isset($mapping['amount']) && ! isset($mapping['debit'], $mapping['credit'])) {
                    Notification::make()->title(__('Choose the amount column or the debit and credit columns.'))->danger()->send();
                    $action->halt();
                }

                $path = $this->mappingFile ?? abort(404);
                $result = $this->readStatement($path, $mapping);
                $this->storeRows($result->rows, $path);
                $this->mappingFile = null;
                $this->mappingHeaders = [];
            });
    }

    /**
     * Excel exports are recognised by their content (a zip or OLE file), not the name, so a
     * renamed file still reads.
     *
     * @param  array<string, int>|null  $mapping
     */
    private function readStatement(string $path, ?array $mapping = null): BankCsvResult
    {
        $this->ensureOwnUpload($path);

        $contents = (string) Storage::disk('local')->get($path);
        $isSpreadsheet = str_starts_with($contents, "PK\x03\x04") || str_starts_with($contents, "\xD0\xCF\x11\xE0");

        return $isSpreadsheet
            ? BankCsvParser::parseSpreadsheet($contents, $mapping)
            : BankCsvParser::parse($contents, $mapping);
    }

    /** Uploads go to a folder of their own per account, on the disk all accounts share. */
    private function uploadDirectory(): string
    {
        return 'bank-statements/'.CurrentAccount::id();
    }

    /**
     * The upload's path comes back from the browser (the file field's state), and this page reads
     * and deletes it: only a file in this account's upload folder, never a path climbing out of it.
     */
    private function ensureOwnUpload(string $path): void
    {
        $inFolder = str_starts_with($path, $this->uploadDirectory().'/')
            && ! str_contains($path, '..')
            && ! str_contains($path, '\\');

        abort_unless($inFolder, 403);
    }

    /** @param array<int, array{booked_on: string, description: string, amount: int, balance: ?int}> $rows */
    private function storeRows(array $rows, string $path): void
    {
        $this->ensureOwnUpload($path);

        Storage::disk('local')->delete($path);

        if ($rows === []) {
            Notification::make()->title(__('No transactions found in the file'))->warning()->send();

            return;
        }

        ['created' => $created, 'skipped' => $skipped] = app(ImportBankStatement::class)->store($rows);

        Notification::make()
            ->title(trans_choice('{1} :count new statement line|[0,*] :count new statement lines', $created))
            ->body($skipped > 0 ? trans_choice('{1} :count line had already been uploaded.|[2,*] :count lines had already been uploaded.', $skipped) : null)
            ->success()
            ->send();

        $this->activeTab = 'pending';
        $this->resetTable();
    }

    /** @var array{done: list<array<string, string>>, left: list<array<string, string>>}|null */
    private ?array $autoReconcilePreview = null;

    /** What "Reconciliar automaticamente" would do: the real run, rolled back. */
    public function autoReconcilePlan(): array
    {
        if ($this->autoReconcilePreview !== null) {
            return $this->autoReconcilePreview;
        }

        DB::beginTransaction();

        try {
            return $this->autoReconcilePreview = $this->runAutoReconcile();
        } finally {
            DB::rollBack();
        }
    }

    public function autoReconcile(): void
    {
        $plan = DB::transaction(fn () => $this->runAutoReconcile());
        $done = count($plan['done']);
        $left = count($plan['left']);

        $this->autoReconcilePreview = null;
        $this->suggestions = [];
        $this->resetTable();

        Notification::make()
            ->title(trans_choice('{1} :count line processed|[0,*] :count lines processed', $done))
            ->body($left > 0
                ? trans_choice('{1} :count line was left unprocessed because there is no reliable suggestion.|[2,*] :count lines were left unprocessed because there are no reliable suggestions.', $left)
                : __('All statement lines are processed.'))
            ->success()
            ->send();
    }

    /**
     * Every pending line with an unambiguous suggestion, oldest first. Suggestions are worked out
     * again after each line, since what one line takes is no longer free for the next.
     *
     * @return array{done: list<array<string, string>>, left: list<array<string, string>>}
     */
    private function runAutoReconcile(): array
    {
        $matcher = app(MatchBankTransactions::class);
        $apply = app(ApplyBankTransaction::class);
        $plan = ['done' => [], 'left' => []];

        foreach (BankTransaction::pending()->orderBy('booked_on')->orderBy('id')->get() as $line) {
            $row = ['date' => $line->booked_on->format(Language::resolve(app()->getLocale())->dateFormat()), 'description' => $line->description, 'amount' => Money::format($line->amount, locale: app()->getLocale())];
            $best = $matcher->for($line)->best();

            if ($best === null) {
                $plan['left'][] = $row;

                continue;
            }

            try {
                $label = $best->label();
                $apply->accept($line, $best);
                $plan['done'][] = [...$row, 'id' => $line->getKey(), 'target' => $label, 'own' => $best->type === 'own_transfer'];
            } catch (DomainException) {
                $plan['left'][] = $row;
            }
        }

        // What each transfer covers, worked out with every line of this run already treated.
        $coverage = new TransferCoverage;

        foreach ($plan['done'] as $index => $row) {
            if ($row['own']) {
                $plan['done'][$index]['covers'] = array_map(
                    fn (TransferCover $cover) => $cover->label(),
                    $coverage->for(BankTransaction::findOrFail($row['id'])),
                );
            }
        }

        return $plan;
    }

    /**
     * For a pending line: "match" when there is one clear suggestion, "choose" when there are
     * several possibilities, "none" when nothing fits. Null for lines already treated.
     */
    private function suggestionTone(BankTransaction $record): ?string
    {
        if ($record->status !== BankTransactionStatus::Pending) {
            return null;
        }

        $suggestion = $this->suggestionFor($record);

        return match (true) {
            $suggestion->best() !== null => 'match',
            $suggestion->candidates->isNotEmpty() => 'choose',
            default => 'none',
        };
    }

    private function suggestionFor(BankTransaction $record): BankSuggestion
    {
        return $this->suggestions[$record->getKey()] ??= app(MatchBankTransactions::class)->for($record);
    }

    /**
     * The search box finds a line by its description or by its amount ("61,50", "400"), with
     * or without the sign.
     */
    private function searchLines(Builder $query, string $search): Builder
    {
        $amount = preg_match('/\A[+−-]?\s*\d{1,9}(?:[.,]\d{1,2})?\s*€?\z/u', trim($search)) === 1
            ? (int) round((float) str_replace(',', '.', preg_replace('/[^0-9.,]/', '', $search)) * 100)
            : null;

        return $query->where(fn (Builder $query) => $query
            ->where('description', 'like', "%{$search}%")
            ->when($amount !== null, fn (Builder $query) => $query->orWhereIn('amount', [$amount, -$amount])));
    }

    /** How many unprocessed lines have one clear suggestion, for "Aceitar as N sugestões certas". */
    private function sureSuggestionCount(): int
    {
        return BankTransaction::pending()->get()
            ->filter(fn (BankTransaction $record): bool => $this->suggestionFor($record)->best() !== null)
            ->count();
    }

    /**
     * Accepts the clear suggestion of each line; lines without one, or whose suggestion no
     * longer applies, are left unprocessed and counted in the notification.
     *
     * @param  iterable<BankTransaction>  $records
     */
    private function acceptSureSuggestions(iterable $records): void
    {
        $done = 0;
        $skipped = 0;

        foreach ($records as $record) {
            $best = $record->status === BankTransactionStatus::Pending ? $this->suggestionFor($record)->best() : null;

            if ($best === null) {
                $skipped++;

                continue;
            }

            try {
                app(ApplyBankTransaction::class)->accept($record, $best);
                $done++;
                $this->forget($record);
            } catch (DomainException) {
                $skipped++;
            }
        }

        Notification::make()
            ->title(trans_choice('{1} :count line processed|[0,*] :count lines processed', $done))
            ->body($skipped > 0 ? trans_choice('{1} :count line without a reliable suggestion was left unprocessed.|[2,*] :count lines without a reliable suggestion were left unprocessed.', $skipped) : null)
            ->success()
            ->send();
    }

    private function forget(BankTransaction $record): void
    {
        $this->suggestions = [];
    }

    /** @return string|array<int, string> */
    private ?TransferCoverage $transferCoverage = null;

    /** "Cobre 2 recebimentos e 1 despesa" for the column; the list is in "Ver detalhes". */
    private function coverSummary(array $covers): string
    {
        $payments = count(array_filter($covers, fn (TransferCover $cover) => $cover->record instanceof Payment));
        $expenses = count($covers) - $payments;

        $parts = array_filter([
            $payments === 0 ? null : trans_choice('{1} :count payment|[2,*] :count payments', $payments),
            $expenses === 0 ? null : trans_choice('{1} :count expense|[2,*] :count expenses', $expenses),
        ]);

        return match (count($parts)) {
            0 => __('Does not cover any unreconciled records'),
            1 => __('Covers :records', ['records' => reset($parts)]),
            default => __('Covers :payments and :expenses', ['payments' => $parts[0], 'expenses' => $parts[1]]),
        };
    }

    /**
     * What "Ver detalhes" shows: every record the line stands for (or a transfer covers), with
     * how much of it and in what situation.
     *
     * @return array<string, mixed>
     */
    private function lineDetails(BankTransaction $line): array
    {
        $payouts = PlatformPayout::query()->where('bank_transaction_id', $line->id)->latest('id')->get();
        $row = function (Payment|Expense $record, int $covered, int $total, string $situation, string $color): array {
            return [
                'type' => $record instanceof Payment ? __('Payment received') : __('Expense'),
                'reference' => $record instanceof Payment ? $record->receivableLabels() : (string) $record->description,
                'party' => $record instanceof Payment ? $record->client?->name : $record->supplier?->name,
                'date' => ($record instanceof Payment ? $record->paid_on : ($record->paid_on ?? $record->spent_on))?->format(Language::resolve(app()->getLocale())->dateFormat()) ?? '—',
                'covered' => Money::format($covered, locale: app()->getLocale()),
                'total' => $covered < $total ? Money::format($total, locale: app()->getLocale()) : null,
                'situation' => $situation,
                'color' => $color,
            ];
        };

        if ($line->status === BankTransactionStatus::OwnTransfer) {
            $covers = (new TransferCoverage)->for($line);
            $rows = array_map(fn (TransferCover $cover) => $row(
                $cover->record,
                $cover->amount,
                $cover->total,
                match (true) {
                    $cover->record instanceof Expense => $cover->deducted ? __('Deducted') : __('Refunded'),
                    $cover->amount < $cover->total => __('Partly covered'),
                    default => __('Complete'),
                },
                $cover->record instanceof Expense ? 'gray' : ($cover->amount < $cover->total ? 'warning' : 'success'),
            ), $covers);

            $used = (int) collect($covers)->sum(fn (TransferCover $cover) => $cover->record instanceof Payment === $line->is_credit ? $cover->amount : -$cover->amount);
            $leftover = $line->absolute_amount - $used;

            return [
                'line' => $line,
                'kind' => __('Transfer between your accounts'),
                'rows' => $rows,
                'leftover' => $leftover > 0 ? __('The remaining :amount does not cover any unreconciled records.', [
                    'amount' => Money::format($leftover, locale: app()->getLocale()),
                ]) : null,
                'difference' => null,
                'platformPayouts' => $payouts,
            ];
        }

        $rows = $line->links()->with('linkable')->get()->pluck('linkable')->filter()
            ->map(fn (Payment|Expense $record) => $row($record, $record instanceof Payment ? $record->amount : $record->total, $record instanceof Payment ? $record->amount : $record->total, __('Complete'), 'success'))
            ->values()->all();
        if ($refund = $line->customerRefund) {
            $rows[] = [
                'type' => __('Refund'), 'reference' => $refund->creditNote->number,
                'party' => $refund->creditNote->party_name, 'date' => $refund->paid_on->format(Language::resolve(app()->getLocale())->dateFormat()),
                'covered' => Money::format($refund->amount, locale: app()->getLocale()), 'total' => null, 'situation' => __('Complete'), 'color' => 'success',
            ];
        }
        $difference = $line->difference();

        return [
            'line' => $line,
            'kind' => $payouts->contains(fn (PlatformPayout $payout): bool => $payout->undone_at === null && $payout->one_off_income_id !== null)
                ? __('Platform payout')
                : ($line->customerRefund !== null ? __('Refund') : $line->status->getLabel()),
            'rows' => $rows,
            'leftover' => null,
            'difference' => match (true) {
                $line->status !== BankTransactionStatus::Matched => null,
                $difference !== 0 => __('A difference of :amount between the line and the records appears in the "Business account" card.', [
                    'amount' => Money::format(abs($difference), locale: app()->getLocale()),
                ]),
                $payouts->contains(fn (PlatformPayout $payout): bool => $payout->undone_at === null) => __('Difference: :amount', [
                    'amount' => Money::format(0, locale: app()->getLocale()),
                ]),
                default => null,
            },
            'platformPayouts' => $payouts,
        ];
    }

    private function describe(BankTransaction $record): string|array
    {
        if ($record->status !== BankTransactionStatus::Pending) {
            if ($record->status === BankTransactionStatus::OwnTransfer) {
                return [$record->status->getLabel(), $this->coverSummary(($this->transferCoverage ??= new TransferCoverage)->for($record))];
            }

            if ($record->status !== BankTransactionStatus::Matched) {
                return $record->status->getLabel();
            }

            $records = $record->links->pluck('linkable')->filter()
                ->map(fn (Payment|Expense $linked) => $linked instanceof Payment
                    ? __('Payment received · :reference · :amount', [
                        'reference' => $linked->is_split ? $linked->receivableLabels() : ($linked->receivable?->label ?? ''),
                        'amount' => Money::format($linked->amount, locale: app()->getLocale()),
                    ])
                    : __('Expense · :supplier · :description · :amount', [
                        'supplier' => $linked->supplier?->name ?? '',
                        'description' => $linked->description ?? '',
                        'amount' => Money::format($linked->total, locale: app()->getLocale()),
                    ]))
                ->values()->all();
            if ($refund = $record->customerRefund) {
                $records[] = __('Refund · :reference · :amount', [
                    'reference' => $refund->creditNote->number,
                    'amount' => Money::format($refund->amount, locale: app()->getLocale()),
                ]);
            }
            $difference = $record->difference();

            if ($difference === 0) {
                return $records;
            }

            return [...$records, __('Difference of :amount · see Business account', [
                'amount' => Money::format(abs($difference), locale: app()->getLocale()),
            ])];
        }

        $suggestion = $this->suggestionFor($record);
        $best = $suggestion->best();

        return match (true) {
            $best !== null => $best->label(),
            $suggestion->candidates->isNotEmpty() => trans_choice('{1} :count option — choose|[2,*] :count options — choose', $suggestion->candidates->count()),
            default => __('No suggestion'),
        };
    }

    private function linkSchema(BankTransaction $record): array
    {
        $targets = null;
        $choices = function () use ($record, &$targets): array {
            return $targets ??= $this->targetChoices($record);
        };

        return [
            Select::make('targets')
                ->label(__('What this line represents'))
                ->helperText(__('You can select several records, such as two invoices paid in one transfer.'))
                ->multiple()
                ->options(fn (): array => $choices()['options'])
                ->dynamicOptions(false)
                ->searchable()
                ->required(),
            ViewField::make('explanation')
                ->label(__('Record the difference as'))
                ->helperText(__('If the difference did not go through this account, leave this blank. It appears in the Business account card.'))
                ->rule(Rule::enum(DifferenceExplanation::class))
                ->view('filament.actions.contiu-bank-match-preview')
                ->viewData(fn (): array => [
                    'amount' => (string) $record->amount,
                    'targets' => $choices()['amounts'],
                    'locale' => Language::resolve(app()->getLocale())->value,
                    'statementAmount' => Money::format($record->amount),
                    'explanations' => [
                        'more' => DifferenceExplanation::optionsFor($record->is_credit, true)[0] ?? null,
                        'less' => DifferenceExplanation::optionsFor($record->is_credit, false)[0] ?? null,
                    ],
                ]),
        ];
    }

    /** @param Builder<Project>|Builder<Retainer> $query */
    private function directCostTarget(string $name, string $label, Builder $query): Select
    {
        return Select::make($name)
            ->label($label)
            ->searchable()
            ->getSearchResultsUsing(fn (string $search): array => (clone $query)
                ->whereLike('code', '%'.$search.'%')->limit(50)->pluck('code', 'id')->all())
            ->getOptionLabelUsing(fn ($value): ?string => (clone $query)->whereKey($value)->value('code'))
            ->dehydrated(fn (Get $get): bool => in_array($get('kind'), [ExpenseKind::Direct, ExpenseKind::Direct->value], true))
            ->visibleJs("\$get('kind') === 'direct'");
    }

    /** @return array{options: array<string, array<string, string>>, amounts: array<string, string|null>} */
    private function targetChoices(BankTransaction $record): array
    {
        $amounts = [];
        $choice = function (string $type, Payment|Expense|Receivable|RecurringExpense|Supplier $model, string $label) use (&$amounts): array {
            $key = $type.':'.$model->getKey();
            $amounts[$key] ??= match (true) {
                $model instanceof Payment => (string) $model->amount,
                $model instanceof Expense => (string) $model->total,
                $model instanceof Receivable => (string) $model->projectedOutstanding(),
                default => null,
            };

            return [$key => $label];
        };

        $suggested = $this->suggestionFor($record)->candidates
            ->reject(fn (BankCandidate $candidate) => $candidate->type === 'own_transfer')
            ->mapWithKeys(fn (BankCandidate $candidate): array => $choice($candidate->type, $candidate->model, $candidate->label()))
            ->all();

        $registeredExpenses = $this->unlinkedNear(Expense::query()->where('is_paid', true)->with('supplier'), 'paid_on', $record)
            ->mapWithKeys(fn (Expense $expense): array => $choice('paid', $expense, ($expense->paid_on ?? $expense->spent_on)->format(Language::resolve(app()->getLocale())->dateFormat()).' · '.$expense->supplier->name.' · '.$expense->description.' — '.Money::format($expense->total, locale: app()->getLocale())))
            ->all();

        if ($record->is_credit) {
            $others = Receivable::query()->open()->excludingCancelled()->withOutstandingBalance()->with('billable.client')->orderBy('due_on')->get()
                ->mapWithKeys(fn (Receivable $receivable): array => $choice('receivable', $receivable, __(':reference (:client) — :amount outstanding', [
                    'reference' => $receivable->label,
                    'client' => $receivable->client?->name ?? '—',
                    'amount' => Money::format($receivable->projectedOutstanding(), locale: app()->getLocale()),
                ])))
                ->all();

            $registered = $this->unlinkedNear(Payment::query()->with('receivable.billable.client'), 'paid_on', $record)
                ->mapWithKeys(fn (Payment $payment): array => $choice('payment', $payment, $payment->paid_on->format(Language::resolve(app()->getLocale())->dateFormat()).' · '.$payment->receivableLabels().' ('.($payment->client?->name ?? '—').') — '.Money::format($payment->amount, locale: app()->getLocale())))
                ->all();

            // A platform payout is the income minus its commission, so expenses can join a credit.
            $options = [
                __('Suggested') => $suggested,
                __('All outstanding') => array_diff_key($others, $suggested),
                __('Previously recorded payments received') => array_diff_key($registered, $suggested),
                __('Previously recorded expenses') => array_diff_key($registeredExpenses, $suggested),
            ];
        } else {
            $unpaid = Expense::query()->where('is_paid', false)->with('supplier')->orderBy('spent_on')->get()
                ->mapWithKeys(fn (Expense $expense): array => $choice('expense', $expense, $expense->supplier->name.' · '.$expense->description.' — '.Money::format($expense->total, locale: app()->getLocale())))
                ->all();
            $templates = RecurringExpense::query()->where('is_active', true)->with('supplier')->get()
                ->mapWithKeys(fn (RecurringExpense $template): array => $choice('template', $template, __(':supplier · :description (recurring)', [
                    'supplier' => $template->supplier->name,
                    'description' => $template->description ?? '',
                ])))
                ->all();

            $options = [
                __('Suggested') => $suggested,
                __('Unpaid expenses') => array_diff_key($unpaid, $suggested),
                __('New from recurring expenses') => array_diff_key($templates, $suggested),
                __('Previously recorded expenses') => array_diff_key($registeredExpenses, $suggested),
            ];
        }

        return ['options' => array_filter($options), 'amounts' => $amounts];
    }

    /** Days around the bank date to list registered records in "Associar…" (wider than the suggestions). */
    private const REGISTERED_LIST_DAYS = 60;

    /**
     * Registered payments or paid expenses not yet linked to a bank line, closest date first.
     *
     * @template TModel of Payment|Expense
     *
     * @param  Builder<TModel>  $query
     * @return Collection<int, TModel>
     */
    private function unlinkedNear(Builder $query, string $dateColumn, BankTransaction $record): Collection
    {
        return $query
            ->doesntHave('bankLink')
            ->whereBetween($dateColumn, [
                $record->booked_on->copy()->subDays(self::REGISTERED_LIST_DAYS)->startOfDay()->toDateTimeString(),
                $record->booked_on->copy()->addDays(self::REGISTERED_LIST_DAYS)->endOfDay()->toDateTimeString(),
            ])
            ->get()
            ->sortBy(fn (Payment|Expense $model) => abs($record->booked_on->diffInDays($model->{$dateColumn})))
            ->take(30)
            ->values();
    }

    private function undoDescription(BankTransaction $record): string
    {
        $created = $record->links()->where('created_from_line', true)->with('linkable')->get()->pluck('linkable')->filter()
            ->map(fn (Payment|Expense $linked) => $linked instanceof Payment
                ? __('the payment of :amount', ['amount' => Money::format($linked->amount, locale: app()->getLocale())])
                : __('the expense :description', ['description' => $linked->description ?? '']));

        return $created->isEmpty()
            ? __('The line becomes unprocessed. Existing records are kept.')
            : __('The line returns to "Unprocessed" and the records created from it are deleted: :records.', [
                'records' => $created->implode(', '),
            ]);
    }

    private function apply(callable $callback, ?Action $action = null): void
    {
        try {
            $callback(app(ApplyBankTransaction::class));
            $this->suggestions = [];

            Notification::make()->title(__('Bank statement line processed'))->success()->send();
        } catch (DomainException $exception) {
            Notification::make()->title(__('Unable to process this line'))->body($exception->getMessage())->danger()->send();
            $action?->halt();
        }
    }

    public function getTitle(): string
    {
        return __('Bank statement');
    }

    public static function getNavigationLabel(): string
    {
        return __('Bank statement');
    }

    public static function getNavigationGroup(): string
    {
        return __('Bank');
    }
}
