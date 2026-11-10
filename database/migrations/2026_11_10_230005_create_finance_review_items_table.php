<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_review_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fiscal_document_id')->nullable()->constrained()->nullOnDelete();
            $table->string('target_type');
            $table->string('target_label');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('kind');
            $table->text('body');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['account_id', 'created_at']);
            $table->index('expense_id');
            $table->index('fiscal_document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_review_items');
    }
};
