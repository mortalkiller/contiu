<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('withholding_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id');
            $table->foreignId('fiscal_document_id');
            $table->foreignId('payment_id');
            $table->date('paid_on');
            $table->bigInteger('gross_amount');
            $table->bigInteger('retained_amount');
            $table->char('source_hash', 64);
            $table->timestamps();

            $table->unique(['account_id', 'fiscal_document_id'], 'withholding_document_unique');
            $table->unique(['account_id', 'payment_id'], 'withholding_payment_unique');
            $table->index(['account_id', 'paid_on']);
            $table->foreign(['account_id', 'fiscal_document_id'], 'withholding_document_fk')
                ->references(['account_id', 'id'])->on('fiscal_documents')->cascadeOnDelete();
            $table->foreign(['account_id', 'payment_id'], 'withholding_payment_fk')
                ->references(['account_id', 'id'])->on('payments')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('withholding_records');
    }
};
