<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_xpress_note_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('original_id')->nullable()->constrained('fiscal_documents')->nullOnDelete();
            $table->foreignId('fiscal_document_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->char('revision', 64);
            $table->string('subdomain', 63);
            $table->uuid('proprietary_uid')->unique();
            $table->text('snapshot');
            $table->string('state', 16);
            $table->unsignedBigInteger('remote_id')->nullable();
            $table->string('document_reference')->nullable();
            $table->string('atcud', 64)->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'revision'], 'ix_note_revision_unique');
            $table->unique(['account_id', 'subdomain', 'remote_id'], 'ix_note_remote_unique');
            $table->index(['account_id', 'original_id', 'state'], 'ix_note_original_state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_xpress_note_attempts');
    }
};
