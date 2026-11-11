<?php

namespace App\Filament\Resources\FiscalDocuments\Widgets;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Enums\Language;
use App\Filament\Pages\IntegrationsPage;
use App\Filament\Pages\InvoiceXpressImport;
use App\Filament\Resources\Receivables\ReceivableResource;
use App\Models\CreditApplication;
use App\Models\FiscalDocument;
use App\Models\FiscalDocumentLink;
use App\Models\InvoiceXpressConfig;
use App\Models\InvoiceXpressSnapshot;
use App\Models\Receivable;
use App\Support\DocumentedTurnover;
use App\Support\Money;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;
use MortalKiller\FilamentSkeletonLoading\Concerns\HasSkeletonPlaceholder;
use ToneGabes\Filament\Icons\Enums\Phosphor;

/**
 * Above the "Documentos fiscais" list: this year's documented billing (the same figure as the
 * tax estimate, App\Support\DocumentedTurnover) with the last six months as a sparkline, the
 * charges already due that still have no document, the issued credit notes not yet applied, and
 * where the InvoiceXpress connection stands. Counts by direction live on the page's tabs.
 */
class FiscalDocumentsSummary extends StatsOverviewWidget
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
        $turnover = DocumentedTurnover::forYear($year);
        $undocumented = $this->undocumentedCharges();
        $unappliedCredits = $this->unappliedCreditNotes();

        return [
            Stat::make(__('Billed in :year, excluding VAT', ['year' => $year]), $turnover->billedNet === null ? '—' : Money::format($turnover->billedNet, locale: app()->getLocale()))
                ->description($turnover->nonEuroDocumentCount > 0
                    ? trans_choice('{1} 1 document in another currency excluded from the total|[2,*] :count documents in other currencies excluded from the total', $turnover->nonEuroDocumentCount)
                    : __('Credit notes reduce the total'))
                ->color($turnover->nonEuroDocumentCount > 0 ? 'warning' : 'primary')
                ->chart($this->billedByMonth())
                ->icon(Phosphor::Invoice),
            Stat::make(__('Charges without a document'), (string) $undocumented)
                ->description($undocumented === 0 ? __('All due charges have an invoice') : __('Due or paid · issue invoice'))
                ->color($undocumented === 0 ? 'success' : 'warning')
                ->url($undocumented === 0 ? null : ReceivableResource::getUrl())
                ->icon(Phosphor::FileDashed),
            Stat::make(__('Unapplied credit notes'), (string) $unappliedCredits)
                ->description($unappliedCredits === 0 ? __('All applied') : __('Apply them to the original invoice'))
                ->color($unappliedCredits === 0 ? 'success' : 'warning')
                ->icon(Phosphor::ArrowUUpLeft),
            $this->invoiceXpressStat(),
        ];
    }

    /**
     * Net billing in EUR for each of the last six months, oldest first, for the sparkline.
     *
     * @return list<float>
     */
    private function billedByMonth(): array
    {
        $first = today()->startOfMonth()->subMonths(5);

        $byMonth = DocumentedTurnover::billingDocuments()
            ->where('currency', 'EUR')
            ->where('document_date', '>=', $first->toDateString())
            ->get(['type', 'net_amount', 'document_date'])
            ->groupBy(fn (FiscalDocument $document): string => $document->document_date->format('Y-m'))
            ->map(fn ($documents): int => (int) $documents->sum(
                fn (FiscalDocument $document): int => $document->type === FiscalDocumentType::CreditNote ? -abs($document->net_amount) : $document->net_amount,
            ));

        return collect(range(0, 5))
            ->map(fn (int $offset): float => max(0, $byMonth->get($first->copy()->addMonths($offset)->format('Y-m'), 0)) / 100)
            ->all();
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

    /** Active credit notes issued in EUR with no application in force. */
    private function unappliedCreditNotes(): int
    {
        return FiscalDocument::query()
            ->where('type', FiscalDocumentType::CreditNote)
            ->where('direction', FiscalDocumentDirection::Issued)
            ->where('status', FiscalDocumentStatus::Active)
            ->where('currency', 'EUR')
            ->whereNotIn('id', CreditApplication::query()->whereNull('reversed_on')->select('credit_note_id'))
            ->count();
    }

    /** Whether the account is connected to InvoiceXpress and when a document last came from there. */
    private function invoiceXpressStat(): Stat
    {
        $connected = InvoiceXpressConfig::current() !== null;
        $lastImport = InvoiceXpressSnapshot::query()->latest()->value('created_at');

        return Stat::make('InvoiceXpress', $connected ? __('Connected') : __('Not connected'))
            ->description(match (true) {
                ! $connected => __('Connect an integration to synchronize fiscal documents'),
                $lastImport === null => __('No documents imported yet'),
                default => __('Last imported on :date', ['date' => $lastImport->format(Language::resolve(app()->getLocale())->dateFormat())]),
            })
            ->url(match (true) {
                $connected && InvoiceXpressImport::canAccess() => InvoiceXpressImport::getUrl(),
                ! $connected && IntegrationsPage::canAccess() => IntegrationsPage::getUrl(),
                default => null,
            })
            ->icon(Phosphor::PlugsConnected);
    }
}
