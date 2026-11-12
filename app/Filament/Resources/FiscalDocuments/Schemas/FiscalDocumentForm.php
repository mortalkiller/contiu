<?php

namespace App\Filament\Resources\FiscalDocuments\Schemas;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Enums\Language;
use App\Filament\Support\DetachedFileUpload;
use App\Filament\Support\MoneyInput;
use App\Models\Expense;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLine;
use App\Models\FiscalDocumentLink;
use App\Models\Payment;
use App\Models\Receivable;
use App\Support\FinanceSettings;
use App\Support\FiscalDocumentPdfs;
use App\Support\FiscalDocumentRules;
use App\Support\Money;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Alignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\Js;
use Illuminate\Support\Number;

final class FiscalDocumentForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->defaultDateDisplayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
            ->defaultNumberLocale(fn (): string => app()->getLocale())
            ->components([
                Section::make(__('Document'))
                    ->schema([
                        Select::make('type')->label(__('Type'))->options(FiscalDocumentType::class)->required(),
                        Select::make('direction')->label(__('Direction'))->options(FiscalDocumentDirection::class)->required(),
                        TextInput::make('number')->label(__('Number'))->required()->maxLength(255),
                        TextInput::make('atcud')->label(__('ATCUD'))->disabled()
                            ->visible(fn (?FiscalDocument $record): bool => filled($record?->atcud)),
                        TextInput::make('origin')->label(__('Source'))->required()->maxLength(255)->default('Registo manual'),
                        DatePicker::make('document_date')->label(__('Document date'))->required(),
                        DatePicker::make('operation_date')->label(__('Transaction date')),
                        Select::make('status')->label(__('Status'))->options(FiscalDocumentStatus::class)
                            ->default(FiscalDocumentStatus::Active->value)->required(),
                        DetachedFileUpload::make('pdf_upload')
                            ->label(fn (?FiscalDocument $record): string => $record?->currentPdf ? __('Replace PDF') : __('Document PDF'))
                            ->hidden(fn (?FiscalDocument $record, string $operation): bool => $operation === 'view' || $record?->integrationRecord !== null)
                            ->acceptedFileTypes(['application/pdf'])
                            ->maxSize(FiscalDocumentPdfs::MAX_KIB),
                    ])->columns(2)->columnSpanFull(),
                Section::make(__('Amounts'))
                    ->schema([
                        TextInput::make('currency')->label(__('Currency'))->required()->default('EUR')
                            ->length(3)->regex('/^[A-Za-z]{3}$/')
                            ->dehydrateStateUsing(fn (string $state): string => strtoupper($state)),
                        self::amount('net_amount', __('Taxable base')),
                        self::amount('vat_amount', __('VAT')),
                        self::amount('total_amount', __('Total'))->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                            $net = $get('net_amount');
                            $vat = $get('vat_amount');

                            if (! self::linesGiven($get) && is_numeric($net) && is_numeric($vat) && is_numeric($value)
                                && Money::toCents($value) !== Money::toCents($net) + Money::toCents($vat)) {
                                $fail(__('The total must equal the taxable base plus VAT.'));
                            }
                        }),
                    ])->columns(4)->columnSpanFull(),
                self::linesSection(),
                Section::make(__('Counterparty'))
                    ->schema([
                        TextInput::make('party_name')->label(__('Name on document'))->maxLength(255),
                        TextInput::make('party_tax_id')->label(__('Tax ID on document'))->maxLength(64),
                    ])->columns(2)->columnSpanFull(),
                Section::make(__('Services to EU businesses'))
                    ->visible(fn (?FiscalDocument $record): bool => $record?->fiscalOperationEvidence !== null)
                    ->schema([
                        TextEntry::make('fiscalOperationEvidence.reason')->label(__('Tax reason'))->badge(),
                        TextEntry::make('fiscalOperationEvidence.vat_number')->label(__('VAT number')),
                        TextEntry::make('fiscalOperationEvidence.operation_on')->label(__('Confirmed transaction date'))->date(),
                        TextEntry::make('fiscalOperationEvidence.net_amount')->label(__('Confirmed taxable base'))
                            ->formatStateUsing(fn (int $state): string => Money::format($state, locale: app()->getLocale())),
                        TextEntry::make('fiscalOperationEvidence.historical_vies_reference')->label(__('VIES evidence'))
                            ->visible(fn (FiscalDocument $record): bool => $record->fiscalOperationEvidence?->historical_vies_reference !== null),
                        TextEntry::make('euEvidenceReview')->label(__('Status'))->badge()->color('warning')
                            ->state(__('Evidence pending review'))
                            ->visible(fn (FiscalDocument $record): bool => $record->fiscalOperationEvidence?->matchesDocument($record) === false),
                    ])->columns(2)->columnSpanFull(),
                Section::make(__('Links'))
                    ->schema([
                        self::target('receivable_id', __('Receivable'), Receivable::class, ['description'],
                            fn (Receivable $record): string => "#{$record->id} · {$record->label}"),
                        self::target('payment_id', __('Payment received'), Payment::class, ['invoice_number'],
                            fn (Payment $record): string => "#{$record->id} · {$record->paid_on->format(Language::resolve(app()->getLocale())->dateFormat())} · ".Money::format($record->amount, locale: app()->getLocale())
                                .self::legacyReference($record->invoice_number)),
                        self::target('expense_id', __('Expense'), Expense::class, ['description', 'invoice_number'],
                            fn (Expense $record): string => "#{$record->id} · {$record->description}".self::legacyReference($record->invoice_number)),
                        self::target('related_fiscal_document_id', __('Related document'), FiscalDocument::class, ['number'],
                            fn (FiscalDocument $record): string => "{$record->type->getLabel()} · {$record->number} · {$record->document_date->format(Language::resolve(app()->getLocale())->dateFormat())}"),
                    ])->columns(2)->columnSpanFull(),
            ]);
    }

    public static function withLinkData(array $data, FiscalDocument $record): array
    {
        return $data + Arr::only($record->links?->getAttributes() ?? [], FiscalDocumentLink::TARGET_FIELDS);
    }

    /**
     * A recorded document's lines as the form shows them: the quantity as a decimal, the price
     * in cents (the money field shows it in euros) and the VAT rate in basis points.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withLines(array $data, FiscalDocument $record): array
    {
        return [...$data, 'lines' => $record->lines->map(fn (FiscalDocumentLine $line): array => [
            'description' => $line->description,
            'quantity' => rtrim(rtrim(number_format($line->quantity / FiscalDocumentLine::QUANTITY_SCALE, 3, '.', ''), '0'), '.') ?: '0',
            'unit_price' => $line->unit_price,
            'vat_basis_points' => (string) $line->vat_basis_points,
        ])->all()];
    }

    /**
     * The form's state as SaveFiscalDocument takes it: the lines in thousandths, cents and basis
     * points. Without lines the document keeps only its header amounts.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function data(array $data): array
    {
        $data['lines'] = array_values(array_map(fn (array $line): array => [
            'description' => $line['description'] ?? null,
            'quantity' => (int) round((float) str_replace(',', '.', (string) ($line['quantity'] ?? 0)) * FiscalDocumentLine::QUANTITY_SCALE),
            'unit' => 'un',
            'unit_price' => (int) ($line['unit_price'] ?? 0),
            'vat_basis_points' => (int) ($line['vat_basis_points'] ?? 0),
        ], array_filter((array) ($data['lines'] ?? []), is_array(...))));

        return $data;
    }

    /**
     * What was charged, line by line. Optional: with lines, the amounts above are worked out from
     * them in the browser as they are typed, and again on the server when the document is saved.
     */
    private static function linesSection(): Section
    {
        $hasLines = fn (Get $get): bool => self::typeValue($get('type')) !== FiscalDocumentType::Receipt->value;

        return Section::make(__('Lines'))
            ->description(__('Optional. With lines, the taxable base, the VAT and the total are worked out from them.'))
            // A receipt only says which invoices were paid: it has no lines.
            ->visibleJs(sprintf("\$get('type') !== %s", Js::from(FiscalDocumentType::Receipt->value)))
            ->schema([
                Repeater::make('lines')
                    ->hiddenLabel()
                    ->dehydrated($hasLines)
                    ->validatedWhenNotDehydrated(false)
                    ->defaultItems(0)
                    ->maxItems(FiscalDocumentRules::MAX_LINES)
                    ->reorderable()
                    ->addActionLabel(__('Add line'))
                    ->addActionAlignment(Alignment::Start)
                    ->table([
                        TableColumn::make(__('Description'))->alignment(Alignment::Start),
                        TableColumn::make(__('Qty'))->width('7rem')->alignment(Alignment::End),
                        TableColumn::make(__('Price'))->width('10rem')->alignment(Alignment::End),
                        TableColumn::make(__('VAT'))->width('9rem'),
                    ])
                    ->schema([
                        TextInput::make('description')->required()->maxLength(255),
                        TextInput::make('quantity')->required()->numeric()->minValue(0.001)->step(0.001)->default('1')
                            ->afterStateUpdatedJs(self::totalsFromLinesJs()),
                        MoneyInput::make('unit_price')->required()->minValue(0)->suffix(null)
                            ->afterStateUpdatedJs(self::totalsFromLinesJs()),
                        Select::make('vat_basis_points')
                            ->options(fn (): array => self::vatOptions())
                            ->default(fn (): string => app(FinanceSettings::class)->vatExempt() ? '0' : (string) max(FiscalDocumentRules::vatRates()))
                            ->selectablePlaceholder(false)
                            ->required()
                            ->afterStateUpdatedJs(self::totalsFromLinesJs()),
                    ])
                    // Adding or removing a line is a request already: the amounts follow it there.
                    ->afterStateUpdated(function (Set $set, ?array $state): void {
                        if (blank($state)) {
                            return;
                        }

                        $totals = FiscalDocumentDraftForm::totals(array_filter($state, is_array(...)));
                        $set('net_amount', Money::toEuros($totals->net));
                        $set('vat_amount', Money::toEuros($totals->vat));
                        $set('total_amount', Money::toEuros($totals->total));
                    }),
            ])
            ->columnSpanFull();
    }

    /**
     * Fills the taxable base, the VAT and the total from the lines on screen, in the browser and
     * with the same sums as App\Support\FiscalDocumentTotals (fiscal-document-totals.js).
     */
    private static function totalsFromLinesJs(): string
    {
        $asset = Js::from(Vite::asset('resources/js/filament/schemas/components/fiscal-document-totals.js'));

        return <<<JS
            const lines = \$get('../../lines') ?? {};
            import({$asset}).then(({ documentTotals }) => {
                if (Object.keys(lines).length === 0) return;

                const totals = documentTotals(lines);
                const euros = (cents) => {
                    const size = cents < 0n ? -cents : cents;

                    return (cents < 0n ? '-' : '') + (size / 100n) + '.' + String(size % 100n).padStart(2, '0');
                };

                \$set('../../net_amount', euros(totals.net));
                \$set('../../vat_amount', euros(totals.vat));
                \$set('../../total_amount', euros(totals.total));
            });
            JS;
    }

    /**
     * Every VAT rate of Portugal's three regions: a recorded document may come from any of them.
     *
     * @return array<string, string>
     */
    private static function vatOptions(): array
    {
        return collect(FiscalDocumentRules::portugueseVatRates())
            ->mapWithKeys(fn (int $rate): array => [
                (string) $rate => $rate === 0 ? __('VAT exempt') : Number::format($rate / 100, maxPrecision: 2, locale: app()->getLocale()).'%',
            ])
            ->all();
    }

    /** The document type as the form holds it: the enum once filled from a record, its value once chosen. */
    private static function typeValue(mixed $type): ?string
    {
        return $type instanceof FiscalDocumentType ? $type->value : (is_string($type) ? $type : null);
    }

    /** Whether the amounts come from lines: the document has some, and is of a kind that has lines. */
    private static function linesGiven(Get $get): bool
    {
        return self::typeValue($get('type')) !== FiscalDocumentType::Receipt->value && filled($get('lines'));
    }

    /** Required while there are no lines; with lines, the amounts come from them. */
    private static function amount(string $name, string $label): TextInput
    {
        return MoneyInput::make($name)->label($label)->suffix(null)->required(fn (Get $get): bool => ! self::linesGiven($get))
            ->minValue(-9999999999.99)->maxValue(9999999999.99)->rule('decimal:0,2');
    }

    /** @param class-string<Model> $model */
    private static function target(string $field, string $label, string $model, array $columns, Closure $format): Select
    {
        $query = fn (?FiscalDocument $document): Builder => $model::query()
            ->when($model === FiscalDocument::class && $document?->exists, fn (Builder $query) => $query->whereKeyNot($document->getKey()))
            ->when($model === Receivable::class, fn (Builder $query) => $query->with('billable'));

        $options = fn (Builder $query): array => $query->latest('id')->limit(25)->get()
            ->mapWithKeys(fn (Model $target): array => [$target->getKey() => $format($target)])->all();

        return Select::make($field)->label($label)->searchable()
            ->options(fn (?FiscalDocument $record): array => $options($query($record)))
            ->getSearchResultsUsing(fn (string $search, ?FiscalDocument $record): array => $options(
                $query($record)->where(fn (Builder $query) => $query
                    ->whereAny($columns, 'like', "%{$search}%")
                    ->when(ctype_digit($search), fn (Builder $query) => $query->orWhere('id', (int) $search))),
            ))
            ->getOptionLabelUsing(function (mixed $value, ?FiscalDocument $record) use ($query, $format): ?string {
                $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                $target = $id === false ? null : $query($record)->find($id);

                return $target === null ? null : $format($target);
            });
    }

    private static function legacyReference(?string $reference): string
    {
        return filled($reference) ? __(' · Previous reference pending confirmation: :reference', ['reference' => $reference]) : '';
    }
}
