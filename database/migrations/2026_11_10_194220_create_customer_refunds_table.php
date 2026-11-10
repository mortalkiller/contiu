<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_refunds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bank_transaction_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('amount');
            $table->date('paid_on');
            $table->string('reference', 120);
            $table->timestamps();

            foreach (['receivable_id' => 'receivables', 'credit_note_id' => 'fiscal_documents', 'invoice_id' => 'fiscal_documents'] as $column => $target) {
                $table->foreignId($column);
                $table->index(['account_id', $column], "customer_refunds_{$column}_idx");
                $table->foreign(['account_id', $column], "customer_refunds_{$column}_fk")
                    ->references(['account_id', 'id'])->on($target)->noActionOnDelete();
            }
            $table->unique(['account_id', 'credit_note_id', 'reference'], 'customer_refunds_note_reference_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_refunds');
    }
};
