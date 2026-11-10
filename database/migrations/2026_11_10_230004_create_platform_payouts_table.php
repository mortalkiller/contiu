<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_payouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('one_off_income_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('platform_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('platform_name');
            $table->string('title');
            $table->string('reference')->nullable();
            $table->char('currency', 3);
            $table->bigInteger('original_gross');
            $table->bigInteger('original_commission');
            $table->bigInteger('gross_eur');
            $table->bigInteger('commission_eur');
            $table->string('fx_rate', 25)->nullable();
            $table->string('fx_reference')->nullable();
            $table->foreignId('commission_document_id')->nullable()->constrained('fiscal_documents')->nullOnDelete();
            $table->string('commission_document_number')->nullable();
            $table->foreignId('reconciled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('undone_at')->nullable();
            $table->foreignId('undone_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['account_id', 'bank_transaction_id', 'undone_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_payouts');
    }
};
