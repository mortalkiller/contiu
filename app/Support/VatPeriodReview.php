<?php

namespace App\Support;

use App\Enums\FiscalDocumentDirection;
use App\Enums\FiscalDocumentStatus;
use App\Enums\FiscalDocumentType;
use App\Exceptions\AccountIsReadOnly;
use App\Models\Account;
use App\Models\FiscalDocument;
use App\Models\VatPeriodConfirmation;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Documentary review in EUR cents, with a separate, explicitly confirmed fiscal result. */
final readonly class VatPeriodReview
{
    /** @param Collection<int, array{document: FiscalDocument, status: 'supported'|'unresolved'|'excluded', reason: string}> $documents */
    private function __construct(
        public CarbonImmutable $start,
        public int $months,
        public Collection $documents,
        public int $issuedVat,
        public int $receivedVat,
        public ?VatPeriodConfirmation $confirmation,
        private int $accountId,
        private string $sourceHash,
    ) {}

    /** The month is the first month of the chosen calendar month or quarter. */
    public static function forPeriod(int $year, int $month, int $months = 1): self
    {
        if (! checkdate($month, 1, $year) || ! in_array($months, [1, 3], true) || ($months === 3 && ($month - 1) % 3 !== 0)) {
            throw new InvalidArgumentException('O período deve ser um mês ou um trimestre civil.');
        }

        $accountId = CurrentAccount::id();
        $start = Period::of($year, $month)->start;
        $from = $start->toDateString();
        $until = $start->addMonths($months)->toDateString();
        $documents = FiscalDocument::query()
            ->where(fn (Builder $query) => $query
                ->where(fn (Builder $issued) => $issued->where('document_date', '>=', $from)->where('document_date', '<', $until))
                ->orWhere(fn (Builder $operated) => $operated->where('operation_date', '>=', $from)->where('operation_date', '<', $until)))
            ->orderBy('document_date')
            ->orderBy('id')
            ->get()
            ->map(fn (FiscalDocument $document): array => self::classify($document, $from, $until));
        $supported = $documents->where('status', 'supported');

        return new self(
            $start,
            $months,
            $documents,
            $supported->where('document.direction', FiscalDocumentDirection::Issued)->sum('document.vat_amount'),
            $supported->where('document.direction', FiscalDocumentDirection::Received)->sum('document.vat_amount'),
            VatPeriodConfirmation::whereDate('starts_on', $from)->where('months', $months)->first(),
            $accountId,
            hash('sha256', json_encode($documents->map(fn (array $row): array => [
                $row['document']->getRawOriginal(), $row['status'], $row['reason'],
            ]), JSON_THROW_ON_ERROR)),
        );
    }

    /** Signed cents. Positive is payable and negative is credit. Null requires review. */
    public function confirmedBalance(): ?int
    {
        return $this->confirmation?->source_hash === $this->sourceHash ? $this->confirmation->balance() : null;
    }

    /** Confirmed cash outflow in cents, before checking whether it has already been paid. */
    public function amountDue(): ?int
    {
        $balance = $this->confirmedBalance();

        return $balance === null ? null : max(0, $balance);
    }

    public function dueOn(): ?CarbonImmutable
    {
        return $this->confirmedBalance() === null ? null : $this->confirmation->due_on;
    }

    public function paidOn(): ?CarbonImmutable
    {
        return $this->confirmedBalance() === null ? null : $this->confirmation->paid_on;
    }

    public function isPaid(): bool
    {
        return $this->paidOn() !== null;
    }

    public function revision(): string
    {
        return hash('sha256', json_encode([
            $this->accountId, $this->start->toDateString(), $this->months, $this->sourceHash, $this->confirmation?->getRawOriginal(),
        ], JSON_THROW_ON_ERROR));
    }

    public function confirm(
        int $liquidated,
        int $deductible,
        int $adjustments,
        string $reference,
        ?string $dueOn = null,
        ?string $paidOn = null,
    ): void {
        if (CurrentAccount::readOnly()) {
            throw new AccountIsReadOnly;
        }

        $reference = trim($reference);
        $dueOn = blank($dueOn) ? null : trim($dueOn);
        $paidOn = blank($paidOn) ? null : trim($paidOn);

        if ($liquidated < 0 || $deductible < 0 || max($liquidated, $deductible, $adjustments) > 99_999_999_999
            || $adjustments < -99_999_999_999 || $reference === '' || mb_strlen($reference) > 500) {
            throw ValidationException::withMessages(['reference' => 'Revê os totais e indica a origem do apuramento.']);
        }

        Validator::make(['dueOn' => $dueOn, 'paidOn' => $paidOn], [
            'dueOn' => ['nullable', 'date_format:Y-m-d'],
            'paidOn' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ], [
            'dueOn.date_format' => 'Indica uma data-limite válida.',
            'paidOn.date_format' => 'Indica uma data de pagamento válida.',
            'paidOn.before_or_equal' => 'A data de pagamento não pode ser futura.',
        ])->validate();

        if ($paidOn !== null && $liquidated - $deductible + $adjustments <= 0) {
            throw ValidationException::withMessages(['paidOn' => 'A data de pagamento exige um montante a pagar.']);
        }

        DB::transaction(function () use ($liquidated, $deductible, $adjustments, $reference, $dueOn, $paidOn): void {
            Account::query()->lockForUpdate()->findOrFail(CurrentAccount::id());
            $current = self::forPeriod($this->start->year, $this->start->month, $this->months);

            if ($this->revision() !== $current->revision()) {
                throw ValidationException::withMessages(['reference' => 'O período mudou. Volta a abrir a revisão antes de confirmar.']);
            }

            ($current->confirmation ?? new VatPeriodConfirmation)
                ->fill([
                    'starts_on' => $this->start->toDateString(),
                    'months' => $this->months,
                    'liquidated' => $liquidated,
                    'deductible' => $deductible,
                    'adjustments' => $adjustments,
                    'due_on' => $dueOn,
                    'paid_on' => $paidOn,
                    'reference' => $reference,
                    'source_hash' => $this->sourceHash,
                ])->save();
        });
    }

    /** @return array{document: FiscalDocument, status: 'supported'|'unresolved'|'excluded', reason: string} */
    private static function classify(FiscalDocument $document, string $from, string $until): array
    {
        $issue = $document->document_date->toDateString();
        $operation = $document->operation_date?->toDateString();

        [$status, $reason] = match (true) {
            $document->status === FiscalDocumentStatus::Voided => ['excluded', 'Documento anulado'],
            $document->type === FiscalDocumentType::Receipt => ['excluded', 'Recibo de pagamento'],
            $document->currency !== 'EUR' => ['excluded', 'Moeda diferente de EUR'],
            $document->type === FiscalDocumentType::CreditNote => ['unresolved', 'Regularização a confirmar'],
            $issue < $from || $issue >= $until || ($operation !== null && ($operation < $from || $operation >= $until)) => ['unresolved', 'Emissão e operação em períodos diferentes'],
            $document->net_amount < 0 || $document->vat_amount < 0 || $document->total_amount - $document->net_amount !== $document->vat_amount => ['unresolved', 'Montantes do documento por conferir'],
            $document->vat_amount === 0 => ['unresolved', 'Enquadramento de IVA por confirmar'],
            default => ['supported', 'IVA inscrito no documento'],
        };

        return ['document' => $document, 'status' => $status, 'reason' => $reason];
    }
}
