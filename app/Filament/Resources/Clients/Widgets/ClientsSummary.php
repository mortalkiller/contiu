<?php

namespace App\Filament\Resources\Clients\Widgets;

use App\Filament\Resources\Receivables\ReceivableResource;
use App\Models\Receivable;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Collection;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

/**
 * Above the "Clientes" list, as in the mock (one card each, no icons): what was contracted for
 * this year and how much of it came in, then what every client still owes and what is already
 * late (both a way into "A receber").
 */
class ClientsSummary extends StatsOverviewWidget
{
    use HasSkeletonPlaceholder;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array
    {
        return ['md' => 2, 'xl' => 4];
    }

    protected function getStats(): array
    {
        $year = today()->year;

        /** @var Collection<int, Receivable> $thisYear */
        $thisYear = Receivable::query()->excludingCancelled()->whereYear('due_on', $year)->with(['payments', 'billable'])->get();
        $contracted = (int) $thisYear->sum('amount');
        $received = (int) $thisYear->sum(fn (Receivable $receivable): int => $receivable->received);
        $activeClients = $thisYear->map(fn (Receivable $receivable) => $receivable->billable?->client_id)->filter()->unique()->count();
        $receivedPercent = $contracted > 0 ? (int) round($received / $contracted * 100) : 0;

        /** @var Collection<int, Receivable> $open */
        $open = Receivable::query()->open()->excludingCancelled()->withOutstandingBalance()->with('billable.client')->orderBy('due_on')->get();
        $outstanding = (int) $open->sum(fn (Receivable $receivable): int => $receivable->projectedOutstanding());
        $owingClients = $open->map(fn (Receivable $receivable) => $receivable->billable?->client_id)->filter()->unique()->count();
        $late = $open->filter(fn (Receivable $receivable): bool => $receivable->due_on->isBefore(today()));
        $overdue = (int) $late->sum(fn (Receivable $receivable): int => $receivable->projectedOutstanding());
        $oldest = $late->first();

        $clients = fn (int $number): string => trans_choice('{0} No clients|{1} 1 client|[2,*] :count clients', $number);

        return [
            Stat::make(__('Contracted in :year', ['year' => $year]), Money::format($contracted))
                ->description(trans_choice('{0} No active clients|{1} 1 active client|[2,*] :count active clients', $activeClients)),
            Stat::make(__('Received'), Money::format($received))
                ->progress($receivedPercent),
            Stat::make(__('Awaiting payment'), Money::format($outstanding))
                ->description($outstanding > 0 ? __('From :clients', ['clients' => mb_strtolower($clients($owingClients))]) : __('All payments received'))
                ->valueColor($outstanding > 0 ? 'warning' : null)
                ->url(ReceivableResource::getUrl()),
            Stat::make(__('Past due'), Money::format($overdue))
                ->description($oldest === null
                    ? __('Nothing overdue')
                    : __(':client · :days', [
                        'client' => $oldest->client?->name ?? __('No client'),
                        'days' => trans_choice('{1} 1 day ago|[2,*] :count days ago', max(1, (int) $oldest->due_on->diffInDays(today()))),
                    ]))
                ->valueColor($overdue > 0 ? 'danger' : null)
                ->url(ReceivableResource::getUrl('index', ['tab' => 'overdue'])),
        ];
    }
}
