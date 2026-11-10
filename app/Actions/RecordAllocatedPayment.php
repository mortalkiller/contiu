<?php

namespace App\Actions;

use App\Models\OneOffIncome;
use App\Models\Payment;
use App\Models\Receivable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RecordAllocatedPayment
{
    /** @param array<string, mixed> $data @param array<int, int> $amountsByReceivable */
    public function handle(array $data, array $amountsByReceivable): Payment
    {
        return DB::transaction(function () use ($data, $amountsByReceivable): Payment {
            $receivables = Receivable::query()->excludingCancelled()
                ->whereKey(array_keys($amountsByReceivable))->orderBy('id')->lockForUpdate()->get();

            if (count($amountsByReceivable) < 2 || $receivables->count() !== count($amountsByReceivable)
                || array_sum($amountsByReceivable) !== (int) $data['amount']) {
                throw ValidationException::withMessages(['allocations' => 'Escolhe pelo menos duas cobranças e reparte o montante total.']);
            }

            $client = $receivables->first()->client;

            foreach ($receivables as $receivable) {
                $amount = $amountsByReceivable[$receivable->id];

                if ($client === null || $receivable->client?->id !== $client->id || $receivable->billable instanceof OneOffIncome
                    || $amount <= 0 || $amount > $receivable->outstanding) {
                    throw ValidationException::withMessages(['allocations' => 'As cobranças devem ser do mesmo cliente e cada parcela deve caber no saldo em aberto.']);
                }
            }

            $payment = Payment::create([
                ...$data,
                'receivable_id' => $receivables->first()->id,
                'is_split' => true,
                'tax_exempt' => (bool) ($data['tax_exempt'] ?? false),
            ]);

            $payment->allocations()->createMany($receivables->map(fn (Receivable $receivable): array => [
                'receivable_id' => $receivable->id,
                'amount' => $amountsByReceivable[$receivable->id],
                'allocated_on' => $payment->paid_on,
            ])->all());

            return $payment;
        }, attempts: 3);
    }
}
