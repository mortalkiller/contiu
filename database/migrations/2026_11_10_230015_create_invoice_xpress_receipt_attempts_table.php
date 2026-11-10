<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_xpress_receipt_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            // Keep the original identities even if a local source is later removed.
            $table->unsignedBigInteger('payment_allocation_id');
            $table->unsignedBigInteger('payment_id');
            $table->unsignedBigInteger('receivable_id');
            $table->string('subdomain', 63);
            $table->unsignedBigInteger('provider_invoice_id');
            $table->string('note', 80)->unique('ix_receipt_note_unique');
            $table->text('intent');
            $table->char('pending_target', 64)->nullable()->unique('ix_receipt_pending_target_unique');
            $table->unsignedBigInteger('reported_receipt_id')->nullable();
            $table->unsignedBigInteger('provider_receipt_id')->nullable();
            $table->string('receipt_number')->nullable();
            $table->string('receipt_atcud', 64)->nullable();
            $table->foreignId('fiscal_document_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['account_id', 'payment_allocation_id'], 'ix_receipt_allocation_unique');
            $table->unique(['account_id', 'payment_id', 'receivable_id'], 'ix_receipt_payment_charge_unique');
            $table->unique(['subdomain', 'provider_receipt_id'], 'ix_receipt_remote_unique');
            $table->index(['account_id', 'subdomain', 'provider_invoice_id'], 'ix_receipt_original_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_xpress_receipt_attempts');
    }
};
