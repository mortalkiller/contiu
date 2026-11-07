<?php

namespace App\Filament\Pages;

use App\Actions\ApplyBankTransaction;
use App\Actions\ImportBankStatement;
use App\Actions\MatchBankTransactions;
use App\Enums\BankTransactionStatus;
use App\Enums\DifferenceExplanation;
use App\Enums\ExpenseKind;
use App\Enums\PaymentMethod;
use App\Filament\Actions\OneOffIncomeAction;
use App\Models\BankTransaction;
use App\Models\Category;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\RecurringExpense;
use App\Models\Retainer;
use App\Models\Supplier;
use App\Support\BankCandidate;
use App\Support\BankCsvParser;
use App\Support\BankCsvResult;
use App\Support\BankSuggestion;
use App\Support\FinanceSettings;
use App\Support\Money;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Resources\Concerns\HasTabs;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use ToneGabes\Filament\Icons\Enums\Phosphor;
use UnitEnum;

/**
 * "Extrato bancário": upload the bank's export (CSV or Excel), then go line by line — accept the suggestion, pick
 * another match, turn a debit into an expense, or ignore it.
 */
class BankStatementImport extends Page implements HasTable
{
    use HasTabs;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Phosphor::Bank;

    protected static ?string $navigationLabel = 'Extrato bancário';

    protected static ?string $title = 'Extrato bancário';

    protected static ?string $slug = 'extrato-bancario';

    protected static string|UnitEnum|null $navigationGroup = 'Receitas';

    protected static ?int $navigationSort = 5;

    /** Stored upload waiting for a manual column mapping. */
    public ?string $mappingFile = null;

