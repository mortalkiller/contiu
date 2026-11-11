<?php

namespace App\Filament\Resources\FiscalDocuments\Schemas;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Enums\Language;
use App\Filament\Support\MoneyInput;
use App\Models\Expense;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLink;
use App\Models\Payment;
use App\Models\Receivable;
use App\Support\FiscalDocumentPdfs;
use App\Support\Money;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

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
                        FileUpload::make('pdf_upload')
                            ->label(fn (?FiscalDocument $record): string => $record?->currentPdf ? __('Replace PDF') : __('Document PDF'))
                            ->hidden(fn (?FiscalDocument $record, string $operation): bool => $operation === 'view' || $record?->invoiceXpressSnapshot !== null)
                            ->acceptedFileTypes(['application/pdf'])
                            ->maxSize(FiscalDocumentPdfs::MAX_KIB)
                            ->storeFiles(false)
                            ->preventFilePathTampering()
                            ->getUploadedFileUsing(null)
                            ->previewable(false),
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

                            if (is_numeric($net) && is_numeric($vat) && is_numeric($value)
                                && Money::toCents($value) !== Money::toCents($net) + Money::toCents($vat)) {
                                $fail(__('The total must equal the taxable base plus VAT.'));
                            }
                        }),
                    ])->columns(4)->columnSpanFull(),
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

    private static function amount(string $name, string $label): TextInput
    {
        return MoneyInput::make($name)->label($label)->suffix(null)->required()
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
