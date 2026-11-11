<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('direct_invoice_sales', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('issue_attempt_id')->constrained('invoice_xpress_issue_attempts');
            $table->foreignId('client_id')->constrained();
            $table->foreignId('receivable_id')->nullable()->unique()->constrained();
            $table->uuid('key');
            $table->date('issued_on');
            $table->decimal('vat_rate', 5, 4)->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'issue_attempt_id']);
            $table->unique(['account_id', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direct_invoice_sales');
    }
};
