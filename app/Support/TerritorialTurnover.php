<?php

namespace App\Support;

use App\Enums\FiscalDocumentType;
use App\Enums\Language;
use App\Models\Account;
use App\Models\FiscalDocument;
use App\Models\TerritorialTurnoverCoverage;
use App\Models\TerritorialTurnoverReview;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final readonly class TerritorialTurnover
{
    /**
     * @param  array<int, array{document: FiscalDocument, review: ?TerritorialTurnoverReview, stale: bool, credit_verified: bool, source: string, revision: string}>  $documents
     */
    private function __construct(
        public int $year,
        public CarbonImmutable $through,
        public array $documents,
        private ?TerritorialTurnoverCoverage $attestation,
        private int $asOfYear,
    ) {}

    public static function forYear(int $year, ?CarbonImmutable $asOf = null): self
    {
        $today = ($asOf ?? CarbonImmutable::today())->startOfDay();
        $through = $today->min(CarbonImmutable::create($year, 12, 31));
        $records = DocumentedTurnover::billingDocuments()
            ->with('links.relatedFiscalDocument')->orderBy('id')->get()->keyBy('id');
        $reviews = TerritorialTurnoverReview::query()->get()->keyBy('fiscal_document_id');
        $documents = [];
        $creditedByOriginal = [];
        $originalByCredit = [];
        $originalEligibility = [];

        foreach ($records as $document) {
            $saved = $reviews->get($document->id);
            $related = $document->type === FiscalDocumentType::CreditNote ? $document->links?->relatedFiscalDocument : null;
            $originalReview = $related === null ? null : $reviews->get($related->id);
            $source = self::sourceRevision($document, $related, $originalReview);
            $review = $saved?->source_revision === $source ? $saved : null;
            $date = $review?->operation_on ?? $document->operation_date;

            if ($document->type === FiscalDocumentType::CreditNote && $review?->eligible_cents > 0
                && $related !== null && $review->operation_on->lte($through)) {
                $creditedByOriginal[$related->id] = ($creditedByOriginal[$related->id] ?? 0) + $review->eligible_cents;

                if ($records->has($related->id)
                    && $related->type !== FiscalDocumentType::CreditNote
                    && $originalReview?->source_revision === self::sourceRevision($related)
                    && $originalReview->eligible_cents !== null
                    && $originalReview->operation_on->lte($review->operation_on)) {
                    $originalByCredit[$document->id] = $related->id;
                    $originalEligibility[$related->id] = $originalReview->eligible_cents;
                }
            }

            // An unresolved operation without a source date can belong to any year.
            $inYear = $date === null || ($date->year === $year && $date->lte($through));
            $previouslyInYear = $review === null && $saved?->operation_on->year === $year;
            if (! $inYear && ! $previouslyInYear) {
                continue;
            }

            $documents[$document->id] = [
                'document' => $document,
                'review' => $review,
                'stale' => $saved !== null && $review === null,
                'credit_verified' => $document->type !== FiscalDocumentType::CreditNote || ! ($review?->eligible_cents > 0),
                'source' => $source,
                'revision' => self::hash([$source, $saved?->review_revision]),
            ];
        }

        foreach ($originalByCredit as $creditId => $originalId) {
            if (isset($documents[$creditId])) {
                $documents[$creditId]['credit_verified'] = $creditedByOriginal[$originalId] <= $originalEligibility[$originalId];
            }
        }

        return new self(
            $year,
            $through,
            $documents,
            TerritorialTurnoverCoverage::query()->where('year', $year)->first(),
            $today->year,
        );
    }

    /** @return list<int> */
    public static function reviewYears(): array
    {
        return DocumentedTurnover::billingDocuments()->whereNotNull('operation_date')->distinct()->pluck('operation_date')
            ->merge(TerritorialTurnoverReview::query()->distinct()->pluck('operation_on'))
            ->map(fn ($date): int => $date->year)
            ->merge(TerritorialTurnoverCoverage::query()->pluck('year'))
            ->unique()->values()->all();
    }

    public function subtotal(): ?int
    {
        $total = null;

        foreach ($this->documents as $row) {
            if ($row['review'] === null) {
                continue;
            }

            $amount = $row['review']->eligible_cents ?? 0;
            $total = ($total ?? 0) + ($row['document']->type === FiscalDocumentType::CreditNote ? -$amount : $amount);
        }

        return $total ?? ($this->coverage() === null ? null : 0);
    }

    public function excludedCount(): int
    {
        return count(array_filter($this->documents, fn (array $row): bool => $row['review'] !== null && $row['review']->eligible_cents === null));
    }

    public function unclassifiedCount(): int
    {
        return count(array_filter($this->documents, fn (array $row): bool => $row['review'] === null));
    }

    public function revision(): string
    {
        return self::hash([$this->through->toDateString(), $this->sourceRevisionForYear()]);
    }

    public function coverage(): ?TerritorialTurnoverCoverage
    {
        $coverage = $this->attestation;

        if ($coverage === null || $coverage->through_on->gt($this->through)
            || ($this->year < $this->asOfYear && ! $coverage->through_on->isSameDay($this->through))
            || $coverage->source_revision !== $this->sourceRevisionForYear()) {
            return null;
        }

        return $coverage;
    }

    public function coverageLabel(): string
    {
        return ($coverage = $this->coverage()) === null
            ? __('Coverage to confirm')
            : __('Coverage confirmed through :date', ['date' => $coverage->through_on->format(Language::resolve(app()->getLocale())->dateFormat())]);
    }

    public function subtotalLabel(): string
    {
        return match (true) {
            $this->hasUnverifiedCreditNotes() => __('Subtotal with credit notes to verify'),
            $this->coverage() === null => __('Confirmed subtotal (partial)'),
            default => __('Confirmed domestic turnover'),
        };
    }

    public function summary(): string
    {
        return __(':coverage. Excluded: :excluded · Unclassified: :unclassified.', [
            'coverage' => $this->coverageLabel(),
            'excluded' => $this->excludedCount(),
            'unclassified' => $this->unclassifiedCount(),
        ]);
    }

    public function notice(): ?string
    {
        if ($this->year < 2025 || $this->coverage() === null || $this->hasUnverifiedCreditNotes()) {
            return null;
        }

        if ($this->year >= 2026) {
            $crossing = $this->inYearCrossing();
            if ($crossing === 'confirmed') {
                return __('Turnover exceeded €18,750 in :year. Article 58 requires VAT starting with the transaction that exceeds that amount.', ['year' => $this->year]);
            }
            if ($crossing === 'possible') {
                return null;
            }
        }

        return $this->nextYearNotice();
    }

    public function nextYearNotice(): ?string
    {
        if ($this->year >= 2025 && $this->coverage() !== null && ! $this->hasUnverifiedCreditNotes()
            && $this->subtotal() > YearlyParameter::vatExemptionLimit($this->year)) {
            return __($this->year === $this->asOfYear
                ? 'Turnover exceeded €15,000 in :year. If the total remains above that amount at year end, Article 58 requires VAT from January 1, :nextYear.'
                : 'Turnover exceeded €15,000 in :year. Article 58 requires VAT from January 1, :nextYear.', [
                    'year' => $this->year,
                    'nextYear' => $this->year + 1,
                ]);
        }

        return null;
    }

    public function advisory(): ?string
    {
        if ($this->hasUnverifiedCreditNotes()) {
            return __('Confirm the original invoice and eligible amount of the credit notes before assessing the VAT thresholds.');
        }

        if ($this->coverage() !== null && $this->year >= 2026
            && $this->inYearCrossing() === 'possible') {
            return __('Turnover may have exceeded €18,750. Confirm the order of the transactions on that day.');
        }

        return null;
    }

    /** @return array{label: string, amount: int, percent: float, status: string, detail: string, notice: ?string, advisory: ?string, usesReceipts: bool} */
    public function threshold(TaxEstimator $estimator): array
    {
        $covered = $this->coverage() !== null;
        $subtotal = $this->subtotal();
        $fallback = $subtotal === null;
        $amount = $subtotal ?? $estimator->income();
        $limit = $fallback || $this->year < 2025 ? $estimator->vatExemptionThreshold($this->through) : YearlyParameter::vatExemptionLimit($this->year);
        $percent = $limit > 0 ? round($amount / $limit * 100, 1) : 0.0;
        $notice = $this->notice();
        $advisory = $this->advisory() ?? ($fallback && $percent >= 80
            ? __('Payments received are approaching or have reached the configured threshold. Review the domestic turnover.')
            : null);

        return [
            'label' => match (true) {
                $fallback => __('VAT exemption · estimate'),
                $this->hasUnverifiedCreditNotes() => __('VAT exemption · subtotal to review'),
                $covered => __('VAT exemption · confirmed domestic turnover'),
                default => __('VAT exemption · partial subtotal'),
            },
            'amount' => $amount,
            'percent' => $percent,
            'status' => match (true) {
                $notice !== null => 'danger',
                $advisory !== null || ($covered && $amount >= 1200000) => 'warning',
                default => 'ok',
            },
            'detail' => __(':amount of :limit in :year. :summary', [
                'amount' => Money::format($amount),
                'limit' => Money::format($limit),
                'year' => $this->year,
                'summary' => $this->summary(),
            ]),
            'notice' => $notice,
            'advisory' => $advisory,
            'usesReceipts' => $fallback,
        ];
    }

    public static function canReview(): bool
    {
        return CurrentAccount::get() !== null && Gate::allows('viewAny', FiscalDocument::class);
    }

    public static function canConfirm(): bool
    {
        return self::canReview() && ! CurrentAccount::readOnly() && Gate::allows('update', new FiscalDocument);
    }

    public function confirmDocument(int $id, string $revision, string $operationOn, ?int $eligibleCents): void
    {
        $this->write(function () use ($id, $revision, $operationOn, $eligibleCents): void {
            $document = DocumentedTurnover::billingDocuments()->lockForUpdate()->find($id) ?? abort(404);
            $row = self::forYear($this->year)->documents[$id] ?? null;
            if ($row === null || $row['revision'] !== $revision) {
                throw ValidationException::withMessages(['operation_on' => __('The document has changed. Reopen the classification.')]);
            }

            validator(['operation_on' => $operationOn], [
                'operation_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:today'],
            ], ['operation_on.*' => __('Enter a valid operation date no later than today.')])->validate();

            if ($eligibleCents !== null && ($eligibleCents < 0 || ($document->currency === 'EUR' && $eligibleCents > abs($document->net_amount)))) {
                throw ValidationException::withMessages(['eligible_amount' => __('Enter a valid eligible amount up to the document’s net amount in EUR.')]);
            }

            TerritorialTurnoverReview::query()->updateOrCreate(['fiscal_document_id' => $id], [
                'operation_on' => $operationOn,
                'eligible_cents' => $eligibleCents,
                'source_revision' => $row['source'],
                'review_revision' => (string) Str::uuid(),
                'confirmed_by' => auth()->id(),
            ]);
        });
    }

    public function confirmCoverage(string $revision): void
    {
        $this->write(function () use ($revision): void {
            $report = self::forYear($this->year);

            if ($report->revision() !== $revision || $report->unclassifiedCount() > 0 || $this->year > $report->through->year) {
                throw ValidationException::withMessages(['complete' => __('Review the unclassified documents and confirm the coverage again.')]);
            }

            TerritorialTurnoverCoverage::query()->updateOrCreate(['year' => $this->year], [
                'through_on' => $report->through->toDateString(),
                'source_revision' => $report->sourceRevisionForYear(),
                'confirmed_by' => auth()->id(),
            ]);
        });
    }

    public function revokeCoverage(): void
    {
        $this->write(fn () => TerritorialTurnoverCoverage::query()->where('year', $this->year)->first()?->delete());
    }

    private function write(callable $callback): void
    {
        abort_unless(self::canConfirm(), 403);

        DB::transaction(function () use ($callback): void {
            Account::query()->lockForUpdate()->findOrFail(CurrentAccount::id());
            $callback();
        }, attempts: 3);
    }

    private function sourceRevisionForYear(): string
    {
        return self::hash([
            CurrentAccount::id(),
            $this->year,
            array_column($this->documents, 'revision'),
            array_column($this->documents, 'credit_verified'),
        ]);
    }

    private function hasUnverifiedCreditNotes(): bool
    {
        return in_array(false, array_column($this->documents, 'credit_verified'), true);
    }

    private function inYearCrossing(): ?string
    {
        $days = [];

        foreach ($this->documents as $row) {
            $review = $row['review'];
            if ($review?->eligible_cents === null) {
                continue;
            }

            $day = $review->operation_on->toDateString();
            $days[$day] ??= ['invoices' => 0, 'credits' => 0];
            $kind = $row['document']->type === FiscalDocumentType::CreditNote ? 'credits' : 'invoices';
            $days[$day][$kind] += $review->eligible_cents;
        }

        ksort($days);
        $limit = YearlyParameter::vatExemptionTolerance($this->year);
        $balance = 0;
        $possible = false;

        foreach ($days as $day) {
            // The daily close is certain. The peak before credits is possible without a known order.
            $possible = $possible || $balance + $day['invoices'] > $limit;
            $balance += $day['invoices'] - $day['credits'];
            if ($balance > $limit) {
                return 'confirmed';
            }
        }

        return $possible ? 'possible' : null;
    }

    private static function sourceRevision(
        FiscalDocument $document,
        ?FiscalDocument $original = null,
        ?TerritorialTurnoverReview $originalReview = null,
    ): string {
        return self::hash([$document->getRawOriginal(), $original?->getRawOriginal(), $originalReview?->review_revision]);
    }

    private static function hash(array $values): string
    {
        return hash('sha256', serialize($values));
    }
}
