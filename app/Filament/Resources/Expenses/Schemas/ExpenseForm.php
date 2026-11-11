<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Enums\CategoryType;
use App\Enums\ExpenseAttribution;
use App\Enums\ExpenseKind;
use App\Enums\Language;
use App\Enums\PaymentMethod;
use App\Enums\Seat;
use App\Filament\Support\FinanceFields;
use App\Filament\Support\QuickCreate;
use App\Filament\Support\ReconciliationColumns;
use App\Filament\Support\TaxSettingsFields;
use App\Models\Expense;
use App\Models\Supplier;
use App\Support\Money;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;
use ToneGabes\Filament\Icons\Enums\Phosphor;

class ExpenseForm
{
    /**
     * As in the mock ("Página de formulário"): one column of sections, in the order the expense
     * is told (what was bought, how much it cost, what it was for, how it was paid, its
     * supporting document), and a summary beside them that follows what is typed and says what
     * is still missing before it can be saved.
     */
    public static function configure(Schema $schema, bool $withEvidence = false): Schema
    {
        return $schema
            ->defaultDateDisplayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
            ->defaultNumberLocale(fn (): string => app()->getLocale())
            ->columns(['default' => 1, 'lg' => 3])
            ->components([
                Group::make([
                    Section::make(__('What you bought'))
                        ->columns(2)
                        ->schema([
                            FinanceFields::supplierSelect(),
                            DatePicker::make('spent_on')
                                ->label(__('Date'))
                                ->native(false)
                                ->displayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
                                ->default(today())
                                ->required(),
                            TextInput::make('description')
                                ->label(__('Description'))
                                ->placeholder(__('ChatGPT Pro, OVH server…'))
                                ->required(),
                            QuickCreate::category(Select::make('category_id')
                                ->label(__('Category'))
                                ->relationship('category', 'name', fn (Builder $query) => $query->expense())
                                ->native(false), CategoryType::Expense),
                            // Services bought abroad are self-assessed (autoliquidação); goods are not.
                            TaxSettingsFields::whereToFind(
                                Toggle::make('is_goods')
                                    ->label(__('Is this a purchase of goods (physical items)?'))
                                    ->helperText(__('When off, this is a service (software, hosting, advertising…). If the invoice has no VAT, account for it under the reverse charge.'))
                                    ->visibleJs(fn (): string => Js::from(Supplier::query()
                                        ->whereIn('seat', [Seat::EuropeanUnion->value, Seat::OutsideEu->value])
                                        ->pluck('id')->map(static fn (int $id): string => (string) $id)->all())
                                        .".includes(String(\$get('supplier_id')))")
                                    ->dehydrated(fn (Get $get): bool => filled($get('supplier_id'))
                                        && (bool) Supplier::find($get('supplier_id'))?->seat?->isForeign())
                                    ->columnSpanFull(),
                                __('Goods are physical items (such as a laptop or monitor). Software, hosting, advertising and subscriptions are services.'),
                            ),
                        ]),

                    Section::make(__('How much it cost'))
                        ->columns(['default' => 1, 'sm' => 3])
                        ->schema([
                            FinanceFields::netAmount(),
                            FinanceFields::vatRate(),
                            FinanceFields::totalPreview()->alignEnd(),
                        ]),

                    Section::make(__('What it was for'))
                        ->columns(2)
                        ->schema(static::kindFields()),

                    Section::make(__('Payment'))
                        // "Já está paga" sits in the section's header, as in the mock.
                        ->afterHeader([
                            Toggle::make('is_paid')
                                ->label(__('Already paid'))
                                ->default(true),
                        ])
                        ->columns(['default' => 1, 'sm' => 3])
                        ->schema([
                            DatePicker::make('paid_on')
                                ->label(__('Paid on'))
                                ->native(false)
                                ->displayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
                                ->default(today())
                                ->visibleJs(<<<'JS'
                    !! $get('is_paid')
                    JS),
                            ToggleButtons::make('method')
                                ->label(__('Method'))
                                ->options(PaymentMethod::class)
                                ->inline()
                                ->columnSpan(['default' => 1, 'sm' => 2]),
                        ]),

                    Section::make(__('Supporting document'))
                        ->afterHeader([Text::make(__('Optional'))->badge()->color('gray')])
                        ->columns(2)
                        ->schema([
                            ...($withEvidence ? self::evidenceFields() : []),
                            TextInput::make('invoice_number')
                                ->label(__('Supplier invoice no.'))
                                ->placeholder(__('E.g. FT 2026/12'))
                                ->maxLength(100),
                            Textarea::make('notes')
                                ->label(__('Notes'))
                                ->rows(2)
                                ->columnSpanFull(),
                        ]),

                    ReconciliationColumns::section(),
                ])->columnSpan(['default' => 1, 'lg' => 2]),

                static::summarySection(),
            ]);
    }

