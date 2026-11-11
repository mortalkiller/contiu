<?php

namespace App\Filament\Pages;

use App\Actions\ExportMonthCsv;
use App\Filament\MonthClose\Widgets\MonthCloseStats;
use App\Filament\MonthClose\Widgets\MonthExpensesTable;
use App\Filament\MonthClose\Widgets\MonthPaymentsTable;
use App\Filament\MonthClose\Widgets\MonthReceivablesTable;
use App\Filament\Widgets\CompanyAccountStats;
use App\Support\MonthReview;
use App\Support\Period;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Components\Attributes\Unexposed;
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
    private const MONTH_PATTERN = '/\A(?!0000)[0-9]{4}-(?:0[1-9]|1[0-2])\z/';

    protected static string|BackedEnum|null $navigationIcon = Phosphor::CalendarCheck;

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
        return Period::of($this->date()->year, $this->date()->month)->label();
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
                ->label(__('Bank statement'))
                ->icon(Phosphor::Bank)
                ->color('gray')
                ->visible(fn (): bool => BankStatementImport::canAccess())
                ->url(BankStatementImport::getUrl()),
            Action::make('previousMonth')
                ->label(__('Previous month'))
                ->icon(Phosphor::CaretLeft)
                ->color('gray')
                ->action(fn () => $this->month = $this->date()->subMonthNoOverflow()->format('Y-m')),
            Action::make('currentMonth')
                ->label(__('Current month'))
                ->color('gray')
                ->visible(fn () => $this->month !== today()->format('Y-m'))
                ->action(fn () => $this->month = today()->format('Y-m')),
            Action::make('nextMonth')
                ->label(__('Next month'))
                ->icon(Phosphor::CaretRight)
                ->iconPosition('after')
                ->color('gray')
                ->action(fn () => $this->month = $this->date()->addMonthNoOverflow()->format('Y-m')),
            Action::make('exportMonthCsv')
                ->label(__('Export CSV'))
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
                0 => $this->pending()['data'] === 0 ? __('Month closed') : __('Transactions processed'),
                default => trans_choice('{1} 1 item remaining|[2,*] :count items remaining', $pending),
            })
                ->description(fn (): string => $this->summary())
                ->icon(fn () => $this->pendingMovements() === 0 ? Phosphor::CheckCircle : Phosphor::ListChecks)
                ->status(fn (): string => $this->pendingMovements() === 0 ? 'success' : 'warning'),
            View::make('filament.pages.partials.month-close-steps')
                ->viewData(fn (): array => ['steps' => $this->steps()]),
            Callout::make(fn (): string => trans_choice('{1} 1 record with incomplete data|[2,*] :count records with incomplete data', $this->pending()['data']))
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
                ? __('All payments received, expenses confirmed and statement lines processed.')
                : __('All payments received and expenses confirmed.');
        }

        ['receivables' => $receivables, 'expenses' => $expenses, 'bank' => $bank] = $this->pending();

        return implode(' · ', array_filter([
            $receivables > 0 ? __(':count to receive', ['count' => $receivables]) : null,
            $expenses > 0 ? trans_choice('{1} :count unpaid expense|[2,*] :count unpaid expenses', $expenses) : null,
            $bank > 0 ? trans_choice('{1} :count statement line to process|[2,*] :count statement lines to process', $bank) : null,
        ]));
    }

    /**
     * The same counts as the callout, one step per kind of record, in the order they are usually
     * dealt with. The statement step only exists with a bank account for the activity.
     *
     * @return list<array{label: string, done: bool, state: string, url: string|null}>
     */
    private function steps(): array
    {
        ['receivables' => $receivables, 'expenses' => $expenses, 'bank' => $bank, 'data' => $data] = $this->pending();

        return [
            [
                'label' => __('Received'),
                'done' => $receivables === 0,
                'state' => $receivables === 0 ? __('All received') : __(':count to receive', ['count' => $receivables]),
                'url' => null,
            ],
            [
                'label' => __('Expenses'),
                'done' => $expenses === 0,
                'state' => $expenses === 0 ? __('All expenses paid') : __(':count to pay', ['count' => $expenses]),
                'url' => null,
            ],
            ...(BankStatementImport::canAccess() ? [[
                'label' => __('Statement'),
                'done' => $bank === 0,
                'state' => $bank === 0 ? __('Processed statement') : trans_choice('{1} :count line to process|[2,*] :count lines to process', $bank),
                'url' => BankStatementImport::getUrl(),
            ]] : []),
            [
                'label' => __('Recorded data'),
                'done' => $data === 0,
                'state' => $data === 0 ? __('All complete') : __(':count to complete', ['count' => $data]),
                'url' => null,
            ],
        ];
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

    #[Unexposed]
    public function getTitle(): string
    {
        return __('Month close');
    }

    #[Unexposed]
    public static function getNavigationLabel(): string
    {
        return __('Month close');
    }
}
