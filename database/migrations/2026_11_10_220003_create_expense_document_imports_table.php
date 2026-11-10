<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_document_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id');
            $table->foreignId('fiscal_document_id');
            $table->char('source_key', 64);
            $table->char('row_hash', 64);
            $table->timestamps();

            $table->unique(['account_id', 'source_key']);
            $table->unique(['account_id', 'fiscal_document_id']);
            $table->foreign(['account_id', 'fiscal_document_id'])
                ->references(['account_id', 'id'])->on('fiscal_documents')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_document_imports');
    }
};
