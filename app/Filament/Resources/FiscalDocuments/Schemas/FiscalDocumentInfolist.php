<?php

namespace App\Filament\Resources\FiscalDocuments\Schemas;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Enums\IntegrationOperationStatus;
use App\Enums\IntegrationRecordState;
use App\Enums\PaymentMethod;
use App\Filament\Resources\Clients\ClientResource;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentPresenter;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Support\DetailPage;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLine;
use App\Models\FiscalDocumentLink;
use App\Models\IntegrationOperation;
use App\Models\Project;
use App\Models\Retainer;
use App\Support\CurrentAccount;
use App\Support\FiscalTerms;
use App\Support\Invoicing\Invoicing;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\TextSize;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;

/**
 * A fiscal document on its own page, as in the mocks: the document as it reads on paper (who,
 * when, the lines and what they add up to) and, beside it, how it was issued, the invoice a note
 * changes, what it is tied to, the documents around it and what happened to it.
 *
 * The page is built for the document it shows, so each kind only gets what it has: a receipt
 * lists the invoice it pays instead of lines, a transport guide says where the goods go, a record
 * without lines says so. Everything read here comes with the document (ViewFiscalDocument).
 */
class FiscalDocumentInfolist
{
    /** The notice of a cancelled document, then the paper and the cards beside it, about 70/30 as in the mock. */
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->defaultDateDisplayFormat(fn (): string => DetailPage::dateFormat())
            ->components(fn (FiscalDocument $record): array => [
                ...static::notices($record),
                Grid::make(['default' => 1, 'xl' => 7])->schema([
                    Group::make([static::paper($record)])->columnSpan(['default' => 1, 'xl' => 5]),
                    Group::make(static::aside($record))->columnSpan(['default' => 1, 'xl' => 2]),
                ]),
            ]);
    }

    /** @return list<Component> */
    private static function notices(FiscalDocument $record): array
    {
        if ($record->status !== FiscalDocumentStatus::Voided) {
            return [];
        }

        return [
            Callout::make(__('This document was voided.'))
                ->description(__('It does not count as billed and brings no tax. Its number stays taken.'))
                ->danger(),
        ];
    }

    private static function paper(FiscalDocument $record): Section
    {
        return Section::make()->schema(array_values(array_filter([
            Grid::make(2)->schema(static::parties($record))->extraAttributes(['class' => 'fap-caps-labels']),
            Grid::make(['default' => 2, 'md' => 4])->schema(static::details($record))->extraAttributes(['class' => 'fap-caps-labels fap-rule-top fap-rule-bottom']),
            static::transport($record),
            ...static::body($record),
            static::totals($record),
            static::payment($record),
            static::footnotes($record),
        ])));
    }

    /**
     * "De" and "Para": who issued the document and who it is for, each with its tax number and,
     * for a client, its address.
     *
     * @return list<TextEntry>
     */
    private static function parties(FiscalDocument $record): array
    {
        $account = CurrentAccount::get();
        $own = ['name' => $account?->name, 'lines' => [filled($account?->nif) ? __('Tax ID :number', ['number' => $account->nif]) : null], 'url' => null];
        $taxId = filled($record->party_tax_id) ? __('Tax ID :number', ['number' => $record->party_tax_id]) : null;

        if ($record->direction === FiscalDocumentDirection::Received) {
            $supplier = $record->links?->expense?->supplier;
            $other = [
                'name' => $record->party_name,
                'lines' => [$taxId, $supplier?->seat],
                'url' => $supplier !== null && SupplierResource::canView($supplier) ? SupplierResource::getUrl('view', ['record' => $supplier]) : null,
            ];

            return [static::party('issuer', __('From'), $other), static::party('recipient', __('To'), $own)];
        }

        $client = $record->client;
        $other = [
            'name' => $record->party_name ?? __('Final consumer'),
            'lines' => blank($record->party_name) && $taxId === null
                ? [__('No tax ID or address')]
                : [$taxId, ...($client === null ? [] : static::address($client))],
            'url' => $client !== null && ClientResource::canView($client) ? ClientResource::getUrl('view', ['record' => $client]) : null,
        ];

        return [static::party('issuer', __('From'), $own), static::party('recipient', __('To'), $other)];
    }

    /** @param array{name: ?string, lines: list<?string>, url: ?string} $party */
    private static function party(string $name, string $label, array $party): TextEntry
    {
        $lines = array_filter($party['lines'], filled(...));

        return TextEntry::make($name)
            ->label($label)
            ->state($party['name'])
            ->placeholder('—')
            ->weight('semibold')
            ->url($party['url'])
            ->helperText($lines === [] ? null : new HtmlString(implode('<br>', array_map(e(...), $lines))));
    }

    /**
     * The client's address in at most two lines: the street, then the postcode and the town.
     *
     * @return list<string>
     */
    private static function address(Client $client): array
    {
        $address = $client->structuredAddress;

        if ($address === null) {
            return array_values(array_filter(preg_split('/\R/u', (string) $client->address) ?: [], filled(...)));
        }

        return array_values(array_filter([$address->street, trim("{$address->postal_code} {$address->locality}")], filled(...)));
    }

    /**
     * The row of small cells under the parties: the date, then what each kind has to say about
     * itself (when it is due, which invoice it changes and why, how it was paid, when the goods leave).
     *
     * @return list<TextEntry>
     */
    private static function details(FiscalDocument $record): array
    {
        $type = $record->type;
        $original = $type->requiresOriginal() ? $record->links?->relatedFiscalDocument : null;
        $isNote = in_array($type, [FiscalDocumentType::CreditNote, FiscalDocumentType::DebitNote], true);
        $category = $record->direction === FiscalDocumentDirection::Received ? $record->links?->expense?->category?->name : null;
        $startsAt = $type === FiscalDocumentType::TransportGuide ? ($record->transport['starts_at'] ?? null) : null;
        $plate = $type === FiscalDocumentType::TransportGuide ? ($record->transport['plate'] ?? null) : null;
        $reference = $isNote || $type === FiscalDocumentType::TransportGuide || $record->direction === FiscalDocumentDirection::Received
            ? (filled($record->client_reference) ? DetailPage::cell('client_reference', __('Client reference'), $record->client_reference) : null)
            : DetailPage::cell('client_reference', __('Client reference'), $record->client_reference);

        return array_values(array_filter([
            DetailPage::cell('document_date', __('Date'), DetailPage::date($record->document_date)),
            match (true) {
                $type->hasTerm() => DetailPage::cell('due_date', match ($type) {
                    FiscalDocumentType::Quote => __('Valid until'),
                    FiscalDocumentType::ProForma => __('Pay by'),
                    default => __('Due date'),
                }, $record->due_date === null ? null : DetailPage::date($record->due_date)),
                $type->settlesOnIssue() => DetailPage::cell('due_date', __('Due date'), __('Paid on issue')),
                default => null,
            },
            $original === null ? null : DetailPage::cell(
                'original',
                $type === FiscalDocumentType::CreditNote ? __('Corrects') : ($type === FiscalDocumentType::DebitNote ? __('Adds to') : __('Invoice')),
                __(':number of :date', ['number' => FiscalDocumentPresenter::title($original), 'date' => DetailPage::date($original->document_date)]),
            )->url(FiscalDocumentResource::getUrl('view', ['record' => $original])),
            $isNote ? DetailPage::cell('reason', __('Reason'), $record->reason) : null,
            $isNote && filled($record->receivable_effect) ? DetailPage::cell('receivable_effect', __('Effect'), static::effect($record->receivable_effect)) : null,
            $type === FiscalDocumentType::Receipt ? DetailPage::cell('payment_method', __('How it was paid'), static::paymentMethod($record)) : null,
            $type === FiscalDocumentType::Receipt && filled($record->payment['account'] ?? null) ? DetailPage::cell('payment_account', __('Went into'), $record->payment['account']) : null,
            blank($startsAt) ? null : DetailPage::cell('transport_starts_at', __('Transport starts'), DetailPage::moment(CarbonImmutable::parse($startsAt))),
            blank($plate) ? null : DetailPage::cell('transport_plate', __('Number plate'), $plate)->fontFamily('mono'),
            $category === null ? null : DetailPage::cell('category', __('Category'), $category),
            $reference,
        ]));
    }

    /** What a note does to what the client owes, as chosen when it was written. */
    private static function effect(string $effect): string
    {
        return match ($effect) {
            'apply' => __('Takes from what is left to receive'),
            'refund' => __('Refund to the client'),
            'add' => __('Adds to what is left to receive'),
            'separate' => __('Charged separately'),
            default => $effect,
        };
    }

    private static function paymentMethod(FiscalDocument $record): ?string
    {
        return PaymentMethod::tryFrom((string) ($record->payment['method'] ?? ''))?->getLabel();
    }

    /** Where the goods are loaded and unloaded. On a transport guide the start and the plate are already in the cells above. */
    private static function transport(FiscalDocument $record): ?Section
    {
        $transport = $record->transport;

        if (blank($transport)) {
            return null;
        }

        $place = fn (string $key): ?string => implode(', ', array_filter([
            $transport[$key]['address'] ?? null,
            trim(($transport[$key]['postal_code'] ?? '').' '.($transport[$key]['city'] ?? '')),
        ], filled(...))) ?: null;
        $guide = $record->type === FiscalDocumentType::TransportGuide;

        return Section::make(__('Transport'))->contained(false)->schema([
            Grid::make(['default' => 1, 'md' => 2])->schema(array_values(array_filter([
                DetailPage::cell('transport_load', __('Loading place'), $place('load')),
                DetailPage::cell('transport_unload', __('Unloading place'), $place('unload')),
                $guide || blank($transport['starts_at'] ?? null) ? null : DetailPage::cell('transport_starts_at', __('Transport starts'), DetailPage::moment(CarbonImmutable::parse($transport['starts_at']))),
                $guide || blank($transport['plate'] ?? null) ? null : DetailPage::cell('transport_plate', __('Number plate'), $transport['plate'])->fontFamily('mono'),
            ])))->extraAttributes(['class' => 'fap-caps-labels']),
        ]);
    }

    /**
     * What the document is about: its lines, the invoice a receipt pays, or a notice saying the
     * lines are not kept here.
     *
     * @return list<Component>
     */
    private static function body(FiscalDocument $record): array
    {
        if ($record->type === FiscalDocumentType::Receipt) {
            return array_values(array_filter([static::paidInvoices($record)]));
        }

        if ($record->lines->isNotEmpty()) {
            return [$record->type === FiscalDocumentType::TransportGuide
                ? Section::make(__('Goods carried'))->contained(false)->schema([static::lines($record)])
                : static::lines($record)];
        }

        return [
            Callout::make(match (true) {
                $record->direction === FiscalDocumentDirection::Received => __('Record without lines: Contiu only keeps the amounts of this document. The detail is in the supplier\'s PDF.'),
                $record->integrationRecord === null => __('Record without lines: the document was issued in another program and Contiu only keeps the amounts. The detail is in the PDF.'),
                default => __('Record without lines: Contiu only keeps the amounts of this document. The detail is in the PDF.'),
            })->info(),
        ];
    }

    /** The lines as a table. A transport guide lists goods, so it leaves the discount and the VAT out. */
    private static function lines(FiscalDocument $record): RepeatableEntry
    {
        $goods = $record->type === FiscalDocumentType::TransportGuide;
        $muted = $record->status === FiscalDocumentStatus::Voided ? 'gray' : null;
        $end = fn (string $label): TableColumn => TableColumn::make($label)->alignment(Alignment::End);

        return RepeatableEntry::make('lines')
            ->hiddenLabel()
            ->extraAttributes(['class' => 'fap-plain-table'])
            ->table(array_values(array_filter([
                TableColumn::make(__('Description')),
                $end(__('Qty')),
                $end(__('Price')),
                $goods ? null : $end(__('Discount')),
                $goods ? null : $end(__('VAT')),
                $end(__('Total')),
            ])))
            ->schema(array_values(array_filter([
                TextEntry::make('description')->weight('medium')->color($muted)
                    ->helperText(fn (FiscalDocumentLine $record): ?string => $record->article === null ? null : __('Article :code', ['code' => $record->article->code])),
                TextEntry::make('quantity')->alignEnd()->color($muted)
                    ->formatStateUsing(fn (int $state, FiscalDocumentLine $record): string => trim(static::quantity($state).' '.$record->unit)),
                TextEntry::make('unit_price')->alignEnd()->color($muted)
                    ->formatStateUsing(fn (int $state): string => Money::format($state)),
                $goods ? null : TextEntry::make('discount_basis_points')->alignEnd()->color($muted)
                    ->formatStateUsing(fn (int $state): string => $state === 0 ? '—' : static::percent($state)),
                $goods ? null : TextEntry::make('vat_basis_points')->alignEnd()->color($muted)
                    ->formatStateUsing(fn (int $state): string => $state === 0 ? __('VAT exempt') : static::percent($state)),
                TextEntry::make('net_amount')->alignEnd()->weight('medium')->color($muted)
                    ->formatStateUsing(fn (int $state): string => Money::format(FiscalDocumentPresenter::signed($record, $state))),
            ])));
    }

    /** "Faturas pagas neste recibo": a receipt has no lines, it says which invoice was paid and how much. */
    private static function paidInvoices(FiscalDocument $record): ?Section
    {
        $invoice = $record->links?->relatedFiscalDocument;

        if ($invoice === null) {
            return null;
        }

        $receivable = $invoice->links?->receivable;
        $end = fn (string $label): TableColumn => TableColumn::make($label)->alignment(Alignment::End);

        return Section::make(__('Invoices paid by this receipt'))->contained(false)->schema([
            RepeatableEntry::make('paid_invoices')
                ->hiddenLabel()
                ->extraAttributes(['class' => 'fap-plain-table'])
                ->state([[
                    'number' => FiscalDocumentPresenter::title($invoice),
                    'date' => DetailPage::date($invoice->document_date),
                    'total' => Money::format($invoice->total_amount),
                    'paid' => Money::format($record->total_amount),
                    'left' => $receivable === null ? null : Money::format((int) $receivable->outstanding),
                ]])
                ->table([TableColumn::make(__('Invoice')), $end(__('Date')), $end(__('Invoice total')), $end(__('Paid by this receipt')), $end(__('Left to pay'))])
                ->schema([
                    TextEntry::make('number')->fontFamily('mono')->color('primary')
                        ->url(FiscalDocumentResource::getUrl('view', ['record' => $invoice]))
                        ->helperText($receivable?->description),
                    TextEntry::make('date')->alignEnd(),
                    TextEntry::make('total')->alignEnd(),
                    TextEntry::make('paid')->alignEnd()->weight('medium'),
                    TextEntry::make('left')->alignEnd()->placeholder('—'),
                ]),
        ]);
    }

    /**
     * What the document adds up to, under the amounts column as on paper. The amounts are the
     * ones saved with the document (FiscalDocumentTotals worked them out when it was written).
     * Every line the kind of document can have is always there, with a dash when the document
     * has nothing for it (no discount, no withholding), so the summary reads the same on all.
     */
    private static function totals(FiscalDocument $record): Grid
    {
        $muted = $record->status === FiscalDocumentStatus::Voided ? 'gray' : null;
        $signed = fn (int $amount): string => Money::format(FiscalDocumentPresenter::signed($record, $amount));
        $row = fn (string $name, string $label, string $amount): TextEntry => TextEntry::make($name)->label($label)->state($amount)->color($muted);
        $total = fn (int $amount): TextEntry => $row('total_amount', FiscalDocumentPresenter::totalLabel($record), $signed($amount))->weight('bold')->size(TextSize::Medium)
            ->extraEntryWrapperAttributes(['class' => 'fap-key-value-total']);
        $discounted = $record->discount_amount > 0;
        $credit = $record->type === FiscalDocumentType::CreditNote;
        $settled = $record->type->settlesOnIssue() && $record->status === FiscalDocumentStatus::Active;
        $withheld = $record->withholding_amount > 0;
        $withholds = $record->direction === FiscalDocumentDirection::Issued && $record->type->countsAsBilling();
        $none = '—';

        $rows = match ($record->type) {
            FiscalDocumentType::Receipt => [$total($record->total_amount)],
            FiscalDocumentType::TransportGuide => [$total($record->net_amount)],
            default => [
                ...($record->lines->isEmpty() ? [] : [
                    $row('lines_total', __('Sum of the lines'), $signed($record->net_amount + $record->discount_amount)),
                    $row('discount_amount', __('Discounts'), $discounted ? Money::format($credit ? $record->discount_amount : -$record->discount_amount) : $none),
                ]),
                $row('net_amount', __('Amount excluding VAT'), $signed($record->net_amount)),
                $row('vat_amount', $record->vat_amount === 0 && filled($record->vat_exemption_reason)
                    ? __('VAT · exempt (:reason)', ['reason' => $record->vat_exemption_reason])
                    : __('VAT'), $signed($record->vat_amount)),
                $total($record->total_amount),
                ...($withholds ? [
                    $row('withholding_amount', $withheld ? __('IRS withholding · :rate', ['rate' => static::percent($record->withholding_basis_points)]) : __('IRS withholding'), $withheld ? Money::format(-$record->withholding_amount) : $none),
                    $row('payable', $settled ? __('Received') : __('To receive'), $signed($record->payableAmount())),
                ] : []),
            ],
        };

        return Grid::make(['default' => 1, 'md' => 2])->schema([
            Group::make([]),
            Section::make()
                ->inlineLabel()
                ->extraAttributes(['class' => 'fap-key-value'])
                ->contained(false)
                ->schema(array_values(array_filter($rows))),
        ]);
    }

    /** "Pagamento": how and when a fatura-recibo or a simplified invoice was paid. */
    private static function payment(FiscalDocument $record): ?Section
    {
        if (! $record->type->settlesOnIssue() || blank($record->payment)) {
            return null;
        }

        $paidOn = $record->payment['paid_on'] ?? null;

        return Section::make(__('Payment'))->contained(false)->schema([
            Grid::make(['default' => 2, 'md' => 4])->schema(array_values(array_filter([
                DetailPage::cell('payment_method', __('How it was paid'), static::paymentMethod($record)),
                DetailPage::cell('payment_paid_on', __('Received on'), blank($paidOn) ? null : DetailPage::date(CarbonImmutable::parse($paidOn))),
                filled($record->payment['account'] ?? null) ? DetailPage::cell('payment_account', __('Went into'), $record->payment['account']) : null,
            ])))->extraAttributes(['class' => 'fap-caps-labels']),
        ]);
    }

    /** The foot of the paper: the notes written on the document, then what the law has it say. */
    private static function footnotes(FiscalDocument $record): ?TextEntry
    {
        $type = $record->type;
        $isNote = in_array($type, [FiscalDocumentType::CreditNote, FiscalDocumentType::DebitNote], true);
        $original = $isNote ? $record->links?->relatedFiscalDocument : null;

        $lines = array_values(array_filter([
            ...(preg_split('/\R/u', (string) $record->notes) ?: []),
            $isNote ? null : $record->reason,
            $record->vat_amount === 0 && filled($record->vat_exemption_reason) ? (FiscalDocumentDraftForm::exemptionReasons()[$record->vat_exemption_reason] ?? null) : null,
            $record->withholding_amount > 0 ? __('IRS withheld at source at :rate, article 101 of the IRS code.', ['rate' => static::percent($record->withholding_basis_points)]) : null,
            $original === null ? null : __('About invoice :number of :date.', ['number' => FiscalDocumentPresenter::title($original), 'date' => DetailPage::date($original->document_date)]),
            $type === FiscalDocumentType::Receipt ? __('A receipt has no lines: it says which invoices were paid and how much.') : null,
            ! $type->countsAsBilling() && $type !== FiscalDocumentType::Receipt ? __('This document is not an invoice.') : null,
        ], filled(...)));

        return $lines === [] ? null : TextEntry::make('notes')->hiddenLabel()->color('gray')->state($lines)->listWithLineBreaks()
            ->extraEntryWrapperAttributes(['class' => 'fap-rule-top']);
    }

    /** @return list<Section> */
    private static function aside(FiscalDocument $record): array
    {
        return array_values(array_filter([
            static::originCard($record),
            static::originalCard($record),
            DetailPage::rowsCard(__('Linked to'), 'linked', static::linkRows($record)),
            static::relatedCard($record),
            DetailPage::rowsCard(__('History'), 'history', static::historyRows($record)),
        ]));
    }

    /**
     * Where the document comes from: "Emissão" when the provider issued it (with how it compares
     * with what the provider holds), "Registo" for one recorded by hand and "Origem" for one received.
     */
    private static function originCard(FiscalDocument $record): Section
    {
        $entry = $record->integrationRecord;
        $received = $record->direction === FiscalDocumentDirection::Received;
        $manual = ! $received && $entry === null && ! $record->isDraft();
        $issuer = FiscalDocumentPresenter::issuer($record);
        $cancellation = FiscalDocumentPresenter::cancellation($record);
        $row = fn (string $name, string $label, ?string $state): TextEntry => DetailPage::cell($name, $label, $state);
        $providerUrl = $entry === null ? null : Invoicing::provider()?->documentUrl($entry->remote_type, $entry->remote_id);

        $rows = match (true) {
            $received => [
                $row('origin', __('Source'), $record->origin),
                $row('recorded_at', FiscalDocumentPresenter::agree($record, 'Recorded on'), $record->created_at === null ? null : DetailPage::moment($record->created_at)),
                $row('issued_by', FiscalDocumentPresenter::agree($record, 'Issued by'), $record->party_name),
                filled($record->atcud) ? $row('atcud', 'ATCUD', $record->atcud)->fontFamily('mono') : null,
                $row('pdf', __('PDF'), $record->currentPdf === null ? __('PDF to attach') : __('PDF saved')),
            ],
            $manual => [
                $row('origin', __('Source'), $record->origin),
                $row('recorded_at', FiscalDocumentPresenter::agree($record, 'Recorded on'), $record->created_at === null ? null : DetailPage::moment($record->created_at)),
                filled($record->series) ? $row('series', __('Series'), $record->series) : null,
                $row('atcud', 'ATCUD', $record->atcud)->fontFamily(filled($record->atcud) ? 'mono' : null),
                $row('pdf', __('PDF'), $record->currentPdf === null ? __('PDF to attach') : __('PDF saved')),
                DetailPage::help('not_issued_here', __('Contiu did not issue this document nor report it to the tax authority: the program that issued it did.')),
            ],
            default => [
                $row('issued_by', FiscalDocumentPresenter::agree($record, 'Issued by'), $issuer['label']),
                $record->isDraft() ? null : $row('issued_at', __('When'), $record->issued_at === null ? null : DetailPage::moment($record->issued_at)),
                $cancellation === null ? null : $row('cancelled_at', __('Cancellation'), DetailPage::moment($cancellation->created_at)),
                $cancellation === null || blank($cancellation->detail) ? null : $row('cancellation_reason', __('Reason'), $cancellation->detail),
                filled($record->series) ? $row('series', __('Series'), $record->series) : null,
                $record->isDraft() || (blank($record->atcud) && ! $record->type->countsAsBilling()) ? null : $row('atcud', 'ATCUD', $record->atcud)->fontFamily(filled($record->atcud) ? 'mono' : null),
                match ($record->type) {
                    FiscalDocumentType::Quote => DetailPage::help('not_fiscal', __('A quote is not an invoice: it does not count as billed and brings no tax.')),
                    FiscalDocumentType::ProForma => DetailPage::help('not_fiscal', __('A pro forma asks for payment before invoicing: it does not count as billed and brings no tax.')),
                    FiscalDocumentType::TransportGuide => DetailPage::help('not_fiscal', __('A guide goes with the goods: it charges nothing and does not count as billed.')),
                    default => null,
                },
                $providerUrl === null ? null : Actions::make([
                    Action::make('openAtProvider')
                        ->label(__('Open at :provider', ['provider' => Invoicing::providerLabel() ?? '']))
                        ->link()
                        ->url($providerUrl, shouldOpenInNewTab: true),
                ]),
            ],
        };

        return Section::make(match (true) {
            $received => __('Origin'),
            $manual => __('Entry'),
            default => __('Issue'),
        })
            ->inlineLabel()
            ->extraAttributes(['class' => 'fap-key-value'])
            ->afterHeader($received || $manual || $issuer['detail'] === null ? [] : [
                Text::make($issuer['detail'])->badge()->color(match (true) {
                    $record->isDraft() => 'gray',
                    $entry?->state === IntegrationRecordState::Differs => 'warning',
                    default => 'success',
                }),
            ])
            ->schema(array_values(array_filter($rows)));
    }

    /**
     * "Fatura corrigida" / "Fatura a que acresce": the invoice a note changes, what stays
     * invoiced once every note on it is counted, and how much of it was received.
     */
    private static function originalCard(FiscalDocument $record): ?Section
    {
        $credit = $record->type === FiscalDocumentType::CreditNote;
        $invoice = $credit || $record->type === FiscalDocumentType::DebitNote ? $record->links?->relatedFiscalDocument : null;

        if ($invoice === null) {
            return null;
        }

        $note = FiscalDocumentPresenter::signed($record, $record->total_amount);
        $others = static::otherNotes($record, $invoice);
        $receivable = $invoice->links?->receivable;
        $row = fn (string $name, string $label, int $amount): TextEntry => TextEntry::make($name)->label($label)->state(Money::format($amount));

        return Section::make($credit ? __('Corrected invoice') : __('Invoice it adds to'))
            ->inlineLabel()
            ->extraAttributes(['class' => 'fap-key-value'])
            ->headerActions([
                Action::make('openOriginal')->label(__('Open the invoice'))->link()
                    ->url(FiscalDocumentResource::getUrl('view', ['record' => $invoice])),
            ])
            ->schema(array_values(array_filter([
                $row('original_total', FiscalDocumentPresenter::heading($invoice), $invoice->total_amount),
                $row('this_note', __('This note'), $note)->color($credit ? 'danger' : null),
                $others === 0 ? null : $row('other_notes', __('Other notes'), $others),
                $row('stays_invoiced', __('Stays invoiced'), $invoice->total_amount + $note + $others)->weight('bold'),
                $receivable === null ? null : $row('original_received', __('Already received'), (int) $receivable->received),
                $receivable === null ? null : $row('original_outstanding', __('Awaiting payment'), (int) $receivable->outstanding),
            ])));
    }

    /** What the other issued notes on the same invoice add to it or take from it, in one query. */
    private static function otherNotes(FiscalDocument $record, FiscalDocument $invoice): int
    {
        return (int) FiscalDocument::query()
            ->whereIn('id', FiscalDocumentLink::query()->where('related_fiscal_document_id', $invoice->getKey())->select('fiscal_document_id'))
            ->whereKeyNot($record->getKey())
            ->where('status', FiscalDocumentStatus::Active)
            ->where('direction', FiscalDocumentDirection::Issued)
            ->whereIn('type', [FiscalDocumentType::CreditNote, FiscalDocumentType::DebitNote])
            ->get(['id', 'type', 'total_amount'])
            ->sum(fn (FiscalDocument $other): int => FiscalDocumentPresenter::signed($other, $other->total_amount));
    }

    /**
     * What the document charges or settles: the receivable and the job or retainer behind it, the
     * payment it recorded, the expense and its supplier.
     *
     * @return list<array<string, mixed>>
     */
    private static function linkRows(FiscalDocument $record): array
    {
        $link = $record->links;
        $receivable = $link?->receivable;
        $billable = $receivable?->billable;
        $payment = $link?->payment;
        $expense = $link?->expense;
        $supplier = $expense?->supplier;
        $method = $payment?->method instanceof PaymentMethod ? $payment->method : PaymentMethod::tryFrom((string) $payment?->method);

        return array_values(array_filter([
            $receivable === null ? null : [
                'title' => $receivable->description,
                'meta' => __('Receivable · due on :date', ['date' => DetailPage::date($receivable->due_on)]),
                'amount' => Money::format((int) $receivable->amount),
                'badge' => $receivable->outstanding <= 0
                    ? ['label' => __('Received'), 'color' => 'success']
                    : ['label' => __('Awaiting payment'), 'color' => 'warning'],
            ],
            $billable instanceof Project || $billable instanceof Retainer ? [
                'title' => $billable->title,
                'meta' => implode(' · ', array_filter([
                    $billable instanceof Project ? __('Job :code', ['code' => $billable->code]) : __('Retainer'),
                    $billable->client?->name,
                ])),
                'url' => $receivable->billableUrl(),
            ] : null,
            $payment === null ? null : [
                'title' => __('Payment on :date', ['date' => DetailPage::date($payment->paid_on)]),
                'meta' => implode(' · ', array_filter([$method?->getLabel(), $record->type->settlesOnIssue() ? __('recorded on issue') : null])) ?: null,
                'url' => PaymentResource::canView($payment) ? PaymentResource::getUrl('view', ['record' => $payment]) : null,
                'amount' => Money::format((int) $payment->amount),
                'badge' => ['label' => __('Received'), 'color' => 'success'],
            ],
            $expense === null ? null : [
                'title' => $expense->description ?: __('Expense'),
                'meta' => __('Expense on :date', ['date' => DetailPage::date($expense->spent_on)]),
                'url' => ExpenseResource::canView($expense) ? ExpenseResource::getUrl('view', ['record' => $expense]) : null,
                'amount' => Money::format((int) $expense->total),
                'badge' => $expense->is_paid
                    ? ['label' => __('Paid (feminine)'), 'color' => 'success']
                    : ['label' => __('To pay'), 'color' => 'warning'],
            ],
            $supplier === null ? null : [
                'title' => $supplier->name,
                'meta' => __('Supplier'),
                'url' => SupplierResource::canView($supplier) ? SupplierResource::getUrl('view', ['record' => $supplier]) : null,
            ],
        ]));
    }

    /**
     * "Documentos relacionados": the document this one was issued against and the notes and
     * receipts issued against it, with the links to write a new one where the app allows it.
     */
    private static function relatedCard(FiscalDocument $record): ?Section
    {
        $rows = static::relatedRows($record);
        $canFollowUp = static::canFollowUp($record);
        $hint = match (true) {
            $rows !== [] => null,
            $canFollowUp => __('No notes or receipts yet.'),
            ! static::canConvert($record) => null,
            $record->type === FiscalDocumentType::Quote => __('On converting, the lines go to an invoice draft you can adjust.'),
            $record->type === FiscalDocumentType::ProForma => __('When you are paid, the invoice receipt comes out with the same lines.'),
            default => null,
        };

        if ($rows === [] && $hint === null) {
            return null;
        }

        return Section::make(__('Related documents'))
            ->footerActions($canFollowUp ? array_values(array_filter([
                static::followUp($record, FiscalDocumentType::CreditNote),
                static::followUp($record, FiscalDocumentType::DebitNote),
                $record->type === FiscalDocumentType::Invoice ? static::followUp($record, FiscalDocumentType::Receipt) : null,
            ])) : [])
            ->schema([
                ...DetailPage::rows('related', $rows),
                ...($hint === null ? [] : [DetailPage::help('no_related', $hint)]),
            ]);
    }

    /**
     * The original first, then what was issued against this document, oldest first. A note's
     * invoice is left out: it has a card of its own.
     *
     * @return list<array<string, mixed>>
     */
    private static function relatedRows(FiscalDocument $record): array
    {
        $isNote = in_array($record->type, [FiscalDocumentType::CreditNote, FiscalDocumentType::DebitNote], true);
        $original = $isNote ? null : $record->links?->relatedFiscalDocument;
        $followUps = FiscalDocument::query()
            ->whereIn('id', FiscalDocumentLink::query()->where('related_fiscal_document_id', $record->getKey())->select('fiscal_document_id'))
            ->orderBy('document_date')
            ->orderBy('id')
            ->get();
        $row = fn (FiscalDocument $document, string $meta): array => [
            'title' => FiscalDocumentPresenter::title($document),
            'meta' => $meta,
            'url' => FiscalDocumentResource::getUrl('view', ['record' => $document]),
            'amount' => Money::format(FiscalDocumentPresenter::signed($document, $document->total_amount)),
        ];

        return [
            ...($original === null ? [] : [$row($original, match (true) {
                $record->type !== FiscalDocumentType::Receipt => __('Original document'),
                $record->total_amount < $original->payableAmount() => __('Invoice paid in part by this receipt'),
                default => __('Invoice paid by this receipt'),
            })]),
            ...$followUps->map(fn (FiscalDocument $document): array => $row($document, FiscalDocumentPresenter::kind($document)))->all(),
        ];
    }

    /** "Credit note", "Debit note", "Receipt": the documents written on top of an issued invoice. */
    private static function followUp(FiscalDocument $record, FiscalDocumentType $type): Action
    {
        return Action::make("new_{$type->value}")
            ->label($type->getLabel())
            ->link()
            ->url(FiscalDocumentResource::getUrl('compose', ['type' => $type->value, 'original' => $record->getKey()]));
    }

    private static function canFollowUp(FiscalDocument $record): bool
    {
        return $record->status === FiscalDocumentStatus::Active
            && $record->direction === FiscalDocumentDirection::Issued
            && $record->type->isInvoice()
            && $record->integrationRecord !== null
            && Invoicing::canIssue();
    }

    /** Whether the page offers to turn this quote or pro forma into an invoice (ViewFiscalDocument has the action). */
    public static function canConvert(FiscalDocument $record): bool
    {
        return in_array($record->type, [FiscalDocumentType::Quote, FiscalDocumentType::ProForma], true)
            && $record->status === FiscalDocumentStatus::Active
            && $record->direction === FiscalDocumentDirection::Issued
            && Invoicing::canIssue() && Invoicing::connected();
    }

    /**
     * What was asked of the provider about this document, newest first. A request that failed
     * says why, in red.
     *
     * @return list<array<string, mixed>>
     */
    private static function historyRows(FiscalDocument $record): array
    {
        return FiscalDocumentPresenter::history($record)
            ->map(function (IntegrationOperation $operation): array {
                $failed = $operation->status === IntegrationOperationStatus::Failed;

                return [
                    'title' => $operation->summary,
                    'meta' => implode(' · ', array_filter([DetailPage::moment($operation->created_at), $operation->user?->name, $failed ? $operation->detail : null])),
                    'color' => $failed ? 'danger' : 'gray',
                ];
            })
            ->all();
    }

    private static function quantity(int $thousandths): string
    {
        return Number::format($thousandths / FiscalDocumentLine::QUANTITY_SCALE, maxPrecision: 3, locale: app()->getLocale());
    }

    private static function percent(int $basisPoints): string
    {
        return FiscalTerms::WITHHOLDING_RATES[$basisPoints] ?? Number::format($basisPoints / 100, maxPrecision: 2, locale: app()->getLocale()).'%';
    }
}