    /**
     * "Resumo", beside the form and in view while scrolling: the amounts as typed, the kind and
     * whether it is paid, and what is still missing to save it.
     */
    private static function summarySection(): Section
    {
        $net = fn (Get $get): int => Money::toCents($get('net_amount')) ?? 0;
        $vat = fn (Get $get): int => Money::vat($net($get), (string) ($get('vat_rate') ?? 0));

        return Section::make(__('Summary'))
            ->columnSpan(1)
            ->inlineLabel()
            // Key/value lines as in the mock (filament-appearance); stays in view on wide screens.
            ->extraAttributes(['class' => 'fap-key-value lg:sticky lg:top-20'])
            ->schema([
                TextEntry::make('summary_net')
                    ->label(__('Amount excluding VAT'))
                    ->state(fn (Get $get): HtmlString => FinanceFields::preview('net', $net($get))),
                TextEntry::make('summary_vat')
                    ->label(fn (Get $get): HtmlString => FinanceFields::preview(
                        'vatLabel',
                        __('VAT at :rate%', ['rate' => (int) round((float) ($get('vat_rate') ?? 0) * 100)]),
                        ['rate' => __('VAT at :rate%')],
                    ))
                    ->state(fn (Get $get): HtmlString => FinanceFields::preview('vat', $vat($get))),
                TextEntry::make('summary_total')
                    ->label(__('Total'))
                    ->state(fn (Get $get): HtmlString => FinanceFields::preview('total', $net($get) + $vat($get)))
                    ->weight(FontWeight::SemiBold),
                Flex::make([
                    TextEntry::make('summary_kind')
                        ->hiddenLabel()
                        ->state(fn (Get $get): HtmlString => FinanceFields::preview(
                            'expenseKind',
                            static::kind($get('kind'))?->getLabel() ?? '',
                            [
                                ExpenseKind::Direct->value => ExpenseKind::Direct->getLabel(),
                                ExpenseKind::Overhead->value => ExpenseKind::Overhead->getLabel(),
                            ],
                        )),
                    TextEntry::make('summary_paid')
                        ->hiddenLabel()
                        ->state(fn (Get $get): HtmlString => FinanceFields::preview(
                            'expensePaid',
                            $get('is_paid')
                                ? (filled($get('paid_on')) ? __('Expense paid on :date', ['date' => Carbon::parse($get('paid_on'))->format(Language::resolve(app()->getLocale())->dateFormat())]) : __('Expense paid'))
                                : __('Unpaid'),
                            ['paid' => __('Expense paid'), 'dated' => __('Expense paid on :date'), 'unpaid' => __('Unpaid')],
                        )),
                ])->inlineLabel(false),
                Callout::make(__('Choose the supplier to be able to save.'))
                    ->status('warning')
                    ->icon(Phosphor::Warning)
                    ->visibleJs(<<<'JS'
                        ! $get('supplier_id')
                        JS),
            ]);
    }

    /**
     * The supporting PDF and the bookkeeping details of an expense already recorded.
     *
     * @return list<Component>
     */
    private static function evidenceFields(): array
    {
        return [
            FileUpload::make('proof_upload')
                ->label(fn (?Expense $record): string => $record?->hasProof() ? __('Replace supporting PDF') : __('Supporting PDF'))
                ->helperText(__('You can attach it later. It shows as missing in the list until then.'))
                ->acceptedFileTypes(['application/pdf'])
                ->maxSize(10240)
                ->maxFiles(1)
                ->storeFiles(false)
                ->preventFilePathTampering()
                ->getUploadedFileUsing(null)
                ->previewable(false)
                ->columnSpanFull(),
            Toggle::make('remove_proof')
                ->label(__('Remove current supporting document'))
                ->visible(fn (?Expense $record): bool => $record?->hasProof() ?? false)
                ->columnSpanFull(),
            TextInput::make('proof_reference')
                ->label(__('Supporting document reference'))
                ->maxLength(255),
            Select::make('attribution')
                ->label(__('Allocation'))
                ->options(ExpenseAttribution::class)
                ->native(false),
            Toggle::make('is_reviewed')
                ->label(__('Reviewed'))
                ->default(false),
        ];
    }

