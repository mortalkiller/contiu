<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->char('fiscal_country_code', 2)->nullable();
            $table->string('fiscal_party_type', 16)->nullable();
            $table->string('vat_validation_status', 16)->default('pending');
            $table->timestamp('vat_validated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'fiscal_country_code',
                'fiscal_party_type',
                'vat_validation_status',
                'vat_validated_at',
            ]);
        });
    }
};
