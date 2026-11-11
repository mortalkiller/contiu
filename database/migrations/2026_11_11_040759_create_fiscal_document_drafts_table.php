<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_document_drafts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('provider_account', 63);
            $table->string('document_type', 32);
            $table->longText('payload');
            $table->longText('preview');
            $table->unsignedBigInteger('remote_id')->nullable();
            $table->foreignId('fiscal_document_id')->nullable();
            $table->uuid('send_key')->unique();
            $table->string('revision', 64);
            $table->string('state', 16)->default('local');
            $table->timestamps();

            $table->unique(['account_id', 'provider', 'provider_account', 'remote_id'], 'fiscal_drafts_remote_unique');
            $table->index(['account_id', 'fiscal_document_id'], 'fiscal_drafts_document_idx');
            $table->foreign(['account_id', 'fiscal_document_id'], 'fiscal_drafts_document_fk')
                ->references(['account_id', 'id'])->on('fiscal_documents')->noActionOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_document_drafts');
    }
};
