<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Why a line and its records differ (DifferenceExplanation), so no money stays unexplained. */
    public function up(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->string('difference_explanation')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropColumn('difference_explanation');
        });
    }
};