    /**
     * Overhead or direct, and for a direct cost the project or retainer it belongs to.
     *
     * @return list<Component>
     */
    private static function kindFields(): array
    {
        return [
            Radio::make('kind')
                ->label(__('Cost type'))
                ->hiddenLabel()
                ->options(ExpenseKind::class)
                ->default(ExpenseKind::Overhead)
                // As in the mock: one card per kind, with what it means (filament-appearance).
                ->columns(['default' => 1, 'sm' => 2])
                ->extraAttributes(['class' => 'fap-radio-cards'])
                ->columnSpanFull()
                ->required()
                ->afterStateUpdatedJs(<<<'JS'
                    if ($state !== 'direct') {
                        $set('project_id', null)
                        $set('retainer_id', null)
                    }
                    JS),
            Select::make('project_id')
                ->label(__('Project'))
                ->relationship('project', 'code')
                ->searchable()
                ->preload()
                ->afterStateUpdatedJs(<<<'JS'
                    if ($state) $set('retainer_id', null)
                    JS)
                ->visibleJs(<<<'JS'
                    $get('kind') === 'direct'
                    JS)
                ->prohibits('retainer_id')
                ->required(fn (Get $get) => static::kind($get('kind')) === ExpenseKind::Direct && blank($get('retainer_id')))
                ->helperText(__('Choose either the project or the retainer this cost belongs to.')),
            Select::make('retainer_id')
                ->label(__('Retainer'))
                ->relationship('retainer', 'code')
                ->searchable()
                ->preload()
                ->afterStateUpdatedJs(<<<'JS'
                    if ($state) $set('project_id', null)
                    JS)
                ->visibleJs(<<<'JS'
                    $get('kind') === 'direct'
                    JS)
                ->prohibits('project_id')
                ->required(fn (Get $get) => static::kind($get('kind')) === ExpenseKind::Direct && blank($get('project_id'))),
        ];
    }

    /**
     * Shared with the recurring expense form.
     *
     * @return array<int, Section>
     */
    public static function classificationAndPayment(bool $withPaymentStatus = true): array
    {
        return [
            Section::make(__('Classification'))
                ->columnSpan(2)
                ->columns(2)
                ->schema(static::kindFields()),

            Section::make(__('Payment'))
                ->columnSpan(1)
                ->schema(array_values(array_filter([
                    $withPaymentStatus ? Toggle::make('is_paid')
                        ->label(__('Already paid'))
                        ->default(true) : null,
                    $withPaymentStatus ? DatePicker::make('paid_on')
                        ->label(__('Paid on'))
                        ->native(false)
                        ->displayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
                        ->default(today())
                        ->visibleJs(<<<'JS'
                            !! $get('is_paid')
                            JS) : null,
                    ToggleButtons::make('method')
                        ->label(__('Method'))
                        ->options(PaymentMethod::class)
                        ->inline(),
                    $withPaymentStatus ? TextInput::make('invoice_number')
                        ->label(__('Supplier invoice no.'))
                        ->maxLength(100) : null,
                    $withPaymentStatus ? Textarea::make('notes')
                        ->label(__('Notes'))
                        ->rows(2) : null,
                ]))),
            ...($withPaymentStatus ? [ReconciliationColumns::section()] : []),
        ];
    }

    public static function kind(mixed $state): ?ExpenseKind
    {
        return $state instanceof ExpenseKind ? $state : ExpenseKind::tryFrom((string) $state);
    }

    /**
     * Overhead never keeps a cost centre, even if a hidden field still holds one.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalize(array $data): array
    {
        if (static::kind($data['kind'] ?? null) !== ExpenseKind::Direct) {
            $data['project_id'] = null;
            $data['retainer_id'] = null;
        }

        if (array_key_exists('is_paid', $data) && ! $data['is_paid']) {
            $data['paid_on'] = null;
        }

        return $data;
    }
}
