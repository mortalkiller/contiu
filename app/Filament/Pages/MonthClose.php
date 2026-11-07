<?php

namespace App\Filament\Pages;

use App\Filament\MonthClose\Widgets\MonthCloseStats;
use App\Filament\MonthClose\Widgets\MonthExpensesTable;
use App\Filament\MonthClose\Widgets\MonthReceivablesTable;
use App\Support\MonthReview;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * "Fecho do mês": one screen per month with everything to deal with, so the month can be
 * reviewed line by line until nothing is left.
 */
class MonthClose extends Page
{
    private const MONTHS = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

    protected static string|BackedEnum|null $navigationIcon = Phosphor::CalendarCheck;

    protected static ?string $navigationLabel = 'Fecho do mês';

    protected static ?string $title = 'Fecho do mês';

    protected static ?string $slug = 'fecho-do-mes';

    protected static ?int $navigationSort = 1;

    /** Reached from the dashboard's "Fecho do mês" button and from notifications, not the menu. */
    protected static bool $shouldRegisterNavigation = false;

    #[Url]
    public string $month = '';

    public function mount(): void
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $this->month)) {
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
        ];
    }

    /**
     * Filament builds and caches this schema once per request — before an action such as "Mês
     * seguinte" changes $month — so everything month-dependent is a closure evaluated at render.
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Callout::make(fn (): string => match ($pending = $this->review()->pendingCount()) {
                0 => 'Mês fechado',
                1 => 'Falta 1 item',
                default => 'Faltam '.$pending.' itens',
            })
                ->description(fn (): string => $this->review()->pendingCount() === 0
                    ? 'Tudo recebido e todas as despesas confirmadas.'
                    : $this->review()->pendingReceivables().' por receber · '.$this->review()->pendingExpenses().' despesas por pagar')
                ->icon(fn () => $this->review()->pendingCount() === 0 ? Phosphor::CheckCircle : Phosphor::ListChecks)
                ->status(fn (): string => $this->review()->pendingCount() === 0 ? 'success' : 'warning'),
            ...$this->getWidgetsSchemaComponents([
                MonthCloseStats::class,
                MonthReceivablesTable::class,
                MonthExpensesTable::class,
            ]),
        ]);
    }

    /** Read lazily by the widgets' Livewire data closure, so they always get the current month. */
    public function getWidgetData(): array
    {
        return ['month' => $this->month];
    }

    private ?MonthReview $review = null;

    private function review(): MonthReview
    {
        if ($this->review === null || ! $this->review->start->isSameMonth($this->date())) {
            $this->review = new MonthReview($this->date());
        }

        return $this->review;
    }

    private function date(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->month.'-01')->startOfDay();
    }
}
