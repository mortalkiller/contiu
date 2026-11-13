<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The invoices a person dropped on "Importar faturas": the private file, what was read from
     * it and what became of it once confirmed.
     */
    public function up(): void
    {
        Schema::create('invoice_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->uuid('file_id');
            $table->string('filename');
            $table->string('mime', 32);
            $table->unsignedInteger('bytes');
            $table->string('sha256', 64);
            $table->json('dimensions');
            $table->string('status', 16)->index();
            $table->json('data')->nullable();
            $table->json('choices')->nullable();
            $table->json('outcome')->nullable();
            $table->string('error')->nullable();
            $table->foreignId('expense_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fiscal_document_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'sha256']);
            $table->unique(['account_id', 'file_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_imports');
    }
};
