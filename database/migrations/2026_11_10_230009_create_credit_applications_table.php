<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_applications', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount');
            $table->date('applied_on');
            $table->foreignId('applied_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('reversed_on')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();

            foreach (['receivable_id' => 'receivables', 'credit_note_id' => 'fiscal_documents', 'invoice_id' => 'fiscal_documents'] as $column => $target) {
                $table->foreignId($column);
                $table->index(['account_id', $column, 'reversed_on'], "credit_applications_{$column}_idx");
                $table->foreign(['account_id', $column], "credit_applications_{$column}_fk")
                    ->references(['account_id', 'id'])->on($target)->noActionOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_applications');
    }
};
