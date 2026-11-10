<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TARGETS = [
        'receivable_id' => 'receivables',
        'payment_id' => 'payments',
        'expense_id' => 'expenses',
        'related_fiscal_document_id' => 'fiscal_documents',
    ];

    public function up(): void
    {
        foreach (['receivables', 'payments', 'expenses'] as $target) {
            Schema::table($target, fn (Blueprint $table) => $table->unique(['account_id', 'id'], "{$target}_fiscal_account_id_unique"));
        }

        Schema::create('fiscal_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('type', 24);
            $table->string('direction', 8);
            $table->string('status', 8)->default('active');
            $table->string('number');
            $table->string('origin');
            $table->date('document_date');
            $table->date('operation_date')->nullable();
            $table->char('currency', 3);
            $table->bigInteger('net_amount');
            $table->bigInteger('vat_amount');
            $table->bigInteger('total_amount');
            $table->string('party_name')->nullable();
            $table->string('party_tax_id', 64)->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'id']);
            $table->index(['account_id', 'document_date']);
        });

        Schema::create('fiscal_document_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id');
            $table->foreignId('fiscal_document_id');
            $table->timestamps();

            $table->unique(['account_id', 'fiscal_document_id'], 'fiscal_links_source_unique');
            $table->foreign(['account_id', 'fiscal_document_id'], 'fiscal_links_source_fk')
                ->references(['account_id', 'id'])->on('fiscal_documents')->cascadeOnDelete();

            foreach (self::TARGETS as $column => $target) {
                $table->foreignId($column)->nullable();
                $table->index(['account_id', $column], "fiscal_links_{$column}_idx");
                // Check at the end of the statement so the existing whole-account cascade can finish.
                $table->foreign(['account_id', $column], "fiscal_links_{$column}_fk")
                    ->references(['account_id', 'id'])->on($target)->noActionOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_document_links');
        Schema::dropIfExists('fiscal_documents');

        foreach (['receivables', 'payments', 'expenses'] as $target) {
            Schema::table($target, fn (Blueprint $table) => $table->dropUnique("{$target}_fiscal_account_id_unique"));
        }
    }
};
