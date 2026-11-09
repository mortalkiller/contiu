<?php

namespace App\Filament\Analysis\Widgets;

use App\Support\FinanceSettings;
use App\Support\Money;
use App\Support\Period;
use App\Support\TaxObligation;
use App\Support\TaxObligations;
use Carbon\CarbonImmutable;
use Filament\Widgets\Widget;

/**
 * "Prazos a seguir", beside the Calendário: the obligations still to do (overdue first, then by
 * deadline) and the next ones coming. Marking them done stays on the dashboard's "Este mês" card.
 */
class UpcomingDeadlines extends Widget
{
    protected string $view = 'filament.analysis.widgets.upcoming-deadlines';

    protected int|string|array $columnSpan = 'full';

    /** @return array{rows: list<array{key: string, title: string, amount: ?string, when: string, badge: string, tone: string, url: ?string, tooltip: string}>} */
    protected function getViewData(): array
    {
        $today = CarbonImmutable::today();
        $obligations = new TaxObligations(app(FinanceSettings::class), $today);

        return [
            'rows' => array_map(
                fn (TaxObligation $obligation) => $this->row($obligation, $today),
                [...$obligations->pending(), ...$obligations->upcoming()],
            ),
        ];
    }

    /** @return array{key: string, title: string, amount: ?string, when: string, badge: string, tone: string, url: ?string, tooltip: string} */
    private function row(TaxObligation $obligation, CarbonImmutable $today): array
    {
        $days = $obligation->daysLeft($today);

        return [
            'key' => $obligation->key,
            'title' => implode(' · ', array_filter([$obligation->title, $obligation->period()])),
            'amount' => $obligation->amount === null ? null : Money::format($obligation->amount),
            'when' => $obligation->opensOn !== null && $obligation->opensOn->month === $obligation->dueOn->month
                ? $obligation->opensOn->day.' a '.self::shortDate($obligation->dueOn)
                : 'até '.self::shortDate($obligation->dueOn),
            'badge' => match (true) {
                $obligation->status === 'overdue' => 'em atraso',
                $days === 0 => 'hoje',
                $days === 1 => '1 dia',
                default => $days.' dias',
            },
            'tone' => match (true) {
                $obligation->status === 'overdue' => 'danger',
                $days <= 7 => 'warning',
                default => 'gray',
            },
            'url' => $obligation->url,
            'tooltip' => $obligation->detail,
        ];
    }

    /** "20 nov.". */
    private static function shortDate(CarbonImmutable $date): string
    {
        return $date->day.' '.mb_strtolower(Period::SHORT_MONTHS[$date->month - 1]).'.';
    }
}
