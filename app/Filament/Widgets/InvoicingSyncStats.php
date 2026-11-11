<?php

namespace App\Filament\Widgets;

use App\Enums\FiscalDocumentStatus;
use App\Enums\IntegrationRecordState;
use App\Models\FiscalDocument;
use App\Models\IntegrationRecord;
use App\Support\Invoicing\Invoicing;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\On;
use MortalKiller\FilamentAppearance\Widgets\Concerns\GroupsStats;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

/**
 * The four figures that open "Sincronização", in one card: how many documents are the same here
 * and at the invoicing provider, how many drafts wait to be issued, how many differ and how many
 * only exist at the provider.
 */
class InvoicingSyncStats extends StatsOverviewWidget
{
    use GroupsStats;
    use HasSkeletonPlaceholder;

    /** The page tells the widget to count again after a synchronisation or an import. */
    public const string REFRESH_EVENT = 'invoicing-sync-updated';

    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array
    {
        return ['md' => 2, 'xl' => 4];
    }

    #[On(self::REFRESH_EVENT)]
    public function countAgain(): void {}

    protected function getStats(): array
    {
        $provider = Invoicing::providerLabel();

        if ($provider === null) {
            return [];
        }

        $counts = self::recordCounts();
        $drafts = FiscalDocument::query()->where('status', FiscalDocumentStatus::Draft)->count();
        $differs = $counts[IntegrationRecordState::Differs->value] ?? 0;
        $remoteOnly = $counts[IntegrationRecordState::RemoteOnly->value] ?? 0;

        return [
            Stat::make(__('In sync'), (string) ($counts[IntegrationRecordState::InSync->value] ?? 0))
                ->description(__('The same in Contiu and in :provider', ['provider' => $provider])),
            Stat::make(__('To issue'), (string) $drafts)
                ->description(__('Drafts waiting to be issued')),
            Stat::make(__('With differences'), (string) $differs)
                ->description(__('Not the same in Contiu and in :provider', ['provider' => $provider]))
                ->valueColor($differs > 0 ? 'warning' : null),
            Stat::make(__('To import'), (string) $remoteOnly)
                ->description(__('Issued directly in :provider', ['provider' => $provider]))
                ->valueColor($remoteOnly > 0 ? 'warning' : null),
        ];
    }

    /**
     * How many of the connection's document records are in each state, in one query. A record
     * whose document is still a draft is left out: it counts as "to issue", whatever its state.
     *
     * @return array<string, int>
     */
    private static function recordCounts(): array
    {
        return IntegrationRecord::query()
            ->where('integration_connection_id', Invoicing::connection()->getKey())
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $query) => $query->whereNull('record_type')->where('state', IntegrationRecordState::RemoteOnly))
                ->orWhereHasMorph('record', [FiscalDocument::class], fn (Builder $query) => $query->where('status', '!=', FiscalDocumentStatus::Draft)))
            ->toBase()
            ->selectRaw('state, count(*) as total')
            ->groupBy('state')
            ->pluck('total', 'state')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();
    }
}
