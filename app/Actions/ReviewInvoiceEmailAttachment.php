<?php

namespace App\Actions;

use App\Enums\FiscalDocumentDirection;
use App\Models\Account;
use App\Models\Expense;
use App\Models\FiscalDocument;
use App\Models\InvoiceEmailAttachment;
use App\Support\AccountantAccess;
use App\Support\CurrentAccount;
use App\Support\ExpenseDocumentCsv;
use App\Support\ExpenseDocumentQr;
use App\Support\ExpenseEvidence;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/** @phpstan-import-type ExpenseDocumentRow from ExpenseDocumentCsv */
final class ReviewInvoiceEmailAttachment
{
    public function __construct(
        private ExpenseDocumentQr $qr,
        private ImportExpenseDocuments $import,
    ) {}

    public static function canReview(): bool
    {
        $account = CurrentAccount::get();

        return config('contiu.invoice_email_inbox_enabled')
            && $account !== null
            && AccountantAccess::canManage($account)
            && Gate::allows('viewAny', Expense::class);
    }

    public static function canAttach(): bool
    {
        return self::canReview() && ! CurrentAccount::readOnly() && Gate::allows('update', new Expense);
    }

    /**
     * @return array{state: string, row: ?ExpenseDocumentRow, suggested: ?int, options: array<int, string>}
     */
    public function preview(InvoiceEmailAttachment $attachment): array
    {
        abort_unless(self::canReview(), 403);
        abort_unless((int) $attachment->account_id === CurrentAccount::id(), 404);

        $row = $this->readQr($attachment);
        $result = ['state' => 'manual', 'row' => $row, 'suggested' => null, 'options' => []];
        if ($row === null) {
            return $result;
        }

        try {
            $document = $this->previousDocument($row);
        } catch (DomainException) {
            return array_replace($result, ['state' => 'conflict']);
        }

        if ($document === null && $this->possibleDuplicate($row)) {
            return array_replace($result, ['state' => 'possible_duplicate']);
        }

        $linked = $document?->links?->expense;
        if ($linked !== null) {
            return array_replace($result, [
                'state' => 'matched',
                'suggested' => $linked->id,
                'options' => [$linked->id => self::expenseLabel($linked)],
            ]);
        }

        $candidates = $this->import->candidateExpenses($row);
        $exact = $candidates->filter(fn (Expense $expense): bool => $expense->invoice_number === $row['number']);

        return array_replace($result, [
            'state' => 'qr',
            'suggested' => $exact->count() === 1 ? $exact->first()->id : null,
            'options' => $exact->concat($candidates->diff($exact))->take(20)
                ->mapWithKeys(fn (Expense $expense): array => [$expense->id => self::expenseLabel($expense)])->all(),
        ]);
    }

    public function attach(int $attachmentId, int $expenseId): void
    {
        abort_unless(self::canAttach(), 403);
        $proof = null;
        $disk = Storage::disk(ExpenseEvidence::DISK);

        try {
            DB::transaction(function () use ($attachmentId, $expenseId, $disk, &$proof): void {
                Account::query()->lockForUpdate()->findOrFail(CurrentAccount::id());
                $attachment = InvoiceEmailAttachment::query()->lockForUpdate()->findOrFail($attachmentId);
                $expense = Expense::query()->lockForUpdate()->findOrFail($expenseId);
                Gate::authorize('update', $expense);

                if ($attachment->expense_id !== null) {
                    if ((int) $attachment->expense_id !== $expenseId) {
                        throw new DomainException(__('This PDF has already been attached to another expense.'));
                    }

                    return;
                }

                $source = Storage::disk(InvoiceEmailAttachment::DISK);
                if (! $source->exists($attachment->storagePath())) {
                    throw new DomainException(__('The inbox PDF is unavailable.'));
                }

                $row = $this->readQr($attachment);
                if ($row !== null) {
                    $this->confirmQr($row, $expense);
                }

                if ($expense->proof_path === null) {
                    $stream = $source->readStream($attachment->storagePath());
                    if (! is_resource($stream)) {
                        throw new DomainException(__('The inbox PDF is unavailable.'));
                    }

                    $proof = ExpenseEvidence::directory(CurrentAccount::id()).'/'.Str::uuid().'.pdf';
                    try {
                        if (! $disk->put($proof, $stream, 'private')) {
                            throw new DomainException(__('Could not save the supporting document.'));
                        }
                    } finally {
                        fclose($stream);
                    }

                    $expense->forceFill(['proof_path' => $proof])->save();
                }

                $attachment->expense()->associate($expense)->save();
            });
        } catch (Throwable $exception) {
            if ($proof !== null) {
                $disk->delete($proof);
            }

            throw $exception;
        }
    }

