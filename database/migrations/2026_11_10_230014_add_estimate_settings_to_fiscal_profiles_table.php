<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fiscal_profiles', function (Blueprint $table) {
            $table->boolean('vat_exempt')->nullable();
            $table->unsignedBigInteger('vat_exemption_threshold')->nullable();
            $table->boolean('ss_contribution_exempt')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fiscal_profiles', function (Blueprint $table) {
            $table->dropColumn(['vat_exempt', 'vat_exemption_threshold', 'ss_contribution_exempt']);
        });
    }
};
