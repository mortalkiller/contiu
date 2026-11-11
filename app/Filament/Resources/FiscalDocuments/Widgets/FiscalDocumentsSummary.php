<?php

namespace App\Filament\Resources\FiscalDocuments\Widgets;

use App\Enums\FiscalDocumentStatus;
use App\Enums\IntegrationRecordState;
use App\Filament\Pages\IntegrationsPage;
use App\Filament\Pages\InvoicingSyncPage;
use App\Filament\Resources\FiscalDocuments\FiscalDocumentResource;
use App\Filament\Resources\Receivables\ReceivableResource;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLink;
use App\Models\IntegrationRecord;
use App\Models\Receivable;
use App\Support\DocumentedTurnover;
use App\Support\Invoicing\Invoicing;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use MortalKiller\FilamentAppearance\Widgets\Concerns\GroupsStats;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;

/**
 * Over the list of fiscal documents: what was billed this year, what still has no document, the
 * drafts waiting to be issued and how the invoicing provider stands.
 */
class FiscalDocumentsSummary extends StatsOverviewWidget
{
    use GroupsStats;
    use HasSkeletonPlaceholder;

    protected int|string|array $columnSpan = 'full';

    protected function getColumns(): int|array
    {
        return ['md' => 2, 'xl' => 4];
    }

    protected function getStats(): array
    {
        $year = today()->year;
        $turnover = DocumentedTurnover::forYear($year);
        $undocumented = $this->undocumentedCharges();

        return [
            Stat::make(__('Billed in :year, excluding VAT', ['year' => $year]), $turnover->billedNet === null ? '—' : Money::format($turnover->billedNet))
                ->description($turnover->nonEuroDocumentCount > 0
                    ? trans_choice('{1} 1 document in another currency excluded from the total|[2,*] :count documents in other currencies excluded from the total', $turnover->nonEuroDocumentCount)
                    : trans_choice('{0} No documents issued|{1} 1 document issued|[2,*] :count documents issued', $turnover->documentCount)),
            Stat::make(__('Charges without a document'), (string) $undocumented)
                ->valueColor($undocumented === 0 ? null : 'warning')
                ->description($undocumented === 0 ? __('All due charges have an invoice') : __('Due or paid · issue invoice'))
                ->url($undocumented === 0 ? null : ReceivableResource::getUrl()),
            $this->draftsStat(),
            $this->providerStat(),
        ];
    }

    private function draftsStat(): Stat
    {
        $drafts = FiscalDocument::query()->where('status', FiscalDocumentStatus::Draft);
        $count = (clone $drafts)->count();
        $latest = $count === 0 ? null : $drafts->latest('id')->first(['id', 'party_name', 'total_amount']);

        return Stat::make(__('Drafts to issue'), (string) $count)
            ->description($latest === null
                ? __('Nothing waiting to be issued')
                : implode(' · ', array_filter([$latest->party_name, Money::format($latest->total_amount)])))
            ->url($latest === null ? null : FiscalDocumentResource::getUrl('index', ['tab' => 'drafts']));
    }

    /** Whether a provider is connected and, when it is, when it was last compared and what differs. */
    private function providerStat(): Stat
    {
        $connection = Invoicing::connection();

        if ($connection === null) {
            return Stat::make(__('Invoicing provider'), __('Not connected'))
                ->description(__('Connect one to create and issue documents here'))
                ->url(IntegrationsPage::canAccess() ? IntegrationsPage::getUrl() : null);
        }

        $differences = IntegrationRecord::query()
            ->where('integration_connection_id', $connection->getKey())
            ->whereIn('state', [IntegrationRecordState::Differs, IntegrationRecordState::RemoteOnly])
            ->count();

        return Stat::make(Invoicing::providerLabel(), $differences === 0
            ? __('In sync')
            : trans_choice('{1} 1 difference|[2,*] :count differences', $differences))
            ->valueColor($differences === 0 ? null : 'warning')
            ->description($connection->last_synced_at === null
                ? __('Not synchronised yet')
                : __('Synchronised :when', ['when' => $connection->last_synced_at->diffForHumans()]))
            ->url(InvoicingSyncPage::canAccess() ? InvoicingSyncPage::getUrl() : null);
    }

    /** Charges of this year that are due or already paid, with no active document on them or on their payments. */
    private function undocumentedCharges(): int
    {
        $activeDocument = fn (Builder $query) => $query->where('status', FiscalDocumentStatus::Active);

        return Receivable::query()
            ->excludingCancelled()
            ->whereYear('due_on', today()->year)
            ->where(fn (Builder $query) => $query->whereDate('due_on', '<=', today())->orWhereHas('payments'))
            ->whereNotIn('receivables.id', FiscalDocumentLink::query()->whereNotNull('receivable_id')->whereHas('fiscalDocument', $activeDocument)->select('receivable_id'))
            ->whereDoesntHave('payments', fn (Builder $query) => $query->whereIn(
                'payments.id',
                FiscalDocumentLink::query()->whereNotNull('payment_id')->whereHas('fiscalDocument', $activeDocument)->select('payment_id'),
            ))
            ->count();
    }
}
