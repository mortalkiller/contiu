<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The holder's name becomes the first of Pedro's account names (one per line). The manual bank
     * balance goes away: the balance comes from the imported statement.
     */
    public function up(): void
    {
        $holder = DB::table('settings')->where('key', 'account_holder_name')->first();

        if ($holder !== null && ! DB::table('settings')->where('key', 'account_names')->exists()) {
            DB::table('settings')->insert(['key' => 'account_names', 'value' => $holder->value, 'created_at' => now(), 'updated_at' => now()]);
        }

        DB::table('settings')->whereIn('key', ['account_holder_name', 'current_bank_balance', 'current_bank_balance_on'])->delete();
    }

    public function down(): void
    {
        // The removed settings are not restored.
    }
};
