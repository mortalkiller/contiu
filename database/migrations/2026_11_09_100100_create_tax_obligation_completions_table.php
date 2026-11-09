<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tax obligations marked as paid / delivered on the "Este mês" card, one row per account and
 * obligation key (ss:2026-11, vat-reverse:2026-10, …).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_obligation_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->timestamp('completed_at');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['account_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_obligation_completions');
    }
};
