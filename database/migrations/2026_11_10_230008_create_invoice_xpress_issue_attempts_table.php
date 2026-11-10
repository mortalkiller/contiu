<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_xpress_issue_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('fiscal_document_id')->nullable()->constrained()->nullOnDelete();
            $table->string('intent_key', 100);
            $table->char('provider_target', 64);
            $table->uuid('proprietary_uid')->unique();
            $table->text('snapshot');
            $table->string('state', 24);
            $table->unsignedBigInteger('remote_draft_id')->nullable();
            $table->unsignedBigInteger('provider_invoice_id')->nullable();
            $table->string('document_reference')->nullable();
            $table->string('atcud', 64)->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'intent_key'], 'ix_issue_intent_unique');
            $table->unique(['account_id', 'provider_target', 'remote_draft_id'], 'ix_issue_remote_draft_unique');
            $table->unique(['account_id', 'provider_target', 'provider_invoice_id'], 'ix_issue_invoice_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_xpress_issue_attempts');
    }
};
