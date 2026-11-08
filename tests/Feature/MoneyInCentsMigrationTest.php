<?php

use Illuminate\Support\Facades\DB;

it('converts stored euros to cents without losing a cent, and back', function () {
    $migration = require database_path('migrations/2026_11_07_160000_store_money_in_cents.php');
    $migration->down(); // columns back to decimal euros, as before the change

    DB::table('bank_transactions')->insert(['account_id' => $this->account->id, 'hash' => 'h', 'booked_on' => '2026-10-14', 'description' => 'STRIPE', 'amount' => 4.32, 'balance' => 4573.82, 'status' => 'pending', 'import_batch' => 'b', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('settings')->insert(['account_id' => $this->account->id, 'key' => 'current_bank_balance', 'value' => '4573.82', 'created_at' => now(), 'updated_at' => now()]);

    $migration->up();

    expect(DB::table('bank_transactions')->value('amount'))->toBe(432)
        ->and(DB::table('bank_transactions')->value('balance'))->toBe(457382)
        ->and(DB::table('settings')->where('key', 'current_bank_balance')->value('value'))->toBe('457382');
});
