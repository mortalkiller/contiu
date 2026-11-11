<?php

namespace App\Filament\Resources\Payments\Schemas;

use App\Filament\Pages\BankStatementImport;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentPresenter;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Projects\ProjectResource;
use App\Filament\Resources\Retainers\RetainerResource;
use App\Filament\Support\DetailPage;
use App\Filament\Support\ReconciliationColumns;
use App\Models\FiscalDocument;
use App\Models\OneOffIncome;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Project;
use App\Models\Receivable;
use App\Models\Retainer;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\TextSize;
use Illuminate\Database\Eloquent\Builder;

/**
 * A payment received on its own page, as in the mock: the payment as it reads (when, how, how
 * much and what it pays) and, beside it, who paid, the fiscal document issued for it, the bank
 * statement line that explains it, how it counts for taxes and what happened to it.
 *
 * The page is built for the payment it shows: an advance still without a charge says so instead
 * of listing what it pays, a split one lists every receivable. Everything read here comes with
 * the payment (ViewPayment) or from one query per card.
 */
class PaymentInfolist
{
    /** How many entries of a payment's history its page shows; the slide-over has them all. */
    private const int HISTORY_LENGTH = 8;

    /** The paper and the cards beside it, about 70/30 as in the mock. */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->defaultDateDisplayFormat(fn (): string => DetailPage::dateFormat())
            ->components(fn (Payment $record): array => [
                Grid::make(['default' => 1, 'xl' => 7])->schema([
                    Group::make([static::paper($record)])->columnSpan(['default' => 1, 'xl' => 5]),
                    Group::make(static::aside($record))->columnSpan(['default' => 1, 'xl' => 2]),
                ]),
            ]);
    }

    private static function paper(Payment $record): Section
    {
        return Section::make()->schema(array_values(array_filter([
            Grid::make(['default' => 2, 'md' => 4])->schema(static::details($record))->extraAttributes(['class' => 'fap-caps-labels fap-rule-bottom']),
            static::allocations($record),
            static::totals($record),
            blank($record->notes) ? null : TextEntry::make('notes')->hiddenLabel()->color('gray')
                ->state(preg_split('/\R/u', (string) $record->notes) ?: [])->listWithLineBreaks()
                ->extraEntryWrapperAttributes(['class' => 'fap-rule-top']),
        ])));
    }

    /**
     * The row of small cells: when it came in, how, how much and the invoice or receipt number
     * typed with it.
     *
     * @return list<TextEntry>
     */
    private static function details(Payment $record): array
    {
        return [
            DetailPage::cell('paid_on', __('Received on'), DetailPage::date($record->paid_on)),
            DetailPage::cell('method', __('How it was paid'), $record->method?->getLabel()),
            DetailPage::cell('amount', __('Amount'), Money::format((int) $record->amount))->weight('semibold'),
            DetailPage::cell('invoice_number', __('Invoice / receipt no.'), $record->invoice_number),
        ];
    }

    /**
     * "O que este recebimento paga": one row per receivable the payment was allocated to, with
     * when it was due, how much it is, what this payment paid of it and what is still owed today.
     * An advance without a charge yet has nothing to list and says what to do about it.
     */
    private static function allocations(Payment $record): Section
    {
        $end = fn (string $label): TableColumn => TableColumn::make($label)->alignment(Alignment::End);

        return Section::make(__('What this payment pays'))->contained(false)->schema([
            $record->allocations->isEmpty()
                ? DetailPage::help('unallocated', $record->isAdvance()
                    ? __('This advance is not allocated to a charge yet. Allocate it when you know what it pays.')
                    : __('This payment is not allocated to any receivable.'))
                : RepeatableEntry::make('allocations')
                    ->hiddenLabel()
                    ->extraAttributes(['class' => 'fap-plain-table'])
                    ->table([TableColumn::make(__('Receivable')), $end(__('Was due on')), $end(__('Amount')), $end(__('Paid by this payment')), $end(__('Left to receive'))])
                    ->schema([
                        TextEntry::make('receivable_title')->weight('medium')
                            ->state(fn (PaymentAllocation $record): string => static::receivableTitle($record->receivable))
                            ->url(fn (PaymentAllocation $record): ?string => static::receivableUrl($record->receivable))
                            ->helperText(fn (PaymentAllocation $record): ?string => static::receivableOrigin($record->receivable)),
                        TextEntry::make('receivable_due_on')->alignEnd()->placeholder('—')
                            ->state(fn (PaymentAllocation $record): ?string => $record->receivable?->due_on === null ? null : DetailPage::date($record->receivable->due_on)),
                        TextEntry::make('receivable_amount')->alignEnd()->placeholder('—')
                            ->state(fn (PaymentAllocation $record): ?string => $record->receivable === null ? null : Money::format((int) $record->receivable->amount)),
                        TextEntry::make('allocated_amount')->alignEnd()->weight('medium')
                            ->state(fn (PaymentAllocation $record): string => Money::format((int) $record->amount)),
                        TextEntry::make('receivable_outstanding')->alignEnd()->placeholder('—')
                            ->state(fn (PaymentAllocation $record): ?string => $record->receivable === null ? null : Money::format($record->receivable->projectedOutstanding())),
                    ]),
        ]);
    }

    /** "Loja online · sinal de 50%": the job, retainer or one-off income, then what the receivable is. */
    private static function receivableTitle(?Receivable $receivable): string
    {
        $billable = $receivable?->billable;
        $title = $billable instanceof Project || $billable instanceof Retainer || $billable instanceof OneOffIncome ? $billable->title : null;

        return collect([$title, $receivable?->description])->filter()->unique()->implode(' · ') ?: __('Receivable');
    }

    /** "Trabalho DEMO-05 · Estúdio Brisa": where the receivable comes from and whose it is. */
    private static function receivableOrigin(?Receivable $receivable): ?string
    {
        $billable = $receivable?->billable;

        return implode(' · ', array_filter([
            match (true) {
                $billable instanceof Project => __('Job :code', ['code' => $billable->code]),
                $billable instanceof Retainer => trim(__('Retainer').' '.$billable->code),
                $billable instanceof OneOffIncome => __('One-off income'),
                default => null,
            },
            $billable?->client?->name,
        ])) ?: null;
    }

    /** The page of the job or retainer the receivable belongs to, for whoever may open it. */
    private static function receivableUrl(?Receivable $receivable): ?string
    {
        $billable = $receivable?->billable;

        return match (true) {
            $billable instanceof Project => ProjectResource::canView($billable),
            $billable instanceof Retainer => RetainerResource::canView($billable),
            default => false,
        } ? $receivable->billableUrl() : null;
    }

    /** What the payment adds up to, under the amounts column as on paper. */
    private static function totals(Payment $record): Grid
    {
        return Grid::make(['default' => 1, 'md' => 2])->schema([
            Group::make([]),
            Section::make()
                ->inlineLabel()
                ->extraAttributes(['class' => 'fap-key-value'])
                ->contained(false)
                ->schema([
                    TextEntry::make('total_received')->label(__('Total received'))->state(Money::format((int) $record->amount))
                        ->weight('bold')->size(TextSize::Medium)
                        ->extraEntryWrapperAttributes(['class' => 'fap-key-value-total']),
                ]),
        ]);
    }

    /** @return list<Section> */
    private static function aside(Payment $record): array
    {
        return array_values(array_filter([
            DetailPage::rowsCard(__('Client'), 'client', static::clientRows($record)),
            static::documentsCard($record),
            static::bankCard($record),
            static::taxesCard($record),
            DetailPage::rowsCard(__('History'), 'history', static::historyRows($record)),
        ]));
    }

    /**
     * Who paid, with its tax number and town, and what it paid this year (one query for the count
     * and the sum).
     *
     * @return list<array<string, mixed>>
     */
    private static function clientRows(Payment $record): array
    {
        $client = $record->client;

        if ($client === null) {
            return [];
        }

        $client->loadMissing('structuredAddress');
        $year = today()->year;
        $received = $client->paymentsQuery()->whereYear('paid_on', $year)
            ->selectRaw('count(*) as payments, coalesce(sum(amount), 0) as total')->toBase()->first();

        return [
            [
                'title' => $client->name,
                'meta' => implode(' · ', array_filter([
                    filled($client->vat_number) ? __('Tax ID :number', ['number' => $client->vat_number]) : null,
                    $client->structuredAddress?->locality,
                ])) ?: null,
                'url' => ClientResource::canView($client) ? ClientResource::getUrl('view', ['record' => $client]) : null,
            ],
            [
                'title' => __('Received in :period', ['period' => $year]),
                'meta' => trans_choice('{0} No payments|{1} 1 payment|[2,*] :count payments', (int) $received->payments),
                'amount' => Money::format((int) $received->total),
            ],
        ];
    }

    /**
     * "Documento fiscal": the documents tied to this payment (a fatura-recibo, a receipt) and the
     * invoices of the receivables it pays, or that there is none yet.
     */
    private static function documentsCard(Payment $record): Section
    {
        $rows = static::documentRows($record);

        return Section::make(__('Fiscal document'))
            ->extraAttributes(['class' => 'fap-divided'])
            ->schema($rows === []
                ? [DetailPage::help('no_document', __('No fiscal document is tied to this payment.'))]
                : DetailPage::rows('document', $rows));
    }

    /**
     * The payment's own documents first, then the invoices of what it pays, in one query.
     *
     * @return list<array<string, mixed>>
     */
    private static function documentRows(Payment $record): array
    {
        if (! FiscalDocumentResource::canViewAny()) {
            return [];
        }

        $paymentId = $record->getKey();
        $receivables = $record->allocations->pluck('receivable_id')->all();

        return FiscalDocument::query()
            ->whereHas('links', fn (Builder $links) => $links->where(fn (Builder $target) => $target
                ->where('payment_id', $paymentId)
                ->orWhereIn('receivable_id', $receivables)))
            ->with(['links', 'integrationRecord'])
            ->orderBy('document_date')
            ->orderBy('id')
            ->get()
            ->sortByDesc(fn (FiscalDocument $document): bool => $document->links?->payment_id === $paymentId)
            ->values()
            ->map(fn (FiscalDocument $document): array => [
                'title' => FiscalDocumentPresenter::title($document),
                'meta' => $document->isDraft()
                    ? FiscalDocumentPresenter::kind($document)
                    : $document->type->getLabel().' · '.FiscalDocumentPresenter::agree($document, 'issued on :date', ['date' => DetailPage::date($document->document_date)]),
                'url' => match (true) {
                    ! FiscalDocumentResource::canView($document) => null,
                    $document->isDraft() && $document->integrationRecord !== null => FiscalDocumentResource::getUrl('draft', ['record' => $document]),
                    default => FiscalDocumentResource::getUrl('view', ['record' => $document]),
                },
                'amount' => Money::format(FiscalDocumentPresenter::signed($document, (int) $document->total_amount)),
                'badge' => FiscalDocumentPresenter::state($document),
            ])
            ->all();
    }

    /**
     * "Banco": whether the bank statement explains the payment and with which lines, as the list
     * and the edit form work it out (ReconciliationColumns). Only with a bank account for the
     * activity: without one there is no statement to compare with.
     */
    private static function bankCard(Payment $record): ?Section
    {
        if (! ReconciliationColumns::enabled()) {
            return null;
        }

        $result = ReconciliationColumns::for($record);
        $rows = array_map(fn (array $by): array => [
            'title' => $by['line']->description,
            'meta' => DetailPage::date($by['line']->booked_on).' · '.($by['direct'] ? __('Linked to this line') : __('Covered by the transfer')),
            'amount' => Money::format((int) $by['amount']),
        ], $result->lines);

        return Section::make(__('Bank'))
            ->extraAttributes(['class' => 'fap-divided'])
            ->afterHeader([Text::make($result->label())->badge()->color($result->status->color())])
            ->footerActions([
                Action::make('statement')->label(__('Open bank statement'))->link()
                    ->visible(fn (): bool => BankStatementImport::canAccess())
                    ->url(fn (): string => BankStatementImport::getUrl()),
            ])
            ->schema(array_values(array_filter([
                ...DetailPage::rows('bank', $rows),
                $result->detail() === null ? null : DetailPage::help('bank_detail', $result->detail()),
                $rows === [] && ! $result->isReconciled()
                    ? DetailPage::help('no_bank_line', __('No bank statement line covers this yet. It will be reconciled when the amount reaches the business account and you process the statement.'))
                    : null,
            ])));
    }

    /** "Impostos": whether the payment counts for the tax estimates, in the words of its form. */
    private static function taxesCard(Payment $record): Section
    {
        return Section::make(__('Taxes'))
            ->inlineLabel()
            ->extraAttributes(['class' => 'fap-key-value'])
            ->schema([
                TextEntry::make('tax_exempt')->label(__('Tax exempt'))->state($record->tax_exempt ? __('Yes') : __('No')),
                DetailPage::help('tax_exempt_help', $record->tax_exempt
                    ? __('Excluded from tax estimates (VAT, Social Security and personal income tax).')
                    : __('Counts for tax estimates (VAT, Social Security and personal income tax).')),
            ]);
    }

    /**
     * What was recorded about the payment, newest first, for whoever may see its history (the
     * same people as the "Histórico" action, which lists what changed each time).
     *
     * @return list<array<string, mixed>>
     */
    private static function historyRows(Payment $record): array
    {
        if (! auth()->user()?->can('audit', $record)) {
            return [];
        }

        return array_map(fn (array $entry): array => [
            'title' => match ($entry['name']) {
                'created' => __('Payment recorded'),
                'updated' => __('Payment changed'),
                default => $entry['event'],
            },
            'meta' => DetailPage::moment($entry['at']).' · '.$entry['who'],
        ], array_slice(PaymentResource::historyEntries($record), 0, self::HISTORY_LENGTH));
    }
}
