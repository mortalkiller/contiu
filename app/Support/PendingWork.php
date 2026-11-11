<?php

namespace App\Support;

use App\Enums\Language;
use App\Filament\Pages\BankStatementImport;
use App\Models\Receivable;
use Carbon\CarbonImmutable;

/**
 * What needs doing besides the tax obligations, for "Por tratar" on the dashboard: statement
 * lines still to reconcile, and amounts from clients that are overdue or fall due within a week.
 * The rows have the shape the tax obligations card draws (see TaxObligationsCard::row()).
 */
final class PendingWork
{
    /** A receivable shows up this many days before it falls due. */
    public const int DUE_SOON_DAYS = 7;

    /** The card is a summary: the full list is on "A receber". */
    private const int MAX_RECEIVABLES = 10;

    public function __construct(private FinanceSettings $settings, private CarbonImmutable $today) {}

    /**
     * @return list<array{key: string, title: string, tooltip: string, url: ?string, when: ?string, overdue: bool, amount: ?string, mark: null, markTooltip: null, asksExpense: false, tone: string, note: false, link: ?string, receivable: ?int}>
     */
    public function rows(): array
    {
        return [...$this->statementRows(), ...$this->receivableRows()];
    }

    /**
     * Everything the "Por tratar" card lists, counted: the tax obligations due or overdue this
     * month plus the rows above, and whether any of them is already late. For the menu count.
     *
     * @return array{count: int, overdue: bool}
     */
    public function summary(): array
    {
        $obligations = array_values(array_filter(
            (new TaxObligations($this->settings, $this->today))->monthOverview()['rows'],
            fn (TaxObligation $obligation): bool => in_array($obligation->status, ['due', 'overdue'], true),
        ));
        $rows = $this->rows();

        return [
            'count' => count($obligations) + count($rows),
            'overdue' => collect($obligations)->contains(fn (TaxObligation $obligation): bool => $obligation->status === 'overdue')
                || collect($rows)->contains(fn (array $row): bool => $row['overdue']),
        ];
    }

    /**
     * One row for the whole statement: the lines are treated on "Extrato bancário".
     *
     * @return list<array<string, mixed>>
     */
    private function statementRows(): array
    {
        if (! $this->settings->hasBusinessAccount()) {
            return [];
        }

        $pending = (new CompanyAccount)->pendingLines();

        if ($pending === 0) {
            return [];
        }

        return [[
            'key' => 'statement',
            'title' => trans_choice('{1} :count statement entry to reconcile|[2,*] :count statement entries to reconcile', $pending),
            'tooltip' => __('The company account balance can still change while statement entries remain unreconciled.'),
            'url' => BankStatementImport::getUrl(),
            'when' => null,
            'overdue' => false,
            'amount' => null,
            'mark' => null,
            'markTooltip' => null,
            'asksExpense' => false,
            'tone' => 'warning',
            'note' => false,
            'link' => __('View statement'),
            'receivable' => null,
        ]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function receivableRows(): array
    {
        return Receivable::query()
            ->open()
            ->excludingCancelled()
            ->whereDate('due_on', '<=', $this->today->addDays(self::DUE_SOON_DAYS))
            ->with('billable.client')
            ->orderBy('due_on')
            ->limit(self::MAX_RECEIVABLES)
            ->get()
            ->map(function (Receivable $receivable): array {
                $days = (int) $this->today->diffInDays($receivable->due_on, absolute: false);

                return [
                    'key' => 'receivable-'.$receivable->getKey(),
                    'title' => __(':name has an outstanding amount', ['name' => $receivable->client?->name ?? $receivable->label]),
                    'tooltip' => $receivable->label,
                    'url' => $receivable->billableUrl(),
                    'when' => match (true) {
                        $days < 0 => trans_choice('{1} :count day overdue|[2,*] :count days overdue', -$days),
                        $days === 0 => __('due today'),
                        default => __('due on :date', ['date' => $receivable->due_on->format(Language::resolve(app()->getLocale())->dateFormat())]),
                    },
                    'overdue' => $days < 0,
                    'amount' => Money::format($receivable->outstanding),
                    'mark' => null,
                    'markTooltip' => null,
                    'asksExpense' => false,
                    'tone' => $days < 0 ? 'danger' : 'warning',
                    'note' => false,
                    'link' => null,
                    'receivable' => $receivable->getKey(),
                ];
            })
            ->all();
    }
}
