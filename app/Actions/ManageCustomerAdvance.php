<?php

namespace App\Actions;

use App\Enums\PaymentMethod;
use App\Models\Client;
use App\Models\Payment;
use App\Models\Receivable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class ManageCustomerAdvance
{
    public function record(int $clientId, int $amount, string $paidOn, PaymentMethod $method): Payment
    {
        abort_unless(auth()->user()?->can('create', Payment::class), 403);
        Validator::make(['paid_on' => $paidOn, 'amount' => $amount], [
            'paid_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'amount' => ['required', 'integer', 'min:1', 'max:99999999999'],
        ])->validate();

        if (! Client::query()->whereKey($clientId)->exists()) {
            throw ValidationException::withMessages(['client_id' => 'Escolhe um cliente desta conta.']);
        }

        return Payment::query()->create([
            'advance_client_id' => $clientId, 'amount' => $amount, 'paid_on' => $paidOn, 'method' => $method,
        ]);
    }

    public function apply(int $paymentId, int $receivableId, string $allocatedOn): void
    {
        DB::transaction(function () use ($paymentId, $receivableId, $allocatedOn): void {
            $payment = Payment::query()->whereNotNull('advance_client_id')->lockForUpdate()->findOrFail($paymentId);
            abort_unless(auth()->user()?->can('update', $payment), 403);

            Validator::make(['allocated_on' => $allocatedOn], [
                'allocated_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$payment->paid_on->toDateString(), 'before_or_equal:today'],
            ])->validate();

            $charge = Receivable::query()->forClient($payment->advance_client_id)
                ->excludingCancelled()->lockForUpdate()->find($receivableId);

            if ($payment->allocations()->exists() || $charge === null || $payment->amount > $charge->outstanding) {
                throw ValidationException::withMessages(['receivable_id' => 'Escolhe uma cobrança do cliente com saldo suficiente e um sinal ainda por afetar.']);
            }

            $payment->allocations()->create([
                'receivable_id' => $charge->id, 'amount' => $payment->amount, 'allocated_on' => $allocatedOn,
            ]);
        });
    }
}
