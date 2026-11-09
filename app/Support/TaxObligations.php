<?php

namespace App\Support;

use App\Enums\Seat;
use App\Filament\Pages\FinanceSettingsPage;
use App\Filament\Pages\TaxEstimate;
use App\Filament\Resources\Expenses\ExpenseResource;
use App\Models\Account;
use App\Models\Expense;
use App\Models\OneOffIncome;
use App\Models\Payment;
use App\Models\Project;
use App\Models\Retainer;
use App\Models\TaxObligationCompletion;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What the account has to pay or deliver, month by month (the "Este mês" card and its alerts).
 * ESTIMATES ONLY — the amounts come from TaxEstimator and ReverseChargeVat.
 *
 * Keys and deadlines:
 * - ss:YYYY-MM — Segurança Social paid in the month, between the 10th and the 20th: the contribution
 *   of the month before (art. 155.º);
 * - ss-declaration:YYYY-Qn — quarterly SS declaration, by the end of the month after the quarter;
 * - vat-reverse:YYYY-MM — VAT self-assessed on the month's foreign services, by the end of the next month;
 * - recapitulativa:YYYY-MM — services to EU businesses received in the month, by the 20th of the next;
 * - irs-modelo3:YYYY — the year's IRS return, from 1 April to 30 June of the next year.
 *
 * An obligation not marked done stays listed after its deadline as overdue, for at most
 * OVERDUE_MONTHS back and never from before the account existed (it could not have been marked),
 * unless the account set the date to track them from (Definições fiscais).
 */
final class TaxObligations
{
    public const OVERDUE_MONTHS = 12;

    private const SS_TITLE = 'Segurança Social';

    private readonly CarbonImmutable $today;

    private readonly CarbonImmutable $month;

    private ?TaxEstimator $estimator = null;

    /** @var Collection<string, TaxObligationCompletion>|null */
    private ?Collection $completions = null;

    public function __construct(private readonly FinanceSettings $settings, CarbonImmutable $today)
    {
        $this->today = $today->startOfDay();
        $this->month = $today->startOfMonth();
    }

    /**
     * Due this month (the month's SS even when exempt) and still undone from earlier months, by
     * deadline; then the notes (incomplete settings, VAT threshold, foreign expenses with VAT).
     *
     * @return list<TaxObligation>
     */
    public function thisMonth(): array
    {
        $current = array_filter($this->dueIn($this->month), fn (TaxObligation $obligation) => $obligation->status !== 'done');
        $overdue = array_filter($this->dueEarlier(), fn (TaxObligation $obligation) => $obligation->status === 'overdue');

        return [...$this->byDeadline([...$overdue, ...$current]), ...$this->notes()];
    }

    /**
     * Marked done and still relevant: due this month, or overdue ones marked this month.
     *
     * @return list<TaxObligation>
     */
    public function done(): array
    {
        $earlier = array_filter($this->dueEarlier(), fn (TaxObligation $obligation) => $obligation->status === 'done'
            && $this->completions()[$obligation->key]->completed_at->gte($this->month));
        $current = array_filter($this->dueIn($this->month), fn (TaxObligation $obligation) => $obligation->status === 'done');

        return $this->byDeadline([...$earlier, ...$current]);
    }

    /**
     * Coming in the next months, without amounts: the next SS declaration and, in April and May,
     * the IRS return.
     *
     * @return list<TaxObligation>
     */
    public function upcoming(): array
    {
        if ($this->settings->isIncomplete()) {
            return [];
        }

        $declarationMonth = collect(range(1, 3))
            ->map(fn (int $offset) => $this->month->addMonths($offset))
            ->first(fn (CarbonImmutable $month) => $month->month % 3 === 1);

        $upcoming = [
            $this->ssDeclaration($declarationMonth->subMonths(3)),
            in_array($this->month->month, [4, 5], true) ? $this->irsReturn($this->month->year - 1) : null,
        ];

        return array_values(array_filter($upcoming, fn (?TaxObligation $obligation) => $obligation !== null && $obligation->status !== 'done'));
    }

