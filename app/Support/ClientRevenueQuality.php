<?php

namespace App\Support;

use App\Models\Receivable;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class ClientRevenueQuality
{
    public function __construct(private readonly Period $period) {}

    /** @return array<int, array{label: string, invoiced: int, received: int, directCosts: int, margin: int, marginPercent: ?float, share: ?float, medianDelay: int|float|null}> */
    public function rows(): array
    {
        $totalReceived = (new PeriodSummary($this->period))->received();
        $clients = (new MarginReport($this->period))->byClientId();
        unset($clients[0]);
        $delays = $this->delaysByClient();

        foreach ($clients as $id => &$client) {
            $client['share'] = $totalReceived > 0 ? round($client['received'] * 100 / $totalReceived, 1) : null;
            $client['medianDelay'] = $delays->get($id)?->median();
        }
        unset($client);

        uasort($clients, fn (array $a, array $b): int => $b['received'] <=> $a['received']);

        return $clients;
    }

    /** @return array{inflows: int, clientInflows: int, withoutClient: int, withDelay?: int, beyondHorizon?: int, months?: list<array{label: string, inflows: int, withDelay: int}>} */
    public function scenario(int $clientId, ?int $delayDays = null): array
    {
        return (new CashForecast(app(FinanceSettings::class)))->clientInflowScenario($clientId, $delayDays);
    }

    /** @return Collection<int, Collection<int, int>> */
    private function delaysByClient(): Collection
    {
        $today = CarbonImmutable::today();

        return Receivable::query()->excludingCancelled()
            ->whereBetween('due_on', [$this->period->start->toDateString(), $this->period->end->toDateTimeString()])
            ->with(['billable.client', 'payments'])
            ->get()
            ->filter(fn (Receivable $charge): bool => $charge->client !== null && $charge->amount > 0)
            ->groupBy(fn (Receivable $charge): int => $charge->client->id)
            ->map(fn (Collection $charges): Collection => $charges
                ->map(fn (Receivable $charge): ?int => $this->daysUntilSettled($charge, $today))
                ->reject(fn (?int $days): bool => $days === null));
    }

    private function daysUntilSettled(Receivable $charge, CarbonImmutable $today): ?int
    {
        $received = 0;

        $applicationDate = fn ($payment): CarbonImmutable => CarbonImmutable::parse($payment->pivot->allocated_on)
            ->max(CarbonImmutable::parse($payment->paid_on));

        foreach ($charge->payments->sortBy($applicationDate) as $payment) {
            $date = $applicationDate($payment);
            if ($date->gt($today)) {
                break;
            }

            $received += (int) $payment->pivot->amount;

            if ($received >= $charge->amount) {
                return max(0, (int) $charge->due_on->diffInDays($date, absolute: false));
            }
        }

        return null;
    }
}
