<?php

namespace App\Support;

use App\Enums\BankTransactionStatus;
use App\Models\BankTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FiscalReserve
{
    public const INVALID_TRANSFER = 'Escolhe uma saída do extrato classificada como «Transferência minha».';

    public function __construct(private readonly FinanceSettings $settings) {}

    /** @return array{linked: bool, transfer: ?BankTransaction, covered: int, unverified: int} */
    public function evidence(): array
    {
        $saved = $this->settings->taxReserveTransfer();
        $transfer = $saved === null ? null : $this->eligibleTransfers()->find($saved['id']);

        if ($transfer !== null && $this->snapshot($transfer) !== $saved) {
            $transfer = null;
        }

        $reserved = $this->settings->taxReservedAmount();
        $covered = $transfer === null ? 0 : min($reserved, $transfer->absolute_amount);

        return [
            'linked' => $saved !== null,
            'transfer' => $transfer,
            'covered' => $covered,
            'unverified' => $reserved - $covered,
        ];
    }

    /** @return Builder<BankTransaction> */
    public function eligibleTransfers(): Builder
    {
        return BankTransaction::query()
            ->where('status', BankTransactionStatus::OwnTransfer)
            ->where('amount', '<', 0)
            ->whereDate('booked_on', '<=', today());
    }

    public function save(int $amount, ?int $transferId): void
    {
        abort_if(CurrentAccount::readOnly(), 403);

        if ($transferId !== null && $amount === 0) {
            throw ValidationException::withMessages(['amount' => 'Indica um montante superior a zero para associar a transferência.']);
        }

        DB::transaction(function () use ($amount, $transferId): void {
            $transfer = $transferId === null ? null : $this->eligibleTransfers()->lockForUpdate()->find($transferId);

            if ($transferId !== null && $transfer === null) {
                throw ValidationException::withMessages(['transfer_id' => self::INVALID_TRANSFER]);
            }

            $this->settings->set([
                'tax_reserved_amount' => $amount,
                'tax_reserve_transfer' => $transfer === null ? null : $this->snapshot($transfer),
            ]);
        });
    }

    /** @return array{id: int, amount: int, bookedOn: string} */
    private function snapshot(BankTransaction $transfer): array
    {
        return ['id' => $transfer->id, 'amount' => $transfer->amount, 'bookedOn' => $transfer->booked_on->toDateString()];
    }
}
