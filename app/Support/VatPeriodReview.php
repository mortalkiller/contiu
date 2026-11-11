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
    // Confirmations hash the original classification reasons, regardless of the display locale.
    private const HASH_REASONS = [
        'Voided document' => 'Documento anulado',
        'Receipt of payment' => 'Recibo de pagamento',
        'Currency other than EUR' => 'Moeda diferente de EUR',
        'Adjustment to confirm' => 'Regularização a confirmar',
        'Issue and operation in different periods' => 'Emissão e operação em períodos diferentes',
        'Document amounts to check' => 'Montantes do documento por conferir',
        'VAT treatment to confirm' => 'Enquadramento de IVA por confirmar',
        'VAT shown on the document' => 'IVA inscrito no documento',
    ];

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
            throw new InvalidArgumentException(__('The period must be a calendar month or quarter.'));
        }

        $accountId = CurrentAccount::id();
        $start = Period::of($year, $month)->start;
        $from = $start->toDateString();
        $until = $start->addMonths($months)->toDateString();
        $documents = FiscalDocument::query()
            // A draft is not a document yet, and a quote, a pro forma or a transport guide never carries VAT to declare.
            ->where('status', '!=', FiscalDocumentStatus::Draft)
            ->whereIn('type', [...FiscalDocumentType::billing(), FiscalDocumentType::Receipt])
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
            $documents->map(fn (array $row): array => [...$row, 'reason' => __($row['reason'])]),
            $supported->where('document.direction', FiscalDocumentDirection::Issued)->sum('document.vat_amount'),
            $supported->where('document.direction', FiscalDocumentDirection::Received)->sum('document.vat_amount'),
            VatPeriodConfirmation::whereDate('starts_on', $from)->where('months', $months)->first(),
            $accountId,
            hash('sha256', json_encode($documents->map(fn (array $row): array => [
                $row['document']->getRawOriginal(), $row['status'], self::HASH_REASONS[$row['reason']],
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
            throw ValidationException::withMessages(['reference' => __('Review the totals and enter the source of the VAT calculation.')]);
        }

        Validator::make(['dueOn' => $dueOn, 'paidOn' => $paidOn], [
            'dueOn' => ['nullable', 'date_format:Y-m-d'],
            'paidOn' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ], [
            'dueOn.date_format' => __('Enter a valid due date.'),
            'paidOn.date_format' => __('Enter a valid payment date.'),
            'paidOn.before_or_equal' => __('The payment date cannot be in the future.'),
        ])->validate();

        if ($paidOn !== null && $liquidated - $deductible + $adjustments <= 0) {
            throw ValidationException::withMessages(['paidOn' => __('A payment date requires an amount due.')]);
        }

        DB::transaction(function () use ($liquidated, $deductible, $adjustments, $reference, $dueOn, $paidOn): void {
            Account::query()->lockForUpdate()->findOrFail(CurrentAccount::id());
            $current = self::forPeriod($this->start->year, $this->start->month, $this->months);

            if ($this->revision() !== $current->revision()) {
                throw ValidationException::withMessages(['reference' => __('The period has changed. Reopen the review before confirming.')]);
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
            $document->status === FiscalDocumentStatus::Voided => ['excluded', 'Voided document'],
            $document->type === FiscalDocumentType::Receipt => ['excluded', 'Receipt of payment'],
            $document->currency !== 'EUR' => ['excluded', 'Currency other than EUR'],
            $document->type === FiscalDocumentType::CreditNote => ['unresolved', 'Adjustment to confirm'],
            $issue < $from || $issue >= $until || ($operation !== null && ($operation < $from || $operation >= $until)) => ['unresolved', 'Issue and operation in different periods'],
            $document->net_amount < 0 || $document->vat_amount < 0 || $document->total_amount - $document->net_amount !== $document->vat_amount => ['unresolved', 'Document amounts to check'],
            $document->vat_amount === 0 => ['unresolved', 'VAT treatment to confirm'],
            default => ['supported', 'VAT shown on the document'],
        };

        return ['document' => $document, 'status' => $status, 'reason' => $reason];
    }
}