    /**
     * Every obligation with a deadline between the two dates (inclusive), by deadline, for the
     * calendar: past and future months alike, from the first tracked month on. Exempt ones have no
     * deadline and are left out.
     *
     * @return list<TaxObligation>
     */
    public function between(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = $from->startOfDay();
        $to = $to->startOfDay();
        $obligations = [];

        for ($month = $from->startOfMonth()->max($this->trackedFrom()); $month->lte($to); $month = $month->addMonth()) {
            array_push($obligations, ...$this->dueIn($month));
        }

        return $this->byDeadline(array_filter($obligations, fn (TaxObligation $obligation) => $obligation->dueOn !== null
            && $obligation->dueOn->betweenIncluded($from, $to)));
    }

    /** @return list<TaxObligation> */
    public function pending(): array
    {
        return array_values(array_filter($this->thisMonth(), fn (TaxObligation $obligation) => in_array($obligation->status, ['due', 'overdue'], true)));
    }

    public function complete(string $key, ?User $user): void
    {
        TaxObligationCompletion::query()->firstOrCreate(['key' => $key], ['completed_at' => now(), 'user_id' => $user?->getKey()]);
        $this->completions = null;
    }

    public function reopen(string $key): void
    {
        TaxObligationCompletion::query()->where('key', $key)->first()?->delete();
        $this->completions = null;
    }

    /**
     * Every obligation whose deadline falls in the month (the month's SS also when exempt).
     *
     * @return list<TaxObligation>
     */
    private function dueIn(CarbonImmutable $month): array
    {
        $previous = $month->subMonth();

        return array_values(array_filter([
            $this->settings->isIncomplete() ? null : $this->ss($month),
            $month->month % 3 === 1 ? $this->ssDeclaration($month->subMonths(3)) : null,
            $this->vatReverse($previous),
            $this->recapitulativa($previous),
            $month->month === 6 ? $this->irsReturn($month->year - 1) : null,
        ]));
    }

    /**
     * Obligations due in the months before this one that can still show as overdue.
     *
     * @return list<TaxObligation>
     */
    private function dueEarlier(): array
    {
        $obligations = [];

        for ($month = $this->trackedFrom(); $month->lt($this->month); $month = $month->addMonth()) {
            array_push($obligations, ...$this->dueIn($month));
        }

        return $obligations;
    }

    /**
     * The first month whose obligations are listed: at most OVERDUE_MONTHS back, and never before
     * the date the account chose to track from (or, without one, the month it was created).
     */
    private function trackedFrom(): CarbonImmutable
    {
        $from = $this->month->subMonths(self::OVERDUE_MONTHS);
        $created = CurrentAccount::get()?->created_at;
        $since = $this->settings->obligationsTrackedFrom() ?? ($created === null ? null : CarbonImmutable::instance($created));

        return $since === null ? $from : $from->max($since->startOfMonth());
    }

    private function ss(CarbonImmutable $month): TaxObligation
    {
        $key = 'ss:'.$month->format('Y-m');
        $contributionMonth = $month->subMonth();
        // The quarter of income that set the contribution: the Estimativa of its year shows it.
        $source = $contributionMonth->firstOfQuarter()->subMonths(3);
        $url = $this->url(fn (Account $account) => TaxEstimate::getUrl(['year' => $source->year], panel: 'admin', tenant: $account));
        $status = $this->estimator()->ssPaymentStatus($month);

        if ($status !== 'charged') {
            $detail = $status === 'exempt' ? 'Isento (art. 157.º)' : 'Isento (1.º enquadramento)';

            return new TaxObligation($key, 'ss', self::SS_TITLE, null, null, null, $detail, $url, 'exempt');
        }

        // While the quarter of income is still open its figure would be partial (or the minimum).
        $amount = $source->endOfQuarter()->gt($this->today) ? null : $this->estimator()->ssPaymentInMonth($month);
        $detail = 'Pagar entre 10 e 20 de '.self::monthName($month)
            .' · contribuição de '.self::monthName($contributionMonth)
            .' · rendimentos de '.self::quarterName($source)
            .match (true) {
                $amount === null => ' · valor por apurar',
                $amount === TaxEstimator::SS_MINIMUM_CONTRIBUTION => ' · contribuição mínima',
                default => '',
            };
        $dueOn = $month->setDay(20);

        return new TaxObligation($key, 'ss', self::SS_TITLE, $amount, $dueOn, $month->setDay(10), $detail, $url, $this->status($key, $dueOn));
    }

