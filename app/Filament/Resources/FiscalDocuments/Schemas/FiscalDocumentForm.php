<?php

namespace App\Filament\Resources\FiscalDocuments\Schemas;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
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
        return $schema->components([
            Section::make('Documento')
                ->schema([
                    Select::make('type')->label('Tipo')->options(FiscalDocumentType::class)->required(),
                    Select::make('direction')->label('Sentido')->options(FiscalDocumentDirection::class)->required(),
                    TextInput::make('number')->label('Número')->required()->maxLength(255),
                    TextInput::make('atcud')->label('ATCUD')->disabled()
                        ->visible(fn (?FiscalDocument $record): bool => filled($record?->atcud)),
                    TextInput::make('origin')->label('Origem')->required()->maxLength(255)->default('Registo manual'),
                    DatePicker::make('document_date')->label('Data do documento')->required(),
                    DatePicker::make('operation_date')->label('Data da operação'),
                    Select::make('status')->label('Estado')->options(FiscalDocumentStatus::class)
                        ->default(FiscalDocumentStatus::Active->value)->required(),
                    FileUpload::make('pdf_upload')
                        ->label(fn (?FiscalDocument $record): string => $record?->currentPdf ? 'Substituir PDF' : 'PDF do documento')
                        ->hidden(fn (?FiscalDocument $record, string $operation): bool => $operation === 'view' || $record?->invoiceXpressSnapshot !== null)
                        ->acceptedFileTypes(['application/pdf'])
                        ->maxSize(FiscalDocumentPdfs::MAX_KIB)
                        ->storeFiles(false)
                        ->preventFilePathTampering()
                        ->getUploadedFileUsing(null)
                        ->previewable(false),
                ])->columns(2)->columnSpanFull(),
            Section::make('Montantes')
                ->schema([
                    TextInput::make('currency')->label('Moeda')->required()->default('EUR')
                        ->length(3)->regex('/^[A-Za-z]{3}$/')
                        ->dehydrateStateUsing(fn (string $state): string => strtoupper($state)),
                    self::amount('net_amount', 'Base tributável'),
                    self::amount('vat_amount', 'IVA'),
                    self::amount('total_amount', 'Total')->rule(fn (Get $get): Closure => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                        $net = $get('net_amount');
                        $vat = $get('vat_amount');

                        if (is_numeric($net) && is_numeric($vat) && is_numeric($value)
                            && Money::toCents($value) !== Money::toCents($net) + Money::toCents($vat)) {
                            $fail('O total deve ser igual à soma da base tributável e do IVA.');
                        }
                    }),
                ])->columns(4)->columnSpanFull(),
            Section::make('Contraparte')
                ->schema([
                    TextInput::make('party_name')->label('Nome no documento')->maxLength(255),
                    TextInput::make('party_tax_id')->label('Identificação fiscal no documento')->maxLength(64),
                ])->columns(2)->columnSpanFull(),
            Section::make('Ligações')
                ->schema([
                    self::target('receivable_id', 'Valor a receber', Receivable::class, ['description'],
                        fn (Receivable $record): string => "#{$record->id} · {$record->label}"),
                    self::target('payment_id', 'Recebimento', Payment::class, ['invoice_number'],
                        fn (Payment $record): string => "#{$record->id} · {$record->paid_on->format('d/m/Y')} · ".Money::format($record->amount)
                            .self::legacyReference($record->invoice_number)),
                    self::target('expense_id', 'Despesa', Expense::class, ['description', 'invoice_number'],
                        fn (Expense $record): string => "#{$record->id} · {$record->description}".self::legacyReference($record->invoice_number)),
                    self::target('related_fiscal_document_id', 'Documento relacionado', FiscalDocument::class, ['number'],
                        fn (FiscalDocument $record): string => "{$record->type->getLabel()} · {$record->number} · {$record->document_date->format('d/m/Y')}"),
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
        return filled($reference) ? " · Referência anterior por confirmar: {$reference}" : '';
    }
}
