<?php

namespace App\Support;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Models\FiscalDocument;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Builder;

/** Operational billing and cash totals in cents. Territorial eligibility requires separate review. */
final readonly class DocumentedTurnover
{
    private function __construct(
        public ?int $billedNet,
        public int $received,
        public int $documentCount,
        public int $nonEuroDocumentCount,
    ) {}

    public static function forYear(int $year): self
    {
        $start = Period::of($year)->start;
        $from = $start->toDateString();
        $until = $start->addYear()->toDateString();

        $documentsByCurrency = self::billingDocuments()
            ->where('document_date', '>=', $from)
            ->where('document_date', '<', $until)
            ->toBase()
            ->selectRaw('currency, COUNT(*) AS document_count')
            ->selectRaw(
                'SUM(CASE WHEN type = ? THEN -ABS(net_amount) ELSE net_amount END) AS billed_net',
                [FiscalDocumentType::CreditNote->value],
            )
            ->groupBy('currency')
            ->get()
            ->keyBy('currency');

        $euro = $documentsByCurrency->get('EUR');
        $documentCount = (int) $documentsByCurrency->sum('document_count');
        $received = Payment::query()
            ->where('paid_on', '>=', $from)
            ->where('paid_on', '<', $until)
            ->sum('amount');

        return new self(
            billedNet: $euro === null ? null : (int) $euro->billed_net,
            received: (int) $received,
            documentCount: $documentCount,
            nonEuroDocumentCount: $documentCount - (int) ($euro->document_count ?? 0),
        );
    }

    /** @return list<int> */
    public static function issueYears(): array
    {
        return self::billingDocuments()->distinct()->pluck('document_date')
            ->map->year->unique()->sortDesc()->values()->all();
    }

    /** @param Builder<FiscalDocument>|null $query @return Builder<FiscalDocument> */
    public static function billingDocuments(?Builder $query = null): Builder
    {
        return ($query ?? FiscalDocument::query())
            ->where('direction', FiscalDocumentDirection::Issued)
            ->where('status', FiscalDocumentStatus::Active)
            ->whereIn('type', [FiscalDocumentType::Invoice, FiscalDocumentType::InvoiceReceipt, FiscalDocumentType::CreditNote, FiscalDocumentType::DebitNote]);
    }
}