    /** Declared in the month after the quarter; not while the SS obligation has not started. */
    private function ssDeclaration(CarbonImmutable $quarter): ?TaxObligation
    {
        $dueOn = $quarter->addMonths(3)->endOfMonth()->startOfDay();

        // Only workers who must contribute declare (art. 151.º-A, n.º 1): not during the first
        // enrolment, nor with a contribution exemption such as the art. 157.º accumulation.
        if ($this->settings->isIncomplete() || $this->estimator()->ssMonthStatus($dueOn) !== 'charged') {
            return null;
        }

        $key = 'ss-declaration:'.$quarter->year.'-Q'.$quarter->quarter;

        return new TaxObligation(
            $key,
            'ss-declaration',
            'Declaração trimestral da Segurança Social',
            null,
            $dueOn,
            null,
            'Até '.$dueOn->day.' de '.self::monthName($dueOn).' · rendimentos de '.self::quarterName($quarter).' '.$quarter->year,
            null,
            $this->status($key, $dueOn),
        );
    }

    /** Only for a month with foreign services to self-assess. */
    private function vatReverse(CarbonImmutable $month): ?TaxObligation
    {
        $vat = (new ReverseChargeVat($this->settings))->forMonth($month);

        if ($vat['vat'] <= 0) {
            return null;
        }

        $key = 'vat-reverse:'.$month->format('Y-m');
        $dueOn = $month->addMonth()->endOfMonth()->startOfDay();

        return new TaxObligation(
            $key,
            'vat-reverse',
            'IVA das compras ao estrangeiro',
            $vat['vat'],
            $dueOn,
            null,
            'Declaração periódica e pagamento até '.$dueOn->day.' de '.self::monthName($dueOn)
                .' · compras de '.self::monthName($month).' ('.Money::format($vat['base']).' sem IVA)',
            // The expenses list filtered to the month (it has no filter by supplier seat).
            $this->url(fn (Account $account) => ExpenseResource::getUrl('index', [
                'tableFilters' => ['year' => ['value' => $month->year], 'month' => ['value' => $month->month]],
            ], panel: 'admin', tenant: $account)),
            $this->status($key, $dueOn),
        );
    }

    /** Only for a month with payments (not tax-exempt) from businesses seated elsewhere in the EU. */
    private function recapitulativa(CarbonImmutable $month): ?TaxObligation
    {
        $hasEuBusinessPayments = Payment::query()
            ->where('tax_exempt', false)
            ->whereBetween('paid_on', Period::of($month->year, $month->month)->range())
            ->whereHas('receivable', fn (Builder $query) => $query->whereHasMorph(
                'billable',
                [Project::class, Retainer::class, OneOffIncome::class],
                fn (Builder $query) => $query->whereHas('client', fn (Builder $query) => $query
                    ->where('seat', Seat::EuropeanUnion->value)
                    ->where('is_business', true)),
            ))
            ->exists();

        if (! $hasEuBusinessPayments) {
            return null;
        }

        $key = 'recapitulativa:'.$month->format('Y-m');
        $dueOn = $month->addMonth()->setDay(20);

        return new TaxObligation(
            $key,
            'recapitulativa',
            'Declaração recapitulativa',
            null,
            $dueOn,
            null,
            'Até dia 20 de '.self::monthName($dueOn).' · serviços a empresas da UE recebidos em '.self::monthName($month),
            null,
            $this->status($key, $dueOn),
        );
    }

    /** The return of an income year, once the activity had started by then. */
    private function irsReturn(int $year): ?TaxObligation
    {
        $started = $this->settings->activityStartedOn();

        if ($this->settings->isIncomplete() || $started === null || $started->year > $year) {
            return null;
        }

        $key = 'irs-modelo3:'.$year;
        $dueOn = CarbonImmutable::create($year + 1, 6, 30);

        return new TaxObligation(
            $key,
            'irs-modelo3',
            'IRS (modelo 3)',
            null,
            $dueOn,
            CarbonImmutable::create($year + 1, 4, 1),
            'Entrega de 1 de abril a 30 de junho, com o anexo SS · rendimentos de '.$year,
            $this->url(fn (Account $account) => TaxEstimate::getUrl(['year' => $year], panel: 'admin', tenant: $account)),
            $this->status($key, $dueOn),
        );
    }

