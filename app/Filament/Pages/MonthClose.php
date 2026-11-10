<?php

namespace App\Filament\Pages;

use App\Actions\ExportMonthCsv;
use App\Filament\MonthClose\Widgets\MonthCloseStats;
use App\Filament\MonthClose\Widgets\MonthExpensesTable;
use App\Filament\MonthClose\Widgets\MonthPaymentsTable;
use App\Filament\MonthClose\Widgets\MonthReceivablesTable;
use App\Filament\Widgets\CompanyAccountStats;
use App\Support\MonthReview;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * "Fecho do mês": one screen per month with everything to deal with, so the month can be
 * reviewed line by line until nothing is left.
 */
class MonthClose extends Page
{
    private const MONTHS = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

    private const MONTH_PATTERN = '/\A(?!0000)[0-9]{4}-(?:0[1-9]|1[0-2])\z/';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::CalendarCheck;

    protected static ?string $navigationLabel = 'Fecho do mês';

    protected static ?string $title = 'Fecho do mês';

    protected static ?string $slug = 'fecho-do-mes';

    /** Second in the menu, after Hoje; also reached from the dashboard button and notifications. */
    protected static ?int $navigationSort = -2;

    #[Url]
    public string $month = '';

    private ?MonthReview $review = null;

    /** @var array{receivables: int, expenses: int, bank: int, data: int}|null */
    private ?array $pending = null;

    public function mount(): void
    {
        if (! preg_match(self::MONTH_PATTERN, $this->month)) {
            $this->month = today()->format('Y-m');
        }
    }

    public function getSubheading(): string
    {
        return self::MONTHS[$this->date()->month - 1].' '.$this->date()->year;
    }

    #[On('month-close-updated')]
    public function refreshSummary(): void
    {
        // Re-render the callout after something was treated in one of the tables.
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('bankStatement')
                ->label('Extrato bancário')
                ->icon(Phosphor::Bank)
                ->color('gray')
                ->visible(fn (): bool => BankStatementImport::canAccess())
                ->url(BankStatementImport::getUrl()),
            Action::make('previousMonth')
                ->label('Mês anterior')
                ->icon(Phosphor::CaretLeft)
                ->color('gray')
                ->action(fn () => $this->month = $this->date()->subMonthNoOverflow()->format('Y-m')),
            Action::make('currentMonth')
                ->label('Mês atual')
                ->color('gray')
                ->visible(fn () => $this->month !== today()->format('Y-m'))
                ->action(fn () => $this->month = today()->format('Y-m')),
            Action::make('nextMonth')
                ->label('Mês seguinte')
                ->icon(Phosphor::CaretRight)
                ->iconPosition('after')
                ->color('gray')
                ->action(fn () => $this->month = $this->date()->addMonthNoOverflow()->format('Y-m')),
            Action::make('exportMonthCsv')
                ->label('Exportar CSV')
                ->icon(Phosphor::FileCsv)
                ->action(fn (): StreamedResponse => app(ExportMonthCsv::class)->handle($this->review())),
        ];
    }

    /**
     * Filament builds and caches this schema once per request — before an action such as "Mês
     * seguinte" changes $month — so everything month-dependent is a closure evaluated at render.
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Callout::make(fn (): string => match ($pending = $this->pendingMovements()) {
                0 => $this->pending()['data'] === 0 ? 'Mês fechado' : 'Movimentos tratados',
                1 => 'Falta 1 item',
                default => 'Faltam '.$pending.' itens',
            })
                ->description(fn (): string => $this->summary())
                ->icon(fn () => $this->pendingMovements() === 0 ? Phosphor::CheckCircle : Phosphor::ListChecks)
                ->status(fn (): string => $this->pendingMovements() === 0 ? 'success' : 'warning'),
            Callout::make(fn (): string => $this->pending()['data'] === 1
                ? '1 registo com dados por completar'
                : $this->pending()['data'].' registos com dados por completar')
                ->icon(Phosphor::ListChecks)
                ->status('warning')
                ->visible(fn (): bool => $this->pending()['data'] > 0),
            ...$this->getWidgetsSchemaComponents([
                MonthCloseStats::class,
                CompanyAccountStats::class,
                MonthReceivablesTable::class,
                MonthPaymentsTable::class,
                MonthExpensesTable::class,
            ]),
        ]);
    }

    /** What is left to close the month; the statement only with a bank account for the activity. */
    private function summary(): string
    {
        $withStatement = BankStatementImport::canAccess();

        if ($this->pendingMovements() === 0) {
            return $withStatement
                ? 'Tudo recebido, todas as despesas confirmadas e o extrato tratado.'
                : 'Tudo recebido e todas as despesas confirmadas.';
        }

        ['receivables' => $receivables, 'expenses' => $expenses, 'bank' => $bank] = $this->pending();

        return implode(' · ', array_filter([
            $receivables > 0 ? $receivables.' por receber' : null,
            $expenses > 0 ? $expenses.($expenses === 1 ? ' despesa por pagar' : ' despesas por pagar') : null,
            $bank > 0 ? $bank.($bank === 1 ? ' linha do extrato por tratar' : ' linhas do extrato por tratar') : null,
        ]));
    }

    private function pendingMovements(): int
    {
        ['receivables' => $receivables, 'expenses' => $expenses, 'bank' => $bank] = $this->pending();

        return $receivables + $expenses + $bank;
    }

    /** @return array{receivables: int, expenses: int, bank: int, data: int} */
    private function pending(): array
    {
        $review = $this->review();

        return $this->pending ??= [
            'receivables' => $review->pendingReceivables(),
            'expenses' => $review->pendingExpenses(),
            'bank' => $review->pendingBankLines(),
            'data' => $review->pendingDataCount(),
        ];
    }

    /** Read lazily by the widgets' Livewire data closure, so they always get the current month. */
    public function getWidgetData(): array
    {
        return ['month' => $this->month];
    }

    private function review(): MonthReview
    {
        if ($this->review === null || $this->review->start->format('Y-m') !== $this->month) {
            $this->review = new MonthReview($this->date());
            $this->pending = null;
        }

        return $this->review;
    }

    private function date(): Carbon
    {
        abort_unless(preg_match(self::MONTH_PATTERN, $this->month), 422);

        return Carbon::createFromFormat('Y-m-d', $this->month.'-01')->startOfDay();
    }
}
