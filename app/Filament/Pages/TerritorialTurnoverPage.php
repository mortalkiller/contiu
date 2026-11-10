<?php

namespace App\Filament\Pages;

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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

class TerritorialTurnoverPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $title = 'Volume de negócios em território nacional';

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
                ->label('Confirmar cobertura')
                ->visible(fn (): bool => TerritorialTurnover::canConfirm()
                    && ($this->report()->coverage() === null || $this->report()->coverage()->through_on->lt($this->report()->through)))
                ->disabled(fn (): bool => $this->report()->unclassifiedCount() > 0)
                ->modalDescription(fn (): string => 'Confirma a cobertura das operações de '.$this->selectedYear()
                    .' até '.$this->report()->through->format('d/m/Y').', incluindo as que podem faltar no Contiu.')
                ->fillForm(fn (): array => ['revision' => $this->report()->revision(), 'year' => $this->selectedYear()])
                ->schema([
                    Hidden::make('revision'),
                    Hidden::make('year'),
                    Checkbox::make('complete')
                        ->label('Verifiquei que todas as operações e retificações do período estão registadas e revistas.')
                        ->rules(['accepted'])
                        ->validationMessages(['accepted' => 'Confirma que o registo está completo.']),
                ])
                ->action(function (array $data): void {
                    abort_unless((int) $data['year'] === $this->selectedYear(), 422);
                    $this->report()->confirmCoverage($data['revision']);
                    $this->reviewSaved('Cobertura confirmada');
                }),
            Action::make('revokeCoverage')
                ->label('Retirar confirmação de cobertura')
                ->color('gray')
                ->visible(fn (): bool => TerritorialTurnover::canConfirm() && $this->report()->coverage() !== null)
                ->requiresConfirmation()
                ->action(function (): void {
                    $this->report()->revokeCoverage();
                    $this->reviewSaved('Confirmação retirada');
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => DocumentedTurnover::billingDocuments()->whereIntegerInRaw('id', array_keys($this->report()->documents)))
            ->defaultSort('document_date', 'desc')
            ->columns([
                TextColumn::make('number')->label('Documento')->searchable()->description(fn (FiscalDocument $record): ?string => $record->party_name),
                TextColumn::make('type')->label('Tipo'),
                TextColumn::make('document_date')->label('Emissão')->date('d/m/Y')->sortable(),
                TextColumn::make('confirmed_operation_on')
                    ->label('Operação confirmada')
                    ->state(fn (FiscalDocument $record): ?string => ($this->report()->documents[$record->id]['review'] ?? null)?->operation_on->format('d/m/Y'))
                    ->placeholder('—'),
                TextColumn::make('net_amount')->label('Líquido')->money(fn (FiscalDocument $record): string => $record->currency, divideBy: 100, locale: 'pt_PT'),
                TextColumn::make('classification')
                    ->label('Classificação')
                    ->state(function (FiscalDocument $record): string {
                        $row = $this->report()->documents[$record->id] ?? null;
                        $review = $row['review'] ?? null;

                        return match (true) {
                            $row['stale'] ?? false => 'Rever alterações',
                            $review === null => 'Por classificar',
                            $review->eligible_cents === null => 'Excluído',
                            default => 'Incluído · '.Money::format($review->eligible_cents),
                        };
                    })
                    ->description(fn (FiscalDocument $record): ?string => ($this->report()->documents[$record->id]['credit_verified'] ?? true)
                        ? null : 'Validar fatura de origem e valor elegível'),
            ])
            ->recordActions([
                Action::make('openDocument')
                    ->label('Abrir documento')
                    ->color('gray')
                    ->visible(fn (FiscalDocument $record): bool => FiscalDocumentResource::canEdit($record))
                    ->url(fn (FiscalDocument $record): string => FiscalDocumentResource::getUrl('edit', ['record' => $record])),
                $this->classifyAction(),
            ])
            ->emptyStateHeading('Sem documentos para classificar');
    }

    private function classifyAction(): Action
    {
        return Action::make('classify')
            ->label('Classificar')
            ->visible(fn (): bool => TerritorialTurnover::canConfirm())
            ->modalDescription('Confirma a data da operação e o valor líquido que conta para o volume de negócios nacional, de acordo com os arts. 6.º, 52.º-A e 52.º-B do CIVA.')
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
                DatePicker::make('operation_on')->label('Data da operação')->required()->maxDate(today()),
                Select::make('classification')->label('Conta para o volume de negócios nacional?')
                    ->options(['included' => 'Sim, incluir valor elegível', 'excluded' => 'Não, excluir documento'])
                    ->required()->in(['included', 'excluded'])->live(),
                TextInput::make('eligible_amount')->label('Valor líquido elegível em euros')
                    ->inputMode('decimal')
                    ->visible(fn (Get $get): bool => $get('classification') === 'included')
                    ->required(fn (FiscalDocument $record): bool => $record->currency !== 'EUR')
                    ->regex('/\A[0-9]{1,10}(?:[,.][0-9]{1,2})?\z/')
                    ->helperText(fn (FiscalDocument $record): string => $record->currency === 'EUR'
                        ? 'Em branco, inclui o valor líquido integral. Indica um valor se só parte do documento for elegível.'
                        : 'Indica o valor elegível já convertido para EUR. Nas notas de crédito, introduz o valor sem sinal.'),
            ])
            ->action(function (FiscalDocument $record, array $data): void {
                $eligible = $data['classification'] === 'included'
                    ? (Money::toCents($data['eligible_amount'] ?? null) ?? abs($record->net_amount))
                    : null;

                $this->report()->confirmDocument($record->id, $data['revision'], $data['operation_on'], $eligible);
                $this->reviewSaved('Classificação guardada');
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
}