    /**
     * Rows without a deadline: what is missing in the settings, the art. 53.º threshold and the
     * foreign services of last month that carry VAT (not self-assessed: worth a look).
     *
     * @return list<TaxObligation>
     */
    private function notes(): array
    {
        $notes = [];

        if ($this->settings->isIncomplete()) {
            $notes[] = new TaxObligation(
                'incomplete-settings',
                'incomplete-settings',
                'Completa as definições fiscais',
                null,
                null,
                null,
                'Indica quando e como começaste a atividade para veres a Segurança Social e o IRS.',
                $this->url(fn (Account $account) => FinanceSettingsPage::getUrl(panel: 'admin', tenant: $account)),
                'info',
            );
        }

        if ($this->settings->vatExempt()) {
            $estimator = new TaxEstimator($this->settings, $this->today->year);
            $share = Money::format($estimator->income()).' de '.Money::format($this->settings->vatExemptionThreshold())
                .' ('.number_format($estimator->vatThresholdPercent(), 1, ',', ' ').' %)';

            $notes[] = new TaxObligation(
                'vat-threshold:'.$this->today->year,
                'vat-threshold',
                'Limite da isenção de IVA',
                $estimator->income(),
                null,
                null,
                match ($estimator->vatThresholdStatus()) {
                    'critical' => $share.' · passaste 125 % do limite: tens 15 dias úteis para entregar a declaração de alterações',
                    'danger' => $share.' · sais da isenção no próximo ano: declaração de alterações em janeiro',
                    'warning' => $share.' · estás perto do limite',
                    default => $share,
                },
                $this->url(fn (Account $account) => TaxEstimate::getUrl(panel: 'admin', tenant: $account)),
                'info',
            );
        }

        foreach ((new ReverseChargeVat($this->settings))->foreignWithVat($this->month->subMonth()) as $expense) {
            /** @var Expense $expense */
            $notes[] = new TaxObligation(
                'foreign-vat-warning:'.$expense->getKey(),
                'foreign-vat-warning',
                'Despesa de fornecedor estrangeiro com IVA: confirma',
                null,
                null,
                null,
                trim(($expense->supplier?->name ?? '—').' · '.$expense->description.' ('.$expense->spent_on->format('d/m/Y').')')
                    .': sem autoliquidação, porque a fatura tem IVA',
                $this->url(fn (Account $account) => ExpenseResource::getUrl('edit', ['record' => $expense], panel: 'admin', tenant: $account)),
                'info',
            );
        }

        return $notes;
    }

    /**
     * By deadline; an exempt SS month (no deadline) sits where its payment would be.
     *
     * @param  array<int, TaxObligation>  $obligations
     * @return list<TaxObligation>
     */
    private function byDeadline(array $obligations): array
    {
        usort($obligations, fn (TaxObligation $a, TaxObligation $b) => $this->sortDate($a) <=> $this->sortDate($b));

        return $obligations;
    }

    private function sortDate(TaxObligation $obligation): string
    {
        return ($obligation->dueOn ?? CarbonImmutable::parse(substr($obligation->key, 3).'-20'))->toDateString();
    }

    private function status(string $key, CarbonImmutable $dueOn): string
    {
        return match (true) {
            $this->completions()->has($key) => 'done',
            $dueOn->lt($this->today) => 'overdue',
            default => 'due',
        };
    }

    /** @return Collection<string, TaxObligationCompletion> */
    private function completions(): Collection
    {
        return $this->completions ??= TaxObligationCompletion::query()->get()->keyBy('key');
    }

    private function estimator(): TaxEstimator
    {
        return $this->estimator ??= new TaxEstimator($this->settings, $this->today->year);
    }

    /**
     * Panel links need the account (tenant) in the URL: there is one in the panel and inside
     * CurrentAccount::run() (console); without one there is no link.
     *
     * @param  callable(Account): string  $build
     */
    private function url(callable $build): ?string
    {
        $account = CurrentAccount::get();

        return $account === null ? null : $build($account);
    }

    /** "novembro". */
    private static function monthName(CarbonImmutable $month): string
    {
        return mb_strtolower(Period::MONTHS[$month->month - 1]);
    }

    /** "jul–set" for the quarter starting in July. */
    private static function quarterName(CarbonImmutable $quarter): string
    {
        return mb_strtolower(Period::SHORT_MONTHS[$quarter->month - 1].'–'.Period::SHORT_MONTHS[$quarter->addMonths(2)->month - 1]);
    }
}
