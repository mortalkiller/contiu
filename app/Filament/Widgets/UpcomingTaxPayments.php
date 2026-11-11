<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\Calendar;
use App\Models\User;
use App\Support\CurrentAccount;
use App\Support\FinanceSettings;
use App\Support\Money;
use App\Support\TaxObligation;
use App\Support\TaxObligations;
use Carbon\CarbonImmutable;
use Filament\Widgets\Widget;

/**
 * "Próximos pagamentos", beside the Segurança Social table on the tax estimate, as in the mock:
 * what is late, what is due this month and what comes next, one line each, with the amount or,
 * for a declaration, the button to mark it filed. The dashboard's "Por tratar" keeps the full card.
 */
class UpcomingTaxPayments extends Widget
{
    protected string $view = 'filament.widgets.upcoming-tax-payments';

    protected int|string|array $columnSpan = 'full';

    protected static bool $isDiscovered = false;

    /** The first lines only; the rest are on the calendar. */
    private const int SHOWN = 6;

    /** A declaration listed here can be marked filed; payments are marked on the dashboard. */
    public function markFiled(string $key): void
    {
        if (CurrentAccount::readOnly()) {
            return;
        }

        $obligations = $this->obligations();
        $obligation = collect($obligations->pending())->firstWhere('key', $key);

        if ($obligation instanceof TaxObligation && $this->isDeclaration($obligation)) {
            $user = auth()->user();
            $obligations->complete($obligation->key, $user instanceof User ? $user : null);
        }
    }

    /**
     * @return array{rows: list<array{key: string, title: string, when: string, tone: string, amount: ?string, canMark: bool}>, hidden: int, calendarUrl: string, total: ?string}
     */
    protected function getViewData(): array
    {
        $today = CarbonImmutable::today();
        $obligations = $this->obligations();
        $locale = app()->getLocale();
        $shortDate = fn (CarbonImmutable $date): string => $date->locale($locale)->translatedFormat(__('j M'));

        $pending = array_values(array_filter($obligations->pending(), fn (TaxObligation $obligation): bool => $obligation->dueOn !== null));
        $listed = [...$pending, ...array_filter($obligations->upcoming(), fn (TaxObligation $obligation): bool => $obligation->dueOn !== null)];

        $rows = array_map(fn (TaxObligation $obligation): array => [
            'key' => $obligation->key,
            'title' => $obligation->title.($obligation->period() !== '' ? ' · '.$obligation->period() : ''),
            'when' => match (true) {
                $obligation->status === 'overdue' => __('Was due by :date', ['date' => $shortDate($obligation->dueOn)]),
                default => __('By :date', ['date' => $shortDate($obligation->dueOn)]),
            },
            'tone' => match (true) {
                $obligation->status === 'overdue' => 'danger',
                $obligation->status === 'due' => 'warning',
                default => 'gray',
            },
            'amount' => $obligation->amount === null ? null : Money::format($obligation->amount, locale: $locale),
            // Only what is already due can be marked; the next declaration is listed as a heads-up.
            'canMark' => $this->isDeclaration($obligation) && in_array($obligation, $pending, true) && ! CurrentAccount::readOnly(),
        ], $listed);

        $total = (int) array_sum(array_map(fn (TaxObligation $obligation): int => (int) $obligation->amount, $pending));

        return [
            'rows' => array_slice($rows, 0, self::SHOWN),
            'hidden' => max(0, count($rows) - self::SHOWN),
            'calendarUrl' => Calendar::getUrl(),
            'total' => $total > 0 ? Money::format($total, locale: $locale) : null,
        ];
    }

    private function isDeclaration(TaxObligation $obligation): bool
    {
        return in_array($obligation->kind, ['ss-declaration', 'ss-annual', 'vat-return', 'recapitulativa', 'irs-modelo3'], true);
    }

    private function obligations(): TaxObligations
    {
        return new TaxObligations(app(FinanceSettings::class), CarbonImmutable::today());
    }
}
