<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** A tax-exempt retainer makes its payments tax-exempt (left out of the tax estimate). */
    public function up(): void
    {
        Schema::table('retainers', function (Blueprint $table) {
            $table->boolean('tax_exempt')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('retainers', function (Blueprint $table) {
            $table->dropColumn('tax_exempt');
        });
    }
};
