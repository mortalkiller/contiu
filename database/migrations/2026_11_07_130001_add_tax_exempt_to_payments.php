<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Payments marked exempt are left out of the tax estimate (IVA, Segurança Social, IRS). */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->boolean('tax_exempt')->default(false)->after('method');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('tax_exempt');
        });
    }
};
