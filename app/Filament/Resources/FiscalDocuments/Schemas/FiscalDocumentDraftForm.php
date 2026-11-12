<?php

namespace App\Filament\Resources\FiscalDocuments\Schemas;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Enums\Language;
use App\Enums\PaymentMethod;
use App\Filament\Resources\Articles\ArticleResource;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentPresenter;
use App\Filament\Support\MoneyInput;
use App\Filament\Support\QuickCreate;
use App\Models\Article;
use App\Models\Client;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLine;
use App\Models\FiscalDocumentLink;
use App\Models\Receivable;
use App\Support\FinanceSettings;
use App\Support\FiscalDocumentRules;
use App\Support\FiscalDocumentTotals;
use App\Support\FiscalTerms;
use App\Support\Money;
use App\Support\VatExemptionExport;
use App\Support\WithholdingWaiver;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\JsComponent;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Enums\Alignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Js;
use Illuminate\Support\Number;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * The form a document is written in before it is issued. One builder for every kind: each kind
 * turns on the sections it needs (payment for a fatura-recibo, transport for a guide, the
 * original's lines for a credit note), so a rule added here reaches all of them.
 *
 * Inside the form the amounts are as the user types them (euros, percentages, decimal
 * quantities); `data()` turns them into what the domain takes (cents, basis points, thousandths).
 *
 * Nothing here asks the server while the user types: what depends on another field is shown,
 * hidden and added up in the browser (`visibleJs()`, `afterStateUpdatedJs()` and the totals
 * renderer in resources/js). Only choosing the client reloads the form, for that client's
 * receivables. The server works the same amounts out again when the document is saved.
 */
class FiscalDocumentDraftForm
{
    /**
     * True in the browser while no line on screen charges VAT, which is when the document has to
     * say why. A line only counts once it has a VAT rate and an amount above zero.
     */
    private const string CHARGES_NO_VAT_JS = <<<'JS'
        Object.values($get('lines') ?? {}).every((line) => {
            const number = (value) => Number(String(value ?? '').replace(',', '.'))

            return ! (number(line?.vat_basis_points) > 0 && number(line?.quantity) * number(line?.unit_price) > 0 && number(line?.discount) < 100)
        })
        JS;

    /**
     * Why a document charges no VAT, in the codes the tax authority defines.
     *
     * @return array<string, string>
     */
    public static function exemptionReasons(): array
    {
        return [
            'M10' => __('M10 · VAT exemption regime (article 53)'),
            'M07' => __('M07 · Exempt (article 9 of the VAT code)'),
            'M40' => __('M40 · Reverse charge (service to an EU business)'),
            'M99' => __('M99 · Not subject to VAT'),
        ];
    }

    /** @return list<Component> */
    public static function components(FiscalDocumentType $type, ?FiscalDocument $original = null): array
    {
        return array_values(array_filter([
            static::documentSection($type, $original),
            in_array($type, [FiscalDocumentType::CreditNote, FiscalDocumentType::DebitNote], true) ? static::reasonSection() : null,
            $type->hasLines() ? static::linesSection($type) : static::receiptSection($original),
            $type->settlesOnIssue() || $type === FiscalDocumentType::Receipt ? static::paymentSection() : null,
            static::transportSection($type),
            $type->requiresOriginal() && $type !== FiscalDocumentType::Receipt ? static::effectSection($type, $original) : null,
        ]));
    }

    /**
     * The form's state as the domain takes it.
     *
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function data(array $state, FiscalDocumentType $type, ?FiscalDocument $original = null): array
    {
        $identified = $type !== FiscalDocumentType::SimplifiedInvoice || ($state['consumer'] ?? 'final') === 'identified';
        $transport = $type === FiscalDocumentType::TransportGuide || ($state['with_transport'] ?? false);

        return array_filter([
            'type' => $type->value,
            'client_id' => $original?->client_id ?? ($identified ? ($state['client_id'] ?? null) : null),
            'party_tax_id' => $identified ? null : ($state['party_tax_id'] ?? null),
            'receivable_id' => $identified ? ($state['receivable_id'] ?? null) : null,
            'original_id' => $original?->getKey(),
            'document_date' => $state['document_date'] ?? null,
            'due_date' => $state['due_date'] ?? null,
            'withholding_basis_points' => (int) ($state['withholding_basis_points'] ?? 0),
            'vat_exemption_reason' => $state['vat_exemption_reason'] ?? null,
            'client_reference' => $state['client_reference'] ?? null,
            'notes' => static::notesWithWaiver($state, $type),
            'reason' => $state['reason'] ?? null,
            'receivable_effect' => $state['receivable_effect'] ?? null,
            'amount' => $type === FiscalDocumentType::Receipt ? (int) ($state['amount'] ?? 0) : null,
            'payment' => isset($state['payment']['method']) ? $state['payment'] : null,
            'transport' => $transport ? ($state['transport'] ?? null) : null,
            'lines' => $type->hasLines() ? array_values(array_map(static::line(...), $state['lines'] ?? [])) : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * A saved draft, or a document being copied from, as the form's state.
     *
     * @return array<string, mixed>
     */
    public static function state(FiscalDocument $document): array
    {
        return [
            'client_id' => $document->client_id,
            'consumer' => $document->client_id === null ? 'final' : 'identified',
            'party_tax_id' => $document->party_tax_id,
            'receivable_id' => $document->links?->receivable_id,
            'document_date' => $document->document_date?->toDateString(),
            'due_date' => $document->due_date?->toDateString(),
            'withholding_basis_points' => (string) $document->withholding_basis_points,
            'vat_exemption_reason' => $document->vat_exemption_reason,
            'client_reference' => $document->client_reference,
            'notes' => $document->notes,
            'reason' => $document->reason,
            'receivable_effect' => $document->receivable_effect,
            'amount' => $document->total_amount,
            'payment' => $document->payment,
            'with_transport' => $document->transport !== null,
            'transport' => $document->transport,
            'lines' => $document->lines->map(fn (FiscalDocumentLine $line): array => [
                'article_id' => $line->article_id,
                'description' => $line->description,
                'quantity' => static::decimal($line->quantity, FiscalDocumentLine::QUANTITY_SCALE),
                'unit' => $line->unit,
                'unit_price' => $line->unit_price,
                'discount' => static::decimal($line->discount_basis_points, 100),
                'vat_basis_points' => (string) $line->vat_basis_points,
            ])->all(),
        ];
    }

    /** What a new document starts with, before the user types anything. */
    public static function defaults(FiscalDocumentType $type, ?FiscalDocument $original = null): array
    {
        $settings = app(FinanceSettings::class);
        $exempt = $settings->vatExempt();

        return [
            'consumer' => 'final',
            'document_date' => today()->toDateString(),
            'due_date' => match (true) {
                $type === FiscalDocumentType::Invoice, $type === FiscalDocumentType::Quote => today()->addDays(30)->toDateString(),
                $type === FiscalDocumentType::ProForma => today()->addDays(8)->toDateString(),
                default => null,
            },
            'withholding_basis_points' => (string) ($original?->withholding_basis_points ?? 0),
            'vat_exemption_reason' => $original?->vat_exemption_reason ?? ($exempt ? 'M10' : null),
            'payment' => ['method' => PaymentMethod::Transfer->value, 'paid_on' => today()->toDateString()],
            'with_transport' => false,
            'transport' => ['starts_at' => now()->addHour()->startOfHour()->toDateTimeString()],
            'receivable_effect' => FiscalDocumentRules::RECEIVABLE_EFFECTS[$type->value][0] ?? null,
            'amount' => $original?->links?->receivable?->outstanding ?? $original?->payableAmount(),
            'lines' => $type === FiscalDocumentType::CreditNote && $original !== null
                ? static::state($original)['lines']
                : [['description' => null, 'quantity' => '1', 'unit' => 'un', 'unit_price' => null, 'discount' => '0', 'vat_basis_points' => static::defaultVatRate()]],
        ];
    }

    /**
     * The totals of the lines as they are on screen right now.
     *
     * @param  array<int|string, array<string, mixed>>  $lines  the repeater's raw state, amounts in euros
     */
    public static function totals(array $lines, int $withholdingBasisPoints = 0): FiscalDocumentTotals
    {
        return FiscalDocumentTotals::of(array_map(fn (array $line): array => [
            ...static::line([...$line, 'unit_price' => Money::toCents($line['unit_price'] ?? 0)]),
        ], array_values($lines)), $withholdingBasisPoints);
    }

    private static function documentSection(FiscalDocumentType $type, ?FiscalDocument $original): Section
    {
        $simplified = $type === FiscalDocumentType::SimplifiedInvoice;
        $identified = fn (Get $get): bool => ! $simplified || $get('consumer') === 'identified';

        return Section::make(__('Document'))->columns(2)->schema(array_values(array_filter([
            $original !== null ? TextEntry::make('original')->label(__('Original document'))
                ->state(FiscalDocumentPresenter::title($original).' · '.$original->party_name.' · '.Money::format($original->total_amount)) : null,
            $type === FiscalDocumentType::SimplifiedInvoice ? ToggleButtons::make('consumer')
                ->label(__('Client'))
                ->options(['final' => __('Final consumer'), 'identified' => __('Identified client')])
                ->inline() : null,
            $original === null ? QuickCreate::client(Select::make('client_id'))
                ->label($type === FiscalDocumentType::TransportGuide ? __('Recipient') : __('Client'))
                ->options(fn (): array => Client::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->required()
                ->live()
                ->afterStateUpdated(fn (Set $set) => $set('receivable_id', null))
                ->helperText(fn (Get $get): ?string => static::clientLine($get('client_id')))
                ->visibleJs($simplified ? "\$get('consumer') === 'identified'" : null)
                ->dehydrated($identified)
                ->validatedWhenNotDehydrated(false) : null,
            $original === null && $type->isInvoice() ? Callout::make(__('Export of goods: the VAT exemption ends'))
                ->description(fn (Get $get): ?string => static::exportWarning($type, $get))
                ->icon(Phosphor::WarningCircle)
                ->status('warning')
                ->visible(fn (Get $get): bool => static::exportWarning($type, $get) !== null)
                ->columnSpanFull() : null,
            $type === FiscalDocumentType::SimplifiedInvoice ? TextInput::make('party_tax_id')
                ->label(__('Tax ID'))
                ->maxLength(64)
                ->placeholder(__('Only if the client asks'))
                ->hiddenJs("\$get('consumer') === 'identified'")
                ->dehydrated(fn (Get $get): bool => ! $identified($get))
                ->validatedWhenNotDehydrated(false) : null,
            $original === null && ($type->isInvoice() || $type === FiscalDocumentType::ProForma) ? Select::make('receivable_id')
                ->label(__('What this document charges'))
                ->options(fn (Get $get): array => static::receivableOptions($get('client_id')))
                ->placeholder(__('Nothing in particular'))
                ->helperText(__('Ties the document to the receivable. Optional.'))
                ->visibleJs($simplified ? "!! \$get('client_id') && \$get('consumer') === 'identified'" : "!! \$get('client_id')") : null,
            DatePicker::make('document_date')
                ->label(__('Date'))
                ->native(false)
                ->displayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
                ->maxDate(today())
                ->required(),
            $type->hasTerm() ? DatePicker::make('due_date')
                ->label(match ($type) {
                    FiscalDocumentType::Quote => __('Valid until'),
                    FiscalDocumentType::ProForma => __('Pay by'),
                    default => __('Due date'),
                })
                ->native(false)
                ->displayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
                ->afterOrEqual('document_date') : null,
            $type->countsAsBilling() ? ToggleButtons::make('withholding_basis_points')
                ->label(__('IRS withholding'))
                ->options([0 => __('No withholding')] + FiscalTerms::WITHHOLDING_RATES)
                ->inline()
                ->helperText(fn (): string => static::withholdingHelp()) : null,
            $type->hasLines() && $type !== FiscalDocumentType::TransportGuide ? Select::make('vat_exemption_reason')
                ->label(__('Why no VAT is charged'))
                ->options(fn (): array => static::exemptionReasons())
                ->visibleJs(static::CHARGES_NO_VAT_JS)
                ->dehydrated(fn (Get $get): bool => static::totals((array) $get('lines'))->vat === 0)
                ->validatedWhenNotDehydrated(false)
                ->required(fn (Get $get): bool => static::totals((array) $get('lines'))->net > 0) : null,
        ])));
    }

    /**
     * The warning of an exempt account invoicing a client outside the European Union while it sells
     * goods (IVA08, VAT05; none for services, VAT06). It is not dismissible: it stays on screen for
     * as long as the chosen client is one an export may go to, and redraws when the client changes,
     * the one field that already asks the server.
     */
    private static function exportWarning(FiscalDocumentType $type, Get $get): ?string
    {
        $clientId = $get('client_id');

        if (blank($clientId) || ($type === FiscalDocumentType::SimplifiedInvoice && $get('consumer') !== 'identified')) {
            return null;
        }

        $date = filled($get('document_date')) ? CarbonImmutable::parse($get('document_date')) : CarbonImmutable::today();

        return VatExemptionExport::warning(Client::query()->with('structuredAddress')->find($clientId), $type, $date);
    }

    /**
     * Which rate applies (art. 101.º CIRS, RET002): 23 % for the occupations of the art. 151.º table,
     * 11.5 % for other services, none for sales of goods; or that the waiver of art. 101.º-B is in
     * force (RET004). Only business clients with accounts withhold.
     */
    private static function withholdingHelp(): string
    {
        $settings = app(FinanceSettings::class);

        return match (true) {
            (new WithholdingWaiver($settings))->appliesOn(CarbonImmutable::today()) => __('Your withholding waiver (Article 101-B) is in force: choose no withholding. The legal mention is added to the document.'),
            $settings->sellsGoodsUnderIrs() => __('Sales of goods have no IRS withholding.'),
            round($settings->irsCoefficient(), 2) === 0.75 => __('A business client withholds 23% (occupations in Article 151) and pays it to the tax authority for you.'),
            default => __('A business client withholds 11.5% (other services) and pays it to the tax authority for you.'),
        };
    }

    /**
     * The notes as typed, plus the legal mention of the withholding waiver on a billing document
     * without withholding issued while the waiver applies (art. 101.º-B, n.º 3, RET005).
     *
     * @param  array<string, mixed>  $state
     */
    private static function notesWithWaiver(array $state, FiscalDocumentType $type): ?string
    {
        $notes = filled($state['notes'] ?? null) ? (string) $state['notes'] : null;
        $date = filled($state['document_date'] ?? null) ? CarbonImmutable::parse($state['document_date']) : CarbonImmutable::today();

        if (! $type->countsAsBilling() || (int) ($state['withholding_basis_points'] ?? 0) !== 0
            || str_contains((string) $notes, WithholdingWaiver::MENTION)
            || ! (new WithholdingWaiver(app(FinanceSettings::class)))->appliesOn($date)) {
            return $notes;
        }

        return $notes === null ? WithholdingWaiver::MENTION : $notes."\n".WithholdingWaiver::MENTION;
    }

    private static function reasonSection(): Section
    {
        return Section::make(__('Reason'))->schema([
            TextInput::make('reason')->label(__('Reason shown on the document'))->required()->maxLength(255),
        ]);
    }

    private static function linesSection(FiscalDocumentType $type): Section
    {
        $goods = $type === FiscalDocumentType::TransportGuide;

        return Section::make($goods ? __('Goods carried') : __('Lines'))->schema([
            Repeater::make('lines')
                ->hiddenLabel()
                ->minItems(1)
                ->maxItems(FiscalDocumentRules::MAX_LINES)
                ->reorderable()
                ->addActionLabel(__('Add line'))
                ->addActionAlignment(Alignment::Start)
                ->table([
                    TableColumn::make(__('Description'))->alignment(Alignment::Start),
                    TableColumn::make(__('Qty'))->width('6rem')->alignment(Alignment::End),
                    TableColumn::make(__('Unit'))->width('6rem'),
                    TableColumn::make(__('Price'))->width('9rem')->alignment(Alignment::End),
                    TableColumn::make(__('Discount'))->width('7rem')->alignment(Alignment::End),
                    TableColumn::make(__('VAT'))->width('8rem'),
                    TableColumn::make(__('Total'))->width('7rem')->alignment(Alignment::End),
                ])
                ->schema([
                    Hidden::make('article_id'),
                    TextInput::make('description')
                        ->required()
                        ->maxLength(255)
                        ->datalist(fn (): array => static::articles()->pluck('name')->all())
                        ->placeholder(__('Write a line or pick an article'))
                        ->afterStateUpdatedJs(fn (): string => static::fillFromArticleJs()),
                    TextInput::make('quantity')->required()->numeric()->minValue(0.001)->step(0.001)->default('1'),
                    TextInput::make('unit')->required()->maxLength(16)->default('un'),
                    MoneyInput::make('unit_price')->required()->minValue(0),
                    TextInput::make('discount')->numeric()->minValue(0)->maxValue(100)->step(0.01)->suffix('%')->default('0'),
                    Select::make('vat_basis_points')
                        ->options(fn (): array => static::vatOptions())
                        ->default(fn (): string => static::defaultVatRate())
                        ->selectablePlaceholder(false)
                        ->required(),
                    static::totalsRenderer('line'),
                ])
                ->extraItemActions([static::saveAsArticleAction()])
                // URL-encoded: the names are the user's own text and must not be able to end the attribute.
                ->extraAttributes(fn (): array => ['data-articles' => rawurlencode(json_encode(static::articleCatalogue(), JSON_THROW_ON_ERROR))]),
            Grid::make(['default' => 1, 'lg' => 2])->schema([
                Grid::make(1)->schema($goods ? [static::notesField()] : [
                    static::notesField($type === FiscalDocumentType::Quote ? __('Conditions') : __('Notes')),
                    TextInput::make('client_reference')
                        ->label(__('Client reference'))
                        ->maxLength(64)
                        ->placeholder(__('Order or requisition number'))
                        ->visible($type->isInvoice()),
                ]),
                static::totalsSection($type),
            ]),
        ]);
    }

    /** What the lines add up to, redrawn in the browser as they change. */
    private static function totalsSection(FiscalDocumentType $type): Section
    {
        return Section::make()
            ->contained(false)
            ->extraAttributes(['class' => 'fap-key-value'])
            ->schema([
                static::totalsRenderer('document', fn (Get $get): array => [
                    'showsVat' => $type !== FiscalDocumentType::TransportGuide,
                    'showsWithholding' => $type->countsAsBilling(),
                    'labels' => [
                        'gross' => __('Sum of the lines'),
                        'discount' => __('Discounts'),
                        'net' => __('Excluding VAT'),
                        'vat' => __('VAT'),
                        'total' => $type === FiscalDocumentType::TransportGuide ? __('Value of the goods') : __('Document total'),
                        'withholding' => __('IRS withholding'),
                        'payable' => __('To receive'),
                    ],
                    'messages' => [
                        'matches' => __('The total matches the receivable: :amount.'),
                        'differs' => __('The receivable is :amount, not this total.'),
                    ],
                    'classes' => [
                        'matches' => 'text-success-600 dark:text-success-400',
                        'differs' => 'text-warning-600 dark:text-warning-400',
                    ],
                    'receivables' => static::receivables($get('client_id'))->mapWithKeys(fn (Receivable $receivable): array => [$receivable->getKey() => (int) $receivable->amount])->all(),
                ]),
            ]);
    }

    /**
     * The amounts worked out in the browser from what is typed: a whole document's ("document")
     * or one line's ("line"). The renderer mirrors FiscalDocumentTotals, so nothing is asked of
     * the server while typing.
     *
     * @param  (Closure(Get): array<string, mixed>)|null  $configuration
     */
    private static function totalsRenderer(string $mode, ?Closure $configuration = null): JsComponent
    {
        return JsComponent::make()
            ->key("fiscal-document-totals-{$mode}")
            ->renderer(fn (): string => static::previewAsset())
            ->rendererConfiguration(fn (Get $get): array => [
                'mode' => $mode,
                'locale' => Language::resolve(app()->getLocale())->value,
                ...($configuration === null ? [] : $configuration($get)),
            ]);
    }

    private static function previewAsset(): string
    {
        return Vite::asset('resources/js/filament/schemas/components/fiscal-document-totals.js');
    }

    private static function receiptSection(?FiscalDocument $original): Section
    {
        return Section::make(__('Amount received'))->schema([
            MoneyInput::make('amount')
                ->label(__('Paid now'))
                ->required()
                ->minValue(0.01)
                ->helperText($original === null ? null : __('Outstanding on :number: :amount.', [
                    'number' => FiscalDocumentPresenter::title($original),
                    'amount' => Money::format((int) ($original->links?->receivable?->outstanding ?? $original->payableAmount())),
                ])),
        ]);
    }

    private static function paymentSection(): Section
    {
        return Section::make(__('Payment'))->columns(2)->schema([
            ToggleButtons::make('payment.method')
                ->label(__('How it was paid'))
                ->options(collect(PaymentMethod::cases())->reject(fn (PaymentMethod $method): bool => $method === PaymentMethod::Stripe)
                    ->mapWithKeys(fn (PaymentMethod $method): array => [$method->value => $method->getLabel()])->all())
                ->inline()
                ->required()
                ->columnSpanFull(),
            DatePicker::make('payment.paid_on')
                ->label(__('Received on'))
                ->native(false)
                ->displayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
                ->maxDate(today())
                ->required(),
            TextEntry::make('payment_note')->hiddenLabel()->color('gray')->columnSpanFull()
                ->state(__('When the document is issued, the payment is recorded and the receivable is settled.')),
        ]);
    }

    /** Where the goods leave from and go to. Always for a transport guide; optional on an invoice of an account that sells goods. */
    private static function transportSection(FiscalDocumentType $type): ?Section
    {
        $guide = $type === FiscalDocumentType::TransportGuide;

        if (! $guide && (! $type->isInvoice() || ! app(FinanceSettings::class)->sellsGoods())) {
            return null;
        }

        $shown = fn (Get $get): bool => $guide || (bool) $get('with_transport');
        $shownJs = $guide ? null : "!! \$get('with_transport')";
        $place = fn (string $key, string $label): Grid => Grid::make(['default' => 1, 'md' => 4])->schema([
            TextInput::make("transport.{$key}.address")->label($label)->required()->maxLength(255)->columnSpan(['md' => 2])->dehydrated($shown)->validatedWhenNotDehydrated(false),
            TextInput::make("transport.{$key}.postal_code")->label(__('Postcode'))->required()->maxLength(16)->dehydrated($shown)->validatedWhenNotDehydrated(false),
            TextInput::make("transport.{$key}.city")->label(__('City'))->required()->maxLength(100)->dehydrated($shown)->validatedWhenNotDehydrated(false),
        ])->visibleJs($shownJs);

        return Section::make(__('Transport'))
            ->schema(array_values(array_filter([
                $guide ? null : Toggle::make('with_transport')->label(__('The goods travel with this document')),
                $place('load', __('Loading place')),
                $place('unload', __('Unloading place')),
                Grid::make(['default' => 1, 'md' => 4])->schema([
                    DateTimePicker::make('transport.starts_at')->label(__('Transport starts'))->native(false)->seconds(false)->required()->columnSpan(['md' => 2])
                        ->dehydrated($shown)->validatedWhenNotDehydrated(false),
                    TextInput::make('transport.plate')->label(__('Number plate'))->maxLength(16)->placeholder('AA-00-AA'),
                ])->visibleJs($shownJs),
            ])));
    }

    private static function effectSection(FiscalDocumentType $type, ?FiscalDocument $original): Section
    {
        $credit = $type === FiscalDocumentType::CreditNote;

        return Section::make(__('What happens to the receivable'))->schema([
            Radio::make('receivable_effect')
                ->hiddenLabel()
                ->options($credit
                    ? ['apply' => __('Take it off what is left to receive'), 'refund' => __('Refund the client')]
                    : ['add' => __('Add it to what is left to receive'), 'separate' => __('Charge it separately')])
                ->descriptions($credit
                    ? ['apply' => __('The credit is applied to the invoice\'s receivable.'), 'refund' => __('For when it was already paid. You record the refund afterwards.')]
                    : ['add' => __('The invoice\'s receivable grows by this note.'), 'separate' => __('You create the new receivable afterwards.')])
                ->required(),
        ])->visible($original !== null);
    }

    private static function notesField(?string $label = null): Textarea
    {
        return Textarea::make('notes')->label($label ?? __('Notes'))->rows(3)->maxLength(2000)
            ->placeholder(__('They appear on the document, under the lines'));
    }

    /**
     * One line of the form as the domain takes it.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, int|string|null>
     */
    private static function line(array $line): array
    {
        return [
            'article_id' => $line['article_id'] ?? null,
            'description' => $line['description'] ?? null,
            'quantity' => (int) round(static::number($line['quantity'] ?? 0) * FiscalDocumentLine::QUANTITY_SCALE),
            'unit' => $line['unit'] ?? 'un',
            'unit_price' => (int) ($line['unit_price'] ?? 0),
            'discount_basis_points' => (int) round(static::number($line['discount'] ?? 0) * 100),
            'vat_basis_points' => (int) ($line['vat_basis_points'] ?? 0),
        ];
    }

    private static function number(mixed $value): float
    {
        return (float) str_replace(',', '.', (string) $value);
    }

    private static function decimal(int $value, int $scale): string
    {
        return rtrim(rtrim(number_format($value / $scale, 3, '.', ''), '0'), '.') ?: '0';
    }

    /**
     * The active articles as the browser needs them to fill a line: the price in euros, as the
     * field takes it.
     *
     * @return list<array{id: int, name: string, unit: string, price: string, vat: ?string}>
     */
    public static function articleCatalogue(): array
    {
        return static::articles()->map(fn (Article $article): array => [
            'id' => $article->getKey(),
            'name' => $article->name,
            'unit' => $article->unit,
            'price' => number_format($article->unit_price / 100, 2, '.', ''),
            'vat' => $article->vat_basis_points === null ? null : (string) $article->vat_basis_points,
        ])->values()->all();
    }

    /**
     * Typing an article's name fills the rest of the line with its unit, price and VAT, which can
     * then be changed. Done in the browser, from the catalogue the repeater carries once for all
     * its lines; the server only checks that the article is the account's own.
     */
    private static function fillFromArticleJs(): string
    {
        $asset = Js::from(static::previewAsset());

        return <<<JS
            const input = \$el;
            const description = \$state;
            import({$asset}).then(({ articleNamed, articlesNear }) => {
                if (! input.isConnected) return;

                const article = articleNamed(articlesNear(input), description);

                \$set('article_id', article?.id ?? null);

                if (article === null) return;

                \$set('unit', article.unit);
                \$set('unit_price', article.price);

                if (article.vat !== null) \$set('vat_basis_points', article.vat);
            });
            JS;
    }

    /**
     * "Save as article" on a free line: keeps its text, unit, price and VAT as an article of the
     * account and ties the line to it. Shown while the line has a description and no article; the
     * server decides on the article and the browser on the description as it is typed, so nothing
     * is asked of the server while typing.
     */
    private static function saveAsArticleAction(): Action
    {
        return Action::make('saveAsArticle')
            ->label(__('Save as article'))
            ->tooltip(__('Save as article'))
            ->icon(Phosphor::BookmarkSimple)
            ->visible(fn (array $arguments, Repeater $component): bool => ArticleResource::canCreate()
                && blank($component->getRawState()[$arguments['item'] ?? null]['article_id'] ?? null))
            ->extraAttributes(function (array $arguments, Repeater $component): array {
                $line = $component->getStatePath().'.'.($arguments['item'] ?? '');

                // Js::from() quotes the path in single quotes with any double quote escaped, so it cannot end the attribute.
                return ['x-show' => sprintf(
                    "String(\$wire.\$get(%s) ?? '').trim() !== '' && ! \$wire.\$get(%s)",
                    Js::from("{$line}.description"),
                    Js::from("{$line}.article_id"),
                )];
            })
            ->action(function (array $arguments, Repeater $component, Action $action): void {
                $key = (string) ($arguments['item'] ?? '');
                $raw = $component->getRawState();

                if (! isset($raw[$key]) || filled($raw[$key]['article_id'] ?? null)) {
                    $action->halt();
                }

                $line = $component->getItemState($key);
                $name = trim((string) ($line['description'] ?? ''));

                if ($name === '') {
                    Notification::make()->warning()->title(__('Write the description of the line first.'))->send();
                    $action->halt();
                }

                if (Article::query()->where('name', $name)->exists()) {
                    Notification::make()->danger()->title(__('You already have an article with this name.'))->send();
                    $action->halt();
                }

                $article = Article::query()->create([
                    'code' => static::nextArticleCode(),
                    'name' => $name,
                    'unit' => filled($line['unit'] ?? null) ? (string) $line['unit'] : 'un',
                    'unit_price' => (int) ($line['unit_price'] ?? 0),
                    'vat_basis_points' => (int) ($line['vat_basis_points'] ?? 0),
                ]);

                $raw[$key]['article_id'] = $article->getKey();
                $component->rawState($raw);

                Notification::make()->success()->title(__('Saved as article'))
                    ->body(__(':name is now in your articles, with code :code.', ['name' => $article->name, 'code' => $article->code]))
                    ->send();
            });
    }

    /** The first free code of the "ART-0001" kind the account has not used yet. */
    private static function nextArticleCode(): string
    {
        $number = Article::query()->count() + 1;

        do {
            $code = sprintf('ART-%04d', $number++);
        } while (Article::query()->where('code', $code)->exists());

        return $code;
    }

    /** @return Collection<int, Article> */
    private static function articles(): Collection
    {
        return once(fn (): Collection => Article::query()->where('is_active', true)->orderBy('name')->get(['id', 'name', 'unit', 'unit_price', 'vat_basis_points']));
    }

    /** The VAT a new line starts with: none for an exempt account, otherwise the standard rate. */
    private static function defaultVatRate(): string
    {
        return app(FinanceSettings::class)->vatExempt() ? '0' : (string) max(FiscalDocumentRules::vatRates());
    }

    /** @return array<string, string> */
    private static function vatOptions(): array
    {
        return collect(FiscalDocumentRules::vatRates())
            ->mapWithKeys(fn (int $rate): array => [(string) $rate => $rate === 0 ? __('VAT exempt') : Number::format($rate / 100, maxPrecision: 2, locale: app()->getLocale()).'%'])
            ->all();
    }

    private static function clientLine(mixed $clientId): ?string
    {
        $client = blank($clientId) ? null : Client::query()->find($clientId, ['id', 'vat_number']);

        return $client === null ? null : (filled($client->vat_number) ? __('Tax ID :number', ['number' => $client->vat_number]) : __('No tax ID yet'));
    }

    /**
     * The client's receivables that still have no invoice, by id.
     *
     * @return array<int, string>
     */
    private static function receivableOptions(mixed $clientId): array
    {
        return static::receivables($clientId)
            ->mapWithKeys(fn (Receivable $receivable): array => [$receivable->getKey() => $receivable->label.' · '.Money::format((int) $receivable->amount)])
            ->all();
    }

    /**
     * Read once per request, for the options of the field and for the amounts the totals compare.
     *
     * @return Collection<int, Receivable>
     */
    private static function receivables(mixed $clientId): Collection
    {
        if (blank($clientId)) {
            return collect();
        }

        return once(function () use ($clientId): Collection {
            $invoiced = FiscalDocumentLink::query()
                ->whereNotNull('receivable_id')
                ->whereHas('fiscalDocument', fn (Builder $query) => $query
                    ->where('direction', FiscalDocumentDirection::Issued)
                    ->where('status', FiscalDocumentStatus::Active))
                ->select('receivable_id');

            return Receivable::query()
                ->excludingCancelled()
                ->forClients([(int) $clientId])
                ->whereNotIn('receivables.id', $invoiced)
                ->with('billable')
                ->orderBy('due_on')
                ->limit(50)
                ->get()
                ->toBase();
        });
    }
}
