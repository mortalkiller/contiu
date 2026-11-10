<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vat_period_confirmations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->date('starts_on');
            $table->unsignedTinyInteger('months');
            $table->bigInteger('liquidated');
            $table->bigInteger('deductible');
            $table->bigInteger('adjustments');
            $table->date('due_on')->nullable();
            $table->date('paid_on')->nullable();
            $table->string('reference', 500);
            $table->char('source_hash', 64);
            $table->timestamps();

            $table->unique(['account_id', 'starts_on', 'months'], 'vat_confirmation_period_unique');
            $table->index(['account_id', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vat_period_confirmations');
    }
};