    /** @param ExpenseDocumentRow $row */
    private function confirmQr(array $row, Expense $expense): void
    {
        $document = $this->previousDocument($row);
        if ($document?->links !== null) {
            if ((int) $document->links->expense_id !== (int) $expense->id) {
                throw new DomainException(__('This invoice is already linked to another expense.'));
            }

            return;
        }

        if ($document !== null) {
            if (! $this->import->candidateExpenses($row)->contains(fn (Expense $candidate): bool => $candidate->is($expense))) {
                throw new DomainException(__('The selected expense is no longer available for this invoice.'));
            }

            $document->links()->create(['expense_id' => $expense->id]);

            return;
        }

        if ($this->possibleDuplicate($row)) {
            return;
        }

        Gate::authorize('create', FiscalDocument::class);
        $this->import->confirm($row['party_tax_id'], [$row], [['expense' => (string) $expense->id]]);
    }

    /** @param ExpenseDocumentRow $row */
    private function possibleDuplicate(array $row): bool
    {
        return FiscalDocument::query()
            ->where('direction', FiscalDocumentDirection::Received)
            ->whereRaw('lower(trim(number)) = ?', [mb_strtolower($row['number'])])
            ->whereDate('document_date', $row['document_date'])
            ->where('net_amount', $row['net_amount'])
            ->where('vat_amount', $row['vat_amount'])
            ->where('total_amount', $row['total_amount'])
            ->where(fn (Builder $query): Builder => $query->whereNull('party_tax_id')
                ->orWhereIn(DB::raw('upper(trim(party_tax_id))'), ['', $row['party_tax_id'], 'PT'.$row['party_tax_id']]))
            ->exists();
    }

    /** @param ExpenseDocumentRow $row */
    private function previousDocument(array $row): ?FiscalDocument
    {
        $document = $this->import->previousImport($row['party_tax_id'], $row);

        if (filled($row['atcud'] ?? null) && FiscalDocument::query()
            ->where('direction', FiscalDocumentDirection::Received)
            ->where('atcud', $row['atcud'])
            ->where(fn ($query) => $query->whereNull('party_tax_id')
                ->orWhereIn(DB::raw('upper(trim(party_tax_id))'), [$row['party_tax_id'], 'PT'.$row['party_tax_id']]))
            ->when($document !== null, fn ($query) => $query->whereKeyNot($document->id))
            ->exists()) {
            throw new DomainException(__('This reference has already been imported with different details or links.'));
        }

        return $document;
    }

    /** @return ?ExpenseDocumentRow */
    private function readQr(InvoiceEmailAttachment $attachment): ?array
    {
        if (blank($attachment->qr_text)) {
            return null;
        }

        try {
            $row = $this->qr->parse($attachment->qr_text);
        } catch (DomainException) {
            return null;
        }

        return $row + ['party_name' => $this->import->supplierFor($row['party_tax_id'])?->name];
    }

    public static function expenseLabel(Expense $expense): string
    {
        return implode(' · ', array_filter([
            "#{$expense->id}", $expense->description, $expense->invoice_number,
        ]));
    }
}
