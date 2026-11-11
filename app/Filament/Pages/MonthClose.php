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

    /**
     * "Novembro 2026. Faltam 3 itens para fechar.", as in the mock; once every transaction is
     * dealt with, how many records still miss data, or that the month is closed.
     */
    public function getSubheading(): string
    {
        $month = Period::of($this->date()->year, $this->date()->month)->label();
        $data = $this->pending()['data'];

        return $month.'. '.match (true) {
            $this->pendingMovements() > 0 => trans_choice('{1} 1 item left to close.|[2,*] :count items left to close.', $this->pendingMovements()),
            $data > 0 => __('Transactions processed').' · '.trans_choice('{1} 1 record with incomplete data|[2,*] :count records with incomplete data', $data).'.',
            default => __('Month closed').'.',
        };
    }

    #[On('month-close-updated')]
    public function refreshSummary(): void
    {
        // Re-render the subheading and the steps after something was treated in one of the tables.
    }

    /**
     * As in the mock: the previous and next month as arrows around "Mês atual", and the CSV
     * export. The statement is reached from its step.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('previousMonth')
                ->label(__('Previous month'))
                ->icon(Phosphor::CaretLeft)
                ->iconButton()
                ->tooltip(__('Previous month'))
                ->color('gray')
                ->action(fn () => $this->month = $this->date()->subMonthNoOverflow()->format('Y-m')),
            Action::make('currentMonth')
                ->label(__('Current month'))
                ->color('gray')
                ->disabled(fn (): bool => $this->month === today()->format('Y-m'))
                ->action(fn () => $this->month = today()->format('Y-m')),
            Action::make('nextMonth')
                ->label(__('Next month'))
                ->icon(Phosphor::CaretRight)
                ->iconButton()
                ->tooltip(__('Next month'))
                ->color('gray')
                ->action(fn () => $this->month = $this->date()->addMonthNoOverflow()->format('Y-m')),
            Action::make('exportMonthCsv')
                ->label(__('Export CSV'))
                ->icon(Phosphor::DownloadSimple)
                ->color('gray')
                ->action(fn (): StreamedResponse => app(ExportMonthCsv::class)->handle($this->review())),
        ];
    }

    /**
     * Filament builds and caches this schema once per request — before an action such as "Mês
     * seguinte" changes $month — so everything month-dependent is a closure evaluated at render.
     */
    public function content(Schema $schema): Schema
    {
        // As in the mock: the steps, the month's figures, then what is to receive and to pay;
        // also what came in (for its receipts) and, last, the company account at the month's end.
        return $schema->components([
            View::make('filament.pages.partials.steps')
                ->viewData(fn (): array => ['heading' => __('Still to do before closing'), 'steps' => $this->steps()]),
            ...$this->getWidgetsSchemaComponents([
                MonthCloseStats::class,
                MonthReceivablesTable::class,
                MonthPaymentsTable::class,
                MonthExpensesTable::class,
                CompanyAccountStats::class,
            ]),
        ]);
    }

    /**
     * What is left, one step per kind of record, in the order they are usually
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
                // To the first table with records to complete, further down the page.
                'url' => ($kind = array_key_first(array_filter($this->review()->pendingDataByKind()))) === null ? null : '#month-'.$kind,
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
