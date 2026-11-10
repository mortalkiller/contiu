<?php

namespace App\Support;

use App\Enums\FiscalDocumentType;
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
    public const ANNUAL_LIMIT = 1500000;

    public const IN_YEAR_LIMIT = 1875000;

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
            ? 'Cobertura por confirmar'
            : 'Cobertura confirmada até '.$coverage->through_on->format('d/m/Y');
    }

    public function subtotalLabel(): string
    {
        return match (true) {
            $this->hasUnverifiedCreditNotes() => 'Subtotal com notas de crédito por validar',
            $this->coverage() === null => 'Subtotal confirmado (parcial)',
            default => 'Volume nacional confirmado',
        };
    }

    public function summary(): string
    {
        return $this->coverageLabel().'. Excluídos: '.$this->excludedCount().' · Por classificar: '.$this->unclassifiedCount().'.';
    }

    public function notice(): ?string
    {
        if ($this->year < 2025 || $this->coverage() === null || $this->hasUnverifiedCreditNotes()) {
            return null;
        }

        if ($this->year >= 2026) {
            $crossing = $this->inYearCrossing();
            if ($crossing === 'confirmed') {
                return 'Ultrapassou 18 750 € em '.$this->year.'. O art. 58.º prevê IVA a partir da operação que excede esse valor.';
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
            && $this->subtotal() > self::ANNUAL_LIMIT) {
            $effect = 'o art. 58.º prevê IVA a partir de 1 de janeiro de '.($this->year + 1).'.';

            return 'Ultrapassou 15 000 € em '.$this->year.'. '
                .($this->year === $this->asOfYear ? 'Se o total no fecho do ano continuar acima desse valor, '.$effect : ucfirst($effect));
        }

        return null;
    }

    public function advisory(): ?string
    {
        if ($this->hasUnverifiedCreditNotes()) {
            return 'Confirma a fatura de origem e o valor elegível das notas de crédito antes de avaliar os limiares de IVA.';
        }

        if ($this->coverage() !== null && $this->year >= 2026
            && $this->inYearCrossing() === 'possible') {
            return 'Pode ter ultrapassado 18 750 €. Confirma a ordem das operações do mesmo dia.';
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
        $limit = $fallback || $this->year < 2025 ? $estimator->vatExemptionThreshold($this->through) : self::ANNUAL_LIMIT;
        $percent = $limit > 0 ? round($amount / $limit * 100, 1) : 0.0;
        $notice = $this->notice();
        $advisory = $this->advisory() ?? ($fallback && $percent >= 80
            ? 'Os recebimentos aproximam-se ou atingiram o limite configurado. Revê o volume de negócios em território nacional.'
            : null);

        return [
            'label' => match (true) {
                $fallback => 'Isenção de IVA · aproximação',
                $this->hasUnverifiedCreditNotes() => 'Isenção de IVA · subtotal a rever',
                $covered => 'Isenção de IVA · volume nacional confirmado',
                default => 'Isenção de IVA · subtotal parcial',
            },
            'amount' => $amount,
            'percent' => $percent,
            'status' => match (true) {
                $notice !== null => 'danger',
                $advisory !== null || ($covered && $amount >= 1200000) => 'warning',
                default => 'ok',
            },
            'detail' => Money::format($amount).' de '.Money::format($limit).' em '.$this->year.'. '.$this->summary(),
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
                throw ValidationException::withMessages(['operation_on' => 'O documento mudou. Volta a abrir a classificação.']);
            }

            validator(['operation_on' => $operationOn], [
                'operation_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:1900-01-01', 'before_or_equal:today'],
            ], ['operation_on.*' => 'Indica uma data de operação válida até hoje.'])->validate();

            if ($eligibleCents !== null && ($eligibleCents < 0 || ($document->currency === 'EUR' && $eligibleCents > abs($document->net_amount)))) {
                throw ValidationException::withMessages(['eligible_amount' => 'Indica um valor elegível válido, até ao valor líquido do documento em EUR.']);
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
                throw ValidationException::withMessages(['complete' => 'Revê os documentos por classificar e volta a confirmar a cobertura.']);
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
        $balance = 0;
        $possible = false;

        foreach ($days as $day) {
            // The daily close is certain. The peak before credits is possible without a known order.
            $possible = $possible || $balance + $day['invoices'] > self::IN_YEAR_LIMIT;
            $balance += $day['invoices'] - $day['credits'];
            if ($balance > self::IN_YEAR_LIMIT) {
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
