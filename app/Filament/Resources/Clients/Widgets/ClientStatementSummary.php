<?php

namespace App\Filament\Resources\Clients\Widgets;

use App\Enums\Language;
use App\Models\Client;
use App\Support\ClientStatement;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Carbon;
use MortalKiller\FilamentAppearance\Widgets\Concerns\GroupsStats;

/**
 * Over the client's statement, as in the mock: the balance the period opens with, what was
 * charged and received in it, and the balance it ends on, in one card.
 */
class ClientStatementSummary extends StatsOverviewWidget
{
    use GroupsStats;

    protected static bool $isLazy = false;

    public ?Client $record = null;

    public ?string $from = null;

    public ?string $until = null;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array
    {
        return ['md' => 2, 'xl' => 4];
    }

    protected function getStats(): array
    {
        if ($this->record === null) {
            return [];
        }

        $statement = new ClientStatement(
            $this->record,
            from: $this->from === null ? null : Carbon::parse($this->from),
            to: $this->until === null ? null : Carbon::parse($this->until),
        );
        $entries = $statement->entries();
        $opening = (int) ($entries->firstWhere('kind', 'opening')['balance'] ?? 0);
        $charges = $entries->where('kind', 'charge')->count();
        $payments = $entries->where('kind', 'payment')->count();
        $overdue = $statement->overdueBalance();
        $balance = $statement->balance();
        $date = fn (string $value): string => Carbon::parse($value)->format(Language::resolve(app()->getLocale())->dateFormat());

        return [
            Stat::make(__('Opening balance'), Money::format($opening))
                ->description($this->from === null ? __('Since the first movement') : __('On :date', ['date' => $date($this->from)])),
            Stat::make(__('Charged'), Money::format($statement->charged()))
                ->description(trans_choice('{0} No amounts|{1} 1 amount|[2,*] :count amounts', $charges)),
            Stat::make(__('Received'), Money::format($statement->received()))
                ->description(trans_choice('{0} No payments received|{1} 1 payment received|[2,*] :count payments received', $payments)),
            Stat::make(__('Balance'), Money::format($balance))
                ->description(match (true) {
                    $overdue > 0 => __(':amount past due', ['amount' => Money::format($overdue)]),
                    $balance > 0 => __('Nothing overdue'),
                    default => __('All payments received'),
                })
                ->valueColor(match (true) {
                    $overdue > 0 => 'danger',
                    $balance > 0 => 'warning',
                    default => null,
                }),
        ];
    }
}
