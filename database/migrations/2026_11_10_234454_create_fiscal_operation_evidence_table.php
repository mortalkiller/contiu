<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_operation_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fiscal_document_id')->unique();
            $table->foreign(['account_id', 'fiscal_document_id'])->references(['account_id', 'id'])
                ->on('fiscal_documents')->noActionOnDelete();
            $table->unsignedBigInteger('client_id');
            $table->unsignedBigInteger('fiscal_profile_id');
            $table->char('country_code', 2);
            $table->string('vat_number', 32);
            $table->date('operation_on');
            $table->char('reason', 3);
            $table->dateTime('vies_checked_at');
            $table->unsignedBigInteger('net_amount');
            $table->string('source', 16);
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            $table->index(['account_id', 'operation_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_operation_evidence');
    }
};
