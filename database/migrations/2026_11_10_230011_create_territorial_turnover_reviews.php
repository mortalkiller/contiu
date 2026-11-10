<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('territorial_turnover_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id');
            $table->foreignId('fiscal_document_id');
            $table->foreign(['account_id', 'fiscal_document_id'], 'territorial_review_document_fk')
                ->references(['account_id', 'id'])->on('fiscal_documents')->cascadeOnDelete();
            $table->unique(['account_id', 'fiscal_document_id'], 'territorial_review_document_unique');
            $table->date('operation_on');
            $table->unsignedBigInteger('eligible_cents')->nullable();
            $table->char('source_revision', 64);
            $table->uuid('review_revision');
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('territorial_turnover_coverages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unique(['account_id', 'year']);
            $table->date('through_on');
            $table->char('source_revision', 64);
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('territorial_turnover_coverages');
        Schema::dropIfExists('territorial_turnover_reviews');
    }
};
