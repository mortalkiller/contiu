<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('legal_name');
            $table->char('country_code', 2);
            $table->string('vat_number', 64);
            $table->string('vat_regime', 160);
            $table->date('activity_started_on')->nullable();
            $table->boolean('has_dependent_employment')->nullable();
            $table->text('recognised_exemptions')->nullable();
            $table->date('effective_from');
            $table->timestamp('confirmed_at');
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['account_id', 'effective_from', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_profiles');
    }
};
