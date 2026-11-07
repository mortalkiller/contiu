<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Only "bank fee" and "other income" explanations remain (they create a record); the others
     * only described money that went through another account, which the company account balance
     * now shows on its own.
     */
    public function up(): void
    {
        DB::table('bank_transactions')
            ->whereNotIn('difference_explanation', ['bank_fee', 'other_income'])
            ->update(['difference_explanation' => null]);
    }

    public function down(): void
    {
        // Nothing to restore.
    }
};