    /** @var array<int, string> */
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
            ? 'Carrega o extrato do banco (CSV ou Excel): as entradas são associadas aos valores a receber e as saídas às despesas.'
            : $pending.' '.($pending === 1 ? 'linha por tratar' : 'linhas por tratar').'.';
    }

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('Por tratar')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', BankTransactionStatus::Pending))
                ->badge(fn () => BankTransaction::pending()->count() ?: null)
                ->badgeColor('warning'),
            'done' => Tab::make('Tratados')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', [BankTransactionStatus::Matched, BankTransactionStatus::OwnTransfer])),
            'ignored' => Tab::make('Ignorados')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', BankTransactionStatus::Ignored)),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getTabsContentComponent(),
            EmbeddedTable::make(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('help')
                ->label('Como funciona?')
                ->icon(Phosphor::Question)
                ->color('gray')
                ->link()
                ->url(HelpCentre::getUrl().'#extrato'),
            $this->uploadAction(),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => $this->modifyQueryWithActiveTab(BankTransaction::query()->with('links.linkable')))
            ->defaultSort('booked_on', 'desc')
            ->columns([
                TextColumn::make('booked_on')->label('Data')->date()->sortable(),
                TextColumn::make('description')->label('Descrição')->wrap()->searchable(),
                TextColumn::make('amount')
                    ->label('Montante')
                    ->money('EUR')
                    ->alignEnd()
                    ->weight('medium')
                    ->color(fn (BankTransaction $record) => $record->is_credit ? 'success' : 'danger'),
                TextColumn::make('suggestion')
                    ->label(fn () => $this->activeTab === 'pending' ? 'Sugestão' : 'Tratado como')
                    ->state(fn (BankTransaction $record) => $this->describe($record))
                    ->listWithLineBreaks()
                    ->color(fn (BankTransaction $record) => $record->status === BankTransactionStatus::Pending && $this->suggestionFor($record)->best() === null ? 'gray' : null)
                    ->wrap(),
                TextColumn::make('status')->label('Estado')->badge()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('accept')
                    ->label('Aceitar')
                    ->icon(Phosphor::Check)
                    ->color('success')
                    ->button()
                    ->visible(fn (BankTransaction $record) => $record->status === BankTransactionStatus::Pending && $this->suggestionFor($record)->best() !== null)
                    ->action(fn (BankTransaction $record) => $this->apply(fn (ApplyBankTransaction $apply) => $apply->accept($record, $this->suggestionFor($record)->best()))),
                Action::make('link')
                    ->label('Associar…')
                    ->icon(Phosphor::Link)
                    ->color('gray')
                    ->visible(fn (BankTransaction $record) => $record->status === BankTransactionStatus::Pending)
                    ->modalHeading(fn (BankTransaction $record) => 'Associar '.Money::format((float) $record->amount).' · '.$record->booked_on->format('d/m/Y'))
                    ->modalDescription(fn (BankTransaction $record) => $record->description)
                    ->modalSubmitActionLabel('Associar')
                    ->schema(fn (BankTransaction $record) => [
                        Select::make('targets')
                            ->label('O que é esta linha')
                            ->helperText('Podes escolher vários: por exemplo, duas faturas pagas numa só transferência.')
                            ->multiple()
                            ->options(fn () => $this->targetOptions($record))
                            ->searchable()
                            ->live()
                            ->required(),
                        Callout::make('O valor é diferente')
                            ->key('amountDifference')
                            ->description(fn (Get $get): string => 'O extrato tem '.Money::format((float) $record->amount).'; os registos escolhidos dão uma diferença de '.Money::format(abs((float) $this->plannedDifference($record, $get('targets')))).'. Confirma que é o mesmo movimento e diz o que é a diferença.')
                            ->status('warning')
                            ->icon(Phosphor::Warning)
                            ->visible(fn (Get $get): bool => abs((float) $this->plannedDifference($record, $get('targets'))) >= 0.01),
                        Radio::make('explanation')
                            ->label('Como explicas a diferença?')
                            ->options(fn (Get $get): array => $this->explanationOptions($record, $get('targets')))
                            ->visible(fn (Get $get): bool => abs((float) $this->plannedDifference($record, $get('targets'))) >= 0.01)
                            ->required(fn (Get $get): bool => abs((float) $this->plannedDifference($record, $get('targets'))) >= 0.01),
                    ])
                    ->action(fn (BankTransaction $record, array $data) => $this->apply(fn (ApplyBankTransaction $apply) => $apply->applyTargets(
                        $record,
                        $data['targets'],
                        DifferenceExplanation::tryFrom((string) ($data['explanation'] ?? '')),
                    ))),
                Action::make('createIncome')
                    ->label('Criar receita')
                    ->icon(Phosphor::Gift)
                    ->color('gray')
                    ->visible(fn (BankTransaction $record) => $record->status === BankTransactionStatus::Pending && $record->is_credit)
                    ->modalHeading(fn (BankTransaction $record) => 'Receita avulsa de '.Money::format($record->absolute_amount))
                    ->modalDescription(fn (BankTransaction $record) => $record->booked_on->format('d/m/Y').' · '.$record->description.'. Indica o valor bruto (o da fatura-recibo) e a comissão que a plataforma ficou.')
                    ->modalSubmitActionLabel('Criar')
                    ->fillForm(fn (BankTransaction $record) => [
                        'gross_amount' => $record->absolute_amount,
                        'commission' => 0,
                        'method' => PaymentMethod::Transfer->value,
                        'tax_exempt' => app(FinanceSettings::class)->paymentsExemptByDefault(),
                    ])
                    ->schema(fn (BankTransaction $record) => OneOffIncomeAction::formSchema(withDate: false, received: $record->absolute_amount))
                    ->action(fn (BankTransaction $record, array $data) => $this->apply(fn (ApplyBankTransaction $apply) => $apply->createOneOffIncome($record, $data))),
                Action::make('createExpense')
                    ->label('Criar despesa')
                    ->icon(Phosphor::Receipt)
                    ->color('gray')
                    ->visible(fn (BankTransaction $record) => $record->status === BankTransactionStatus::Pending && ! $record->is_credit)
                    ->modalHeading(fn (BankTransaction $record) => 'Nova despesa de '.Money::format($record->absolute_amount))
                    ->modalDescription(fn (BankTransaction $record) => $record->booked_on->format('d/m/Y').' · '.$record->description)
                    ->fillForm(fn (BankTransaction $record) => [
                        'supplier_id' => $this->suggestionFor($record)->candidates->firstWhere('type', 'supplier')?->model->getKey(),
                        'kind' => ExpenseKind::Overhead->value,
                        'vat_rate' => '0.0000',
                    ])
                    ->schema([
                        Select::make('supplier_id')
                            ->label('Fornecedor')
                            ->options(fn () => Supplier::query()->orderBy('name')->pluck('name', 'id')->all())
                            ->searchable()
                            ->required()
                            ->createOptionForm([TextInput::make('name')->label('Nome')->required()])
                            ->createOptionUsing(fn (array $data) => Supplier::create($data)->getKey()),
                        Select::make('category_id')
                            ->label('Categoria')
                            ->options(fn () => Category::query()->expense()->pluck('name', 'id')->all()),
                        TextInput::make('description')->label('Descrição')->placeholder('Ex.: ChatGPT Pro'),
                        Radio::make('kind')
                            ->label('Tipo de custo')
                            ->options(ExpenseKind::class)
                            ->live()
                            ->required(),
                        Select::make('project_id')
                            ->label('Trabalho')
                            ->options(fn () => Project::query()->orderByDesc('quoted_on')->pluck('code', 'id')->all())
                            ->searchable()
                            ->visible(fn (Get $get) => $get('kind') === ExpenseKind::Direct->value || $get('kind') === ExpenseKind::Direct),
                        Select::make('retainer_id')
                            ->label('Avença')
                            ->options(fn () => Retainer::query()->orderBy('code')->pluck('code', 'id')->all())
                            ->visible(fn (Get $get) => $get('kind') === ExpenseKind::Direct->value || $get('kind') === ExpenseKind::Direct),
                        ToggleButtons::make('vat_rate')
                            ->label('IVA incluído no valor')
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
                    ->label('Transferência minha')
                    ->icon(Phosphor::ArrowsLeftRight)
                    ->color('gray')
                    ->iconButton()
                    ->tooltip('Transferência minha: dinheiro entre as tuas contas, não é receita nem despesa')
                    ->visible(fn (BankTransaction $record) => $record->status === BankTransactionStatus::Pending)
                    ->action(fn (BankTransaction $record) => $this->apply(fn (ApplyBankTransaction $apply) => $apply->markOwnTransfer($record))),
                Action::make('undo')
                    ->label('Voltar a tratar')
                    ->icon(Phosphor::ArrowCounterClockwise)
                    ->color('gray')
                    ->visible(fn (BankTransaction $record) => $record->status !== BankTransactionStatus::Pending)
                    ->requiresConfirmation()
                    ->modalHeading('Voltar a tratar esta linha?')
                    ->modalDescription(fn (BankTransaction $record) => $this->undoDescription($record))
                    ->modalSubmitActionLabel('Voltar a tratar')
                    ->action(function (BankTransaction $record): void {
                        app(ApplyBankTransaction::class)->undo($record);
                        $this->suggestions = [];
                        Notification::make()->title('A linha voltou a "Por tratar"')->success()->send();
                    }),
                Action::make('ignore')
                    ->label('Ignorar')
                    ->icon(Phosphor::EyeSlash)
                    ->color('gray')
                    ->iconButton()
                    ->tooltip('Ignorar esta linha (ex.: transferência entre contas)')
                    ->visible(fn (BankTransaction $record) => $record->status === BankTransactionStatus::Pending)
                    ->action(function (BankTransaction $record): void {
                        app(ApplyBankTransaction::class)->ignore($record);
                        Notification::make()->title('Linha ignorada')->send();
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('acceptSuggestions')
                    ->label('Aceitar sugestões')
                    ->icon(Phosphor::Checks)
                    ->color('success')
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records): void {
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
                            ->title($done.' '.($done === 1 ? 'linha tratada' : 'linhas tratadas'))
                            ->body($skipped > 0 ? $skipped.' sem sugestão segura ficaram por tratar.' : null)
                            ->success()
                            ->send();
                    }),
            ])
            ->emptyStateHeading(fn () => $this->activeTab === 'pending' ? 'Nada por tratar' : 'Sem linhas')
            ->emptyStateDescription(fn () => $this->activeTab === 'pending' ? 'Carrega o extrato do banco em CSV ou Excel para começar.' : null)
            ->emptyStateIcon(Phosphor::Bank);
    }

    protected function uploadAction(): Action
    {
        return Action::make('upload')
            ->label('Carregar extrato')
            ->icon(Phosphor::UploadSimple)
            ->modalHeading('Carregar extrato bancário')
            ->modalDescription('Exporta os movimentos no site do banco em CSV ou Excel. Linhas que já foram carregadas são ignoradas.')
            ->modalSubmitActionLabel('Ler extrato')
            ->schema([
                FileUpload::make('file')
                    ->label('Ficheiro do extrato')
                    ->disk('local')
                    ->directory('bank-statements')
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
            ->options(fn () => collect($this->mappingHeaders)->mapWithKeys(fn (string $header, int $index) => [$index => 'Coluna '.($index + 1).($header !== '' ? ': '.$header : '')])->all())
            ->required($required);

        return Action::make('mapColumns')
            ->label('Indicar colunas')
            ->modalHeading('Não reconheci as colunas do ficheiro')
            ->modalDescription('Diz onde está cada informação. Usa "Montante" se houver uma coluna com sinal, ou "Débito" e "Crédito" se forem separadas.')
            ->modalSubmitActionLabel('Ler extrato')
            ->schema([
                $columnSelect('date', 'Data', required: true),
                $columnSelect('description', 'Descrição', required: true),
                $columnSelect('amount', 'Montante (com sinal)'),
                $columnSelect('debit', 'Débito'),
                $columnSelect('credit', 'Crédito'),
                $columnSelect('balance', 'Saldo'),
            ])
            ->action(function (array $data, Action $action): void {
                $mapping = array_map('intval', array_filter($data, fn ($value) => $value !== null && $value !== ''));

                if (! isset($mapping['amount']) && ! isset($mapping['debit'], $mapping['credit'])) {
                    Notification::make()->title('Indica a coluna do montante, ou as de débito e crédito.')->danger()->send();
                    $action->halt();
                }

                $result = $this->readStatement($this->mappingFile, $mapping);
                $this->storeRows($result->rows, $this->mappingFile);
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
        $contents = (string) Storage::disk('local')->get($path);
        $isSpreadsheet = str_starts_with($contents, "PK\x03\x04") || str_starts_with($contents, "\xD0\xCF\x11\xE0");

        return $isSpreadsheet
            ? BankCsvParser::parseSpreadsheet($contents, $mapping)
            : BankCsvParser::parse($contents, $mapping);
    }

    /** @param array<int, array{booked_on: string, description: string, amount: float, balance: ?float}> $rows */
    private function storeRows(array $rows, string $path): void
    {
        Storage::disk('local')->delete($path);

        if ($rows === []) {
            Notification::make()->title('Não encontrei movimentos no ficheiro')->warning()->send();

            return;
        }

        ['created' => $created, 'skipped' => $skipped] = app(ImportBankStatement::class)->store($rows);

        Notification::make()
            ->title($created.' '.($created === 1 ? 'linha nova' : 'linhas novas').' do extrato')
            ->body($skipped > 0 ? $skipped.' já tinham sido carregadas.' : null)
            ->success()
            ->send();

        $this->activeTab = 'pending';
        $this->resetTable();
    }

    private function suggestionFor(BankTransaction $record): BankSuggestion
    {
        return $this->suggestions[$record->getKey()] ??= app(MatchBankTransactions::class)->for($record);
    }

    private function forget(BankTransaction $record): void
    {
        $this->suggestions = [];
    }

    /** @return string|array<int, string> */
    private function describe(BankTransaction $record): string|array
    {
        if ($record->status !== BankTransactionStatus::Pending) {
            if ($record->status !== BankTransactionStatus::Matched) {
                return $record->status->getLabel();
            }

            $records = $record->links->pluck('linkable')->filter()
                ->map(fn (Payment|Expense $linked) => $linked instanceof Payment
                    ? 'Recebimento · '.$linked->receivable?->label.' · '.Money::format((float) $linked->amount)
                    : 'Despesa · '.$linked->supplier?->name.' · '.$linked->description.' · '.Money::format($linked->total))
                ->values()->all();
            $difference = $record->difference();

            if (abs($difference) < 0.01) {
                return $records;
            }

            return [...$records, 'Diferença de '.Money::format(abs($difference)).($record->difference_explanation ? ' · '.$record->difference_explanation->labelFor($record->is_credit) : ' · por explicar')];
        }

        $suggestion = $this->suggestionFor($record);
        $best = $suggestion->best();

        return match (true) {
            $best !== null => $best->label(),
            $suggestion->candidates->isNotEmpty() => $suggestion->candidates->count().' possibilidades — escolhe',
            default => 'Sem sugestão',
        };
    }

    /** @return array<string, string>|array<string, array<string, string>> */
    private function targetOptions(BankTransaction $record): array
    {
        $suggested = $this->suggestionFor($record)->candidates
            ->reject(fn (BankCandidate $candidate) => $candidate->type === 'own_transfer')
            ->mapWithKeys(fn (BankCandidate $candidate) => [$candidate->key() => $candidate->label()])
            ->all();

        if ($record->is_credit) {
            $others = Receivable::query()->open()->excludingCancelled()->with(['payments', 'billable.client'])->orderBy('due_on')->get()
                ->mapWithKeys(fn (Receivable $receivable) => ['receivable:'.$receivable->getKey() => $receivable->label.' ('.($receivable->client?->name ?? '—').') — falta '.Money::format($receivable->outstanding)])
                ->all();

            $registered = $this->unlinkedNear(Payment::query()->with('receivable.billable.client'), 'paid_on', $record)
                ->mapWithKeys(fn (Payment $payment) => ['payment:'.$payment->getKey() => $payment->paid_on->format('d/m/Y').' · '.$payment->receivable?->label.' ('.($payment->receivable?->client?->name ?? '—').') — '.Money::format((float) $payment->amount)])
                ->all();

            // A platform payout is the income minus its commission, so expenses can join a credit.
            $expenses = $this->unlinkedNear(Expense::query()->where('is_paid', true)->with('supplier'), 'paid_on', $record)
                ->mapWithKeys(fn (Expense $expense) => ['paid:'.$expense->getKey() => ($expense->paid_on ?? $expense->spent_on)->format('d/m/Y').' · '.$expense->supplier->name.' · '.$expense->description.' — '.Money::format($expense->total)])
                ->all();

            return array_filter([
                'Sugeridos' => $suggested,
                'Todos em aberto' => array_diff_key($others, $suggested),
                'Recebimentos já registados' => array_diff_key($registered, $suggested),
                'Despesas já registadas' => array_diff_key($expenses, $suggested),
            ]);
        }

        $unpaid = Expense::query()->where('is_paid', false)->with('supplier')->orderBy('spent_on')->get()
            ->mapWithKeys(fn (Expense $expense) => ['expense:'.$expense->getKey() => $expense->supplier->name.' · '.$expense->description.' — '.Money::format($expense->total)])
            ->all();
        $templates = RecurringExpense::query()->where('is_active', true)->with('supplier')->get()
            ->mapWithKeys(fn (RecurringExpense $template) => ['template:'.$template->getKey() => $template->supplier->name.' · '.$template->description.' (recorrente)'])
            ->all();

        $registered = $this->unlinkedNear(Expense::query()->where('is_paid', true)->with('supplier'), 'paid_on', $record)
            ->mapWithKeys(fn (Expense $expense) => ['paid:'.$expense->getKey() => ($expense->paid_on ?? $expense->spent_on)->format('d/m/Y').' · '.$expense->supplier->name.' · '.$expense->description.' — '.Money::format($expense->total)])
            ->all();

        return array_filter([
            'Sugeridos' => $suggested,
            'Despesas por pagar' => array_diff_key($unpaid, $suggested),
            'Novas a partir de recorrentes' => array_diff_key($templates, $suggested),
            'Despesas já registadas' => array_diff_key($registered, $suggested),
        ]);
    }

    /** Days around the bank date to list registered records in "Associar…" (wider than the suggestions). */
    private const REGISTERED_LIST_DAYS = 60;

    /**
     * Registered payments or paid expenses not yet linked to a bank line, closest date first.
     *
     * @template TModel of Payment|Expense
     *
     * @param  Builder<TModel>  $query
     * @return \Illuminate\Support\Collection<int, TModel>
     */
    private function unlinkedNear(Builder $query, string $dateColumn, BankTransaction $record): \Illuminate\Support\Collection
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

    /** @param array<int, string>|null $targets */
    private function plannedDifference(BankTransaction $record, ?array $targets): float
    {
        if (blank($targets)) {
            return 0.0;
        }

        try {
            return app(ApplyBankTransaction::class)->plannedDifference($record, $targets);
        } catch (DomainException) {
            return 0.0;
        }
    }

    /**
     * @param  array<int, string>|null  $targets
     * @return array<string, string>
     */
    private function explanationOptions(BankTransaction $record, ?array $targets): array
    {
        $difference = $this->plannedDifference($record, $targets);
        $lineExceeds = ($difference > 0) === $record->is_credit;

        return collect(DifferenceExplanation::optionsFor($record->is_credit, $lineExceeds))
            ->mapWithKeys(fn (DifferenceExplanation $option) => [$option->value => $option->labelFor($record->is_credit)])
            ->all();
    }

    private function undoDescription(BankTransaction $record): string
    {
        $created = $record->links()->where('created_from_line', true)->with('linkable')->get()->pluck('linkable')->filter()
            ->map(fn (Payment|Expense $linked) => $linked instanceof Payment ? 'o recebimento de '.Money::format((float) $linked->amount) : 'a despesa '.$linked->description);

        return $created->isEmpty()
            ? 'A linha volta a "Por tratar". Os registos que já existiam ficam como estão.'
            : 'A linha volta a "Por tratar" e é apagado o que foi criado a partir dela: '.$created->implode(', ').'.';
    }

    private function apply(callable $callback): void
    {
        try {
            $callback(app(ApplyBankTransaction::class));
            $this->suggestions = [];

            Notification::make()->title('Linha do extrato tratada')->success()->send();
        } catch (DomainException $exception) {
            Notification::make()->title('Não foi possível tratar esta linha')->body($exception->getMessage())->danger()->send();
        }
    }
}
