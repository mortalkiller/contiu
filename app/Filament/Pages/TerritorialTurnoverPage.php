<?php

namespace App\Filament\Pages;

use App\Enums\Language;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Models\FiscalDocument;
use App\Support\DocumentedTurnover;
use App\Support\Money;
use App\Support\TerritorialTurnover;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Components\Attributes\Unexposed;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class TerritorialTurnoverPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'volume-de-negocios-nacional';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.territorial-turnover-page';

    #[Url]
    public ?int $year = null;

    private ?TerritorialTurnover $report = null;

    public static function canAccess(): bool
    {
        return TerritorialTurnover::canReview();
    }

    public function mount(): void
    {
        $this->year = $this->selectedYear();
    }

    public function updatedYear(): void
    {
        $this->year = $this->selectedYear();
        $this->report = null;
        $this->resetTable();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('confirmCoverage')
                ->label(__('Confirm coverage'))
                ->visible(fn (): bool => TerritorialTurnover::canConfirm()
                    && ($this->report()->coverage() === null || $this->report()->coverage()->through_on->lt($this->report()->through)))
                ->disabled(fn (): bool => $this->report()->unclassifiedCount() > 0)
                ->modalDescription(fn (): string => __('Confirm coverage of transactions for :year through :date, including any missing from Contiu.', ['year' => $this->selectedYear(), 'date' => $this->report()->through->format(Language::resolve(app()->getLocale())->dateFormat())]))
                ->fillForm(fn (): array => ['revision' => $this->report()->revision(), 'year' => $this->selectedYear()])
                ->schema([
                    Hidden::make('revision'),
                    Hidden::make('year'),
                    Checkbox::make('complete')
                        ->label(__('I verified that all transactions and corrections for the period are recorded and reviewed.'))
                        ->rules(['accepted'])
                        ->validationMessages(['accepted' => __('Confirm that the record is complete.')]),
                ])
                ->action(function (array $data): void {
                    abort_unless((int) $data['year'] === $this->selectedYear(), 422);
                    $this->report()->confirmCoverage($data['revision']);
                    $this->reviewSaved(__('Coverage confirmed'));
                }),
            Action::make('revokeCoverage')
                ->label(__('Withdraw coverage confirmation'))
                ->color('gray')
                ->visible(fn (): bool => TerritorialTurnover::canConfirm() && $this->report()->coverage() !== null)
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->report()->revokeCoverage();
                    $this->reviewSaved(__('Confirmation withdrawn'));
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => DocumentedTurnover::billingDocuments()->whereIntegerInRaw('id', array_keys($this->report()->documents)))
            ->defaultSort('document_date', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('Document'))->searchable()->description(fn (FiscalDocument $record): ?string => $record->party_name),
                TextColumn::make('type')->label(__('Type')),
                TextColumn::make('document_date')->label(__('Issue date'))->date(fn (): string => Language::resolve(app()->getLocale())->dateFormat())->sortable(),
                TextColumn::make('confirmed_operation_on')
                    ->label(__('Confirmed transaction'))
                    ->state(fn (FiscalDocument $record): ?string => ($this->report()->documents[$record->id]['review'] ?? null)?->operation_on->format(Language::resolve(app()->getLocale())->dateFormat()))
                    ->placeholder('—'),
                TextColumn::make('net_amount')->label(__('Net'))->money(fn (FiscalDocument $record): string => $record->currency, divideBy: 100, locale: app()->getLocale()),
                TextColumn::make('classification')
                    ->label(__('Classification'))
                    ->state(function (FiscalDocument $record): string {
                        $row = $this->report()->documents[$record->id] ?? null;
                        $review = $row['review'] ?? null;

                        return match (true) {
                            $row['stale'] ?? false => __('Review changes'),
                            $review === null => __('Awaiting classification'),
                            $review->eligible_cents === null => __('Excluded'),
                            default => __('Included · :amount', ['amount' => Money::format($review->eligible_cents, locale: app()->getLocale())]),
                        };
                    })
                    ->description(fn (FiscalDocument $record): ?string => ($this->report()->documents[$record->id]['credit_verified'] ?? true)
                        ? null : __('Validate source invoice and eligible amount')),
            ])
            ->recordActions([
                Action::make('openDocument')
                    ->label(__('Open document'))
                    ->color('gray')
                    ->visible(fn (FiscalDocument $record): bool => FiscalDocumentResource::canEdit($record))
                    ->url(fn (FiscalDocument $record): string => FiscalDocumentResource::getUrl('edit', ['record' => $record])),
                $this->classifyAction(),
            ])
            ->emptyStateHeading(__('No documents to classify'));
    }

    private function classifyAction(): Action
    {
        return Action::make('classify')
            ->label(__('Classify'))
            ->visible(fn (): bool => TerritorialTurnover::canConfirm())
            ->modalDescription(__('Confirm the transaction date and the net amount that counts toward domestic turnover under Articles 6, 52-A and 52-B of the Portuguese VAT Code.'))
            ->fillForm(function (FiscalDocument $record): array {
                $row = $this->report()->documents[$record->id];
                $review = $row['review'];

                return [
                    'revision' => $row['revision'],
                    'operation_on' => ($review?->operation_on ?? $record->operation_date)?->toDateString(),
                    'classification' => $review === null ? null : ($review->eligible_cents === null ? 'excluded' : 'included'),
                    'eligible_amount' => Money::toEuros($review?->eligible_cents),
                ];
            })
            ->schema([
                Hidden::make('revision'),
                DatePicker::make('operation_on')->label(__('Transaction date'))->required()->maxDate(today()),
                Select::make('classification')->label(__('Does this count toward domestic turnover?'))
                    ->options(['included' => __('Yes, include the eligible amount'), 'excluded' => __('No, exclude the document')])
                    ->required()->in(['included', 'excluded'])->live(),
                TextInput::make('eligible_amount')->label(__('Eligible net amount in euros'))
                    ->inputMode('decimal')
                    ->visible(fn (Get $get): bool => $get('classification') === 'included')
                    ->required(fn (FiscalDocument $record): bool => $record->currency !== 'EUR')
                    ->regex('/\A[0-9]{1,10}(?:[,.][0-9]{1,2})?\z/')
                    ->helperText(fn (FiscalDocument $record): string => $record->currency === 'EUR'
                        ? __('Leave blank to include the full net amount. Enter an amount if only part of the document is eligible.')
                        : __('Enter the eligible amount already converted to EUR. For credit notes, enter the amount without a sign.')),
            ])
            ->action(function (FiscalDocument $record, array $data): void {
                $eligible = $data['classification'] === 'included'
                    ? (Money::toCents($data['eligible_amount'] ?? null) ?? abs($record->net_amount))
                    : null;

                $this->report()->confirmDocument($record->id, $data['revision'], $data['operation_on'], $eligible);
                $this->reviewSaved(__('Classification saved'));
            });
    }

    protected function getViewData(): array
    {
        $year = $this->selectedYear();

        return [
            'report' => $this->report(),
            'years' => collect([today()->year, today()->year - 1, $year])
                ->merge(DocumentedTurnover::issueYears())->merge(TerritorialTurnover::reviewYears())
                ->filter(fn (int $year): bool => $year >= 1900 && $year <= today()->year)
                ->unique()->sortDesc(),
        ];
    }

    private function report(): TerritorialTurnover
    {
        abort_unless(static::canAccess(), 403);

        return $this->report ??= TerritorialTurnover::forYear($this->selectedYear());
    }

    private function selectedYear(): int
    {
        return $this->year >= 1900 && $this->year <= today()->year ? $this->year : today()->year;
    }

    private function reviewSaved(string $title): void
    {
        $this->report = null;
        $this->flushCachedTableRecords();
        Notification::make()->title($title)->success()->send();
    }

    #[Unexposed]
    public function getTitle(): string
    {
        return __('Domestic turnover');
    }

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Domestic turnover');
    }
}
