<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentPresenter;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\RecurringExpenses\RecurringExpenseResource;
use App\Filament\Resources\Retainers\RetainerResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Support\DetailPage;
use App\Filament\Support\ReconciliationColumns;
use App\Models\Expense;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLink;
use App\Models\Supplier;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\TextSize;
use Illuminate\Database\Eloquent\Collection;

/**
 * An expense on its own page, as in the mock: the record as it reads (when, what kind, how much
 * it cost, how it was paid, its supporting document) and, beside it, the supplier, what it is
 * tied to, the bank statement and its review.
 *
 * The page is built for the expense it shows. Its own relations come loaded with it
 * (ViewExpense); the three things that are not relations of an expense (the supplier's spend
 * this year, the fiscal documents tied to it, the bank reconciliation) are each read once.
 */
class ExpenseInfolist
{
    /** The record and the cards beside it, about 70/30 as in the mock. */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components(fn (Expense $record): array => [
                Grid::make(['default' => 1, 'xl' => 7])->schema([
                    Group::make([static::paper($record)])->columnSpan(['default' => 1, 'xl' => 5]),
                    Group::make(static::aside($record))->columnSpan(['default' => 1, 'xl' => 2]),
                ]),
            ]);
    }

    private static function paper(Expense $record): Section
    {
        return Section::make()->schema(array_values(array_filter([
            Grid::make(['default' => 2, 'md' => 4])->schema(static::details($record))->extraAttributes(['class' => 'fap-caps-labels fap-rule-bottom']),
            static::cost($record),
            static::payment($record),
            static::evidence($record),
            static::notes($record),
        ])));
    }

    /**
     * The row of small cells that opens the record: when, its category, overhead or direct, and
     * the supplier's invoice number.
     *
     * @return list<TextEntry>
     */
    private static function details(Expense $record): array
    {
        return [
            DetailPage::cell('spent_on', __('Date'), DetailPage::date($record->spent_on)),
            DetailPage::cell('category', __('Category'), $record->category?->name),
            DetailPage::cell('kind', __('Type'), $record->kind->getLabel()),
            DetailPage::cell('invoice_number', __('Invoice number'), $record->invoice_number)->fontFamily(filled($record->invoice_number) ? 'mono' : null),
        ];
    }

    /**
     * "Quanto custou": the one VAT rate of the expense with its amounts, then what they add up
     * to and how the expense is allocated. Every line is always there, with a dash when the
     * expense has nothing for it.
     */
    private static function cost(Expense $record): Section
    {
        $rate = static::rate($record);
        $end = fn (string $label): TableColumn => TableColumn::make($label)->alignment(Alignment::End);
        $row = fn (string $name, string $label, ?string $state): TextEntry => TextEntry::make($name)->label($label)->state($state)->placeholder('—');

        return Section::make(__('How much it cost'))->contained(false)->schema([
            RepeatableEntry::make('amounts')
                ->label(__('How much it cost'))
                ->hiddenLabel()
                ->extraAttributes(['class' => 'fap-plain-table'])
                ->state([[
                    'rate' => $rate,
                    'net' => Money::format($record->net_amount),
                    'vat' => Money::format($record->vat_amount),
                    'total' => Money::format($record->total),
                ]])
                ->table([TableColumn::make(__('VAT rate')), $end(__('Amount excluding VAT')), $end(__('VAT')), $end(__('Total'))])
                ->schema([
                    TextEntry::make('rate')->label(__('VAT rate'))->weight('medium'),
                    TextEntry::make('net')->label(__('Amount excluding VAT'))->alignEnd(),
                    TextEntry::make('vat')->label(__('VAT'))->alignEnd(),
                    TextEntry::make('total')->label(__('Total'))->alignEnd()->weight('medium'),
                ]),
            Grid::make(['default' => 1, 'md' => 2])->schema([
                Group::make([]),
                Section::make()
                    ->inlineLabel()
                    ->extraAttributes(['class' => 'fap-key-value'])
                    ->contained(false)
                    ->schema([
                        $row('net_amount', __('Amount excluding VAT'), Money::format($record->net_amount)),
                        $row('vat_amount', __('VAT at :rate%', ['rate' => rtrim($rate, '%')]), Money::format($record->vat_amount)),
                        $row('total', __('Expense total'), Money::format($record->total))->weight('bold')->size(TextSize::Medium)
                            ->extraEntryWrapperAttributes(['class' => 'fap-key-value-total']),
                        $row('attribution', __('Allocation'), $record->attribution?->getLabel()),
                    ]),
            ]),
        ]);
    }

    /** "23%": the VAT rate of the expense, as the form offers it. */
    private static function rate(Expense $record): string
    {
        return Money::vatOptions()[(string) $record->vat_rate] ?? ((int) round((float) $record->vat_rate * 100)).'%';
    }

    /** "Pagamento": whether it is paid, when and how. */
    private static function payment(Expense $record): Section
    {
        return Section::make(__('Payment'))->contained(false)->schema([
            Grid::make(['default' => 2, 'md' => 4])->schema([
                DetailPage::cell('payment_state', __('Status'), ExpenseHeader::paymentState($record)['label']),
                DetailPage::cell('paid_on', __('Paid on'), $record->paid_on === null ? null : DetailPage::date($record->paid_on)),
                DetailPage::cell('method', __('Method'), $record->method?->getLabel()),
            ])->extraAttributes(['class' => 'fap-caps-labels']),
        ]);
    }

    /**
     * "Comprovativo": the supporting PDF with the way to download it, or that there is none yet
     * with the way to the edit form, where it is attached.
     */
    private static function evidence(Expense $record): Section
    {
        $saved = $record->hasProof();
        $action = $saved
            ? Action::make('downloadEvidence')
                ->label(__('Download supporting document'))
                ->link()
                ->extraAttributes(['download' => true])
                ->visible(ExpenseResource::canView($record))
                ->url(ExpenseResource::getUrl('download-proof', ['record' => $record]))
            : Action::make('attachEvidence')
                ->label(__('Attach PDF'))
                ->link()
                ->visible(ExpenseResource::canEdit($record))
                ->url(ExpenseResource::getUrl('edit', ['record' => $record]));

        return Section::make(__('Supporting document'))->contained(false)->schema([
            Section::make()->compact()->schema([
                Flex::make([
                    Group::make(array_values(array_filter([
                        TextEntry::make('evidence')->label(__('Supporting document'))->hiddenLabel()
                            ->state($saved ? __('Supporting PDF') : __('No PDF yet.'))
                            ->weight($saved ? 'medium' : null)
                            ->color($saved ? null : 'gray'),
                        $saved && filled($record->proof_reference)
                            ? TextEntry::make('evidence_reference')->label(__('Supporting document reference'))->hiddenLabel()->state($record->proof_reference)->color('gray')
                            : null,
                    ])))->gap(false),
                    Actions::make([$action])->grow(false),
                ]),
            ]),
        ]);
    }

    /** The foot of the record: the notes written on the expense. */
    private static function notes(Expense $record): ?TextEntry
    {
        $lines = array_values(array_filter(preg_split('/\R/u', (string) $record->notes) ?: [], filled(...)));

        return $lines === [] ? null : TextEntry::make('notes')->label(__('Notes'))->hiddenLabel()->color('gray')->state($lines)->listWithLineBreaks()
            ->extraEntryWrapperAttributes(['class' => 'fap-rule-top']);
    }

    /** @return list<Section> */
    private static function aside(Expense $record): array
    {
        return array_values(array_filter([
            DetailPage::rowsCard(__('Supplier'), 'supplier', static::supplierRows($record)),
            DetailPage::rowsCard(__('Linked to'), 'linked', static::linkRows($record)),
            static::bankCard($record),
            static::reviewCard($record),
        ]));
    }

    /**
     * Who was paid, with its tax number and where it is established, and what this year's
     * expenses with it add up to.
     *
     * @return list<array<string, mixed>>
     */
    private static function supplierRows(Expense $record): array
    {
        $supplier = $record->supplier;

        if ($supplier === null) {
            return [];
        }

        $spent = static::spentThisYear($supplier);

        return [
            [
                'title' => $supplier->name,
                'meta' => implode(' · ', array_filter([
                    filled($supplier->vat_number) ? __('Tax ID :number', ['number' => $supplier->vat_number]) : null,
                    $supplier->seat?->getLabel(),
                ])),
                'url' => SupplierResource::canView($supplier) ? SupplierResource::getUrl('view', ['record' => $supplier]) : null,
            ],
            [
                'title' => __('Spent in :year', ['year' => today()->year]),
                'meta' => trans_choice('{0} No expenses|{1} 1 expense|[2,*] :count expenses', $spent['count']),
                'amount' => Money::format($spent['total']),
            ],
        ];
    }

    /**
     * How many expenses the supplier has this year and their total, in one query. The total of
     * an expense is its amount plus its VAT rounded per document, so it is added up here, as the
     * supplier's own page does.
     *
     * @return array{count: int, total: int}
     */
    private static function spentThisYear(Supplier $supplier): array
    {
        $expenses = Expense::query()
            ->where('supplier_id', $supplier->getKey())
            ->whereYear('spent_on', today()->year)
            ->get(['id', 'net_amount', 'vat_rate']);

        return [
            'count' => $expenses->count(),
            'total' => (int) $expenses->sum(fn (Expense $expense): int => (int) $expense->total),
        ];
    }

    /**
     * What the expense belongs to: the job or retainer of a direct cost, the recurring expense
     * that generated it and the fiscal documents recorded for it.
     *
     * @return list<array<string, mixed>>
     */
    private static function linkRows(Expense $record): array
    {
        $project = $record->project;
        $retainer = $record->retainer;
        $recurring = $record->recurringExpense;

        return array_values(array_filter([
            $project === null ? null : [
                'title' => $project->title,
                'meta' => implode(' · ', array_filter([__('Job :code', ['code' => $project->code]), $project->client?->name])),
                'url' => ProjectResource::canView($project) ? ProjectResource::getUrl('view', ['record' => $project]) : null,
            ],
            $retainer === null ? null : [
                'title' => $retainer->title,
                'meta' => implode(' · ', array_filter([__('Retainer'), $retainer->client?->name])),
                'url' => RetainerResource::canView($retainer) ? RetainerResource::getUrl('view', ['record' => $retainer]) : null,
            ],
            $recurring === null ? null : [
                'title' => $recurring->description,
                'meta' => __('Recurring expense · generated on :date', ['date' => DetailPage::date($record->created_at ?? $record->spent_on)]),
                'url' => RecurringExpenseResource::hasPage('view') && RecurringExpenseResource::canView($recurring)
                    ? RecurringExpenseResource::getUrl('view', ['record' => $recurring])
                    : null,
            ],
            ...static::fiscalDocuments($record)->map(fn (FiscalDocument $document): array => [
                'title' => FiscalDocumentPresenter::title($document),
                'meta' => implode(' · ', array_filter([FiscalDocumentPresenter::kind($document), $document->origin])),
                'url' => FiscalDocumentResource::canView($document) ? FiscalDocumentResource::getUrl('view', ['record' => $document]) : null,
                'amount' => Money::format(FiscalDocumentPresenter::signed($document, $document->total_amount)),
                'badge' => FiscalDocumentPresenter::state($document),
            ])->all(),
        ]));
    }

    /**
     * The fiscal documents recorded for this expense (the supplier's invoice), oldest first, in one query.
     *
     * @return Collection<int, FiscalDocument>
     */
    private static function fiscalDocuments(Expense $record): Collection
    {
        return FiscalDocument::query()
            ->whereIn('id', FiscalDocumentLink::query()->where('expense_id', $record->getKey())->select('fiscal_document_id'))
            ->orderBy('document_date')
            ->orderBy('id')
            ->get();
    }

    /**
     * "Banco": the statement lines that explain a paid expense, or that there is none. Only with
     * a bank account for the activity, as the list's column. The answer is the one the list and
     * the edit form give (ReconciliationColumns).
     */
    private static function bankCard(Expense $record): ?Section
    {
        if (! ReconciliationColumns::enabled()) {
            return null;
        }

        if (! $record->is_paid) {
            return Section::make(__('Bank'))
                ->afterHeader([Text::make(__('No transaction'))->badge()->color('gray')])
                ->schema([DetailPage::help('bank_unpaid', __('Unpaid: there is no statement line to link yet.'))]);
        }

        $result = ReconciliationColumns::for($record);
        $rows = array_map(fn (array $covering): array => [
            'title' => (string) $covering['line']->description,
            'meta' => implode(' · ', [
                DetailPage::date($covering['line']->booked_on),
                match (true) {
                    $covering['direct'] => __('Linked to this line'),
                    $covering['line']->is_credit => __('Deducted from the transfer'),
                    default => __('Refunded by the transfer'),
                },
            ]),
            'amount' => Money::format($covering['amount']),
        ], $result->lines);

        return Section::make(__('Bank'))
            ->afterHeader([Text::make($result->label())->badge()->color($result->status->color())])
            ->extraAttributes($rows === [] ? [] : ['class' => 'fap-divided'])
            ->schema(array_values(array_filter([
                ...DetailPage::rows('bank', $rows),
                $result->detail() === null ? null : DetailPage::help('bank_detail', $result->detail()),
                $rows === [] && ! $result->isReconciled()
                    ? DetailPage::help('bank_none', __('No bank statement line covers this yet. It will be reconciled when the amount reaches the business account and you process the statement.'))
                    : null,
            ])));
    }

    /** "Revisão": whether the supporting document is there, its reference and whether the expense was reviewed. */
    private static function reviewCard(Expense $record): Section
    {
        $saved = $record->hasProof();

        return Section::make(__('Expense review'))
            ->inlineLabel()
            ->extraAttributes(['class' => 'fap-key-value'])
            ->schema([
                DetailPage::cell('review_evidence', __('Supporting document'), $saved ? __('PDF saved') : __('Missing'))->color($saved ? null : 'warning'),
                DetailPage::cell('proof_reference', __('Supporting document reference'), $record->proof_reference),
                DetailPage::cell('is_reviewed', __('Reviewed'), $record->is_reviewed ? __('Yes') : __('Not yet')),
            ]);
    }
}
