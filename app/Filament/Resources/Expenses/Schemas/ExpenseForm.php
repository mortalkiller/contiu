<?php

namespace App\Filament\Resources\Expenses\Schemas;

use App\Enums\ExpenseAttribution;
use App\Enums\ExpenseKind;
use App\Enums\Language;
use App\Enums\PaymentMethod;
use App\Filament\Support\FinanceFields;
use App\Filament\Support\ReconciliationColumns;
use App\Filament\Support\TaxSettingsFields;
use App\Models\Expense;
use App\Models\Supplier;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class ExpenseForm
{
    public static function configure(Schema $schema, bool $withEvidence = false): Schema
    {
        return $schema
            ->defaultDateDisplayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
            ->defaultNumberLocale(fn (): string => app()->getLocale())
            ->columns(3)
            ->components([
                Section::make(__('Expense'))
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        FinanceFields::supplierSelect()
                            ->live(),
                        Select::make('category_id')
                            ->label(__('Category'))
                            ->relationship('category', 'name', fn (Builder $query) => $query->expense())
                            ->native(false),
                        TextInput::make('description')
                            ->label(__('Description'))
                            ->placeholder(__('ChatGPT Pro, OVH server…'))
                            ->required(),
                        DatePicker::make('spent_on')
                            ->label(__('Date'))
                            ->native(false)
                            ->displayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
                            ->default(today())
                            ->required(),
                        // Services bought abroad are self-assessed (autoliquidação); goods are not.
                        TaxSettingsFields::whereToFind(
                            Toggle::make('is_goods')
                                ->label(__('Is this a purchase of goods (physical items)?'))
                                ->helperText(__('When off, this is a service (software, hosting, advertising…). If the invoice has no VAT, account for it under the reverse charge.'))
                                ->visible(fn (Get $get): bool => (bool) Supplier::find($get('supplier_id'))?->seat?->isForeign())
                                ->columnSpanFull(),
                            __('Goods are physical items (such as a laptop or monitor). Software, hosting, advertising and subscriptions are services.'),
                        ),
                    ]),

                Section::make(__('Amount'))
                    ->columnSpan(1)
                    ->schema([
                        FinanceFields::netAmount(),
                        FinanceFields::vatRate(),
                        FinanceFields::totalPreview(),
                    ]),

                ...static::classificationAndPayment(),
                ...($withEvidence ? [self::evidenceSection()] : []),
            ]);
    }

    private static function evidenceSection(): Section
    {
        return Section::make(__('Supporting document and allocation'))
            ->columnSpanFull()
            ->columns(2)
            ->schema([
                FileUpload::make('proof_upload')
                    ->label(fn (?Expense $record): string => $record?->hasProof() ? __('Replace supporting PDF') : __('Supporting PDF'))
                    ->acceptedFileTypes(['application/pdf'])
                    ->maxSize(10240)
                    ->maxFiles(1)
                    ->storeFiles(false)
                    ->preventFilePathTampering()
                    ->getUploadedFileUsing(null)
                    ->previewable(false),
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
                Toggle::make('remove_proof')
                    ->label(__('Remove current supporting document'))
                    ->visible(fn (?Expense $record): bool => $record?->hasProof() ?? false),
            ]);
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
                ->schema([
                    Radio::make('kind')
                        ->label(__('Cost type'))
                        ->options(ExpenseKind::class)
                        ->default(ExpenseKind::Overhead)
                        ->columnSpanFull()
                        ->live()
                        ->required()
                        ->afterStateUpdated(function (Set $set, $state): void {
                            if (static::kind($state) !== ExpenseKind::Direct) {
                                $set('project_id', null);
                                $set('retainer_id', null);
                            }
                        }),
                    Select::make('project_id')
                        ->label(__('Project'))
                        ->relationship('project', 'code')
                        ->searchable()
                        ->preload()
                        ->live()
                        ->visible(fn (Get $get) => static::kind($get('kind')) === ExpenseKind::Direct)
                        ->disabled(fn (Get $get) => filled($get('retainer_id')))
                        ->required(fn (Get $get) => static::kind($get('kind')) === ExpenseKind::Direct && blank($get('retainer_id')))
                        ->helperText(__('Choose either the project or the retainer this cost belongs to.')),
                    Select::make('retainer_id')
                        ->label(__('Retainer'))
                        ->relationship('retainer', 'code')
                        ->searchable()
                        ->preload()
                        ->live()
                        ->visible(fn (Get $get) => static::kind($get('kind')) === ExpenseKind::Direct)
                        ->disabled(fn (Get $get) => filled($get('project_id')))
                        ->required(fn (Get $get) => static::kind($get('kind')) === ExpenseKind::Direct && blank($get('project_id'))),
                ]),

            Section::make(__('Payment'))
                ->columnSpan(1)
                ->schema(array_values(array_filter([
                    $withPaymentStatus ? Toggle::make('is_paid')
                        ->label(__('Already paid'))
                        ->default(true)
                        ->live() : null,
                    $withPaymentStatus ? DatePicker::make('paid_on')
                        ->label(__('Paid on'))
                        ->native(false)
                        ->displayFormat(fn (): string => Language::resolve(app()->getLocale())->dateFormat())
                        ->default(today())
                        ->visible(fn (Get $get) => (bool) $get('is_paid')) : null,
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
