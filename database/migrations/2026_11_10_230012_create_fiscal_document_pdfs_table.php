<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_document_pdfs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id');
            $table->foreignId('fiscal_document_id');
            $table->foreignId('invoice_xpress_snapshot_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('storage_key')->unique();
            $table->char('sha256', 64);
            $table->unsignedInteger('size');
            $table->timestamp('created_at');

            $table->index(['account_id', 'fiscal_document_id', 'id'], 'fiscal_pdfs_document_versions');
            $table->foreign(['account_id', 'fiscal_document_id'], 'fiscal_pdfs_document_fk')
                ->references(['account_id', 'id'])->on('fiscal_documents')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_document_pdfs');
    }
};
