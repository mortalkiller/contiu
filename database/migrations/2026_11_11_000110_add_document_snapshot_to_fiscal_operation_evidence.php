<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('fiscal_operation_evidence', function (Blueprint $table): void {
            $table->date('document_on')->nullable();
            $table->date('document_operation_on')->nullable();
            $table->string('document_type', 32)->nullable();
            $table->string('document_number')->nullable();
            $table->date('historical_vies_on')->nullable();
            $table->string('historical_vies_reference')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('fiscal_operation_evidence', function (Blueprint $table): void {
            $table->dropColumn([
                'document_on', 'document_operation_on', 'document_type', 'document_number',
                'historical_vies_on', 'historical_vies_reference',
            ]);
        });
    }
};
