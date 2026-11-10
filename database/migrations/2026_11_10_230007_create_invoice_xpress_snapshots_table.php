<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_xpress_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('subdomain', 63);
            $table->unsignedBigInteger('provider_id');
            $table->char('source_hash', 64);
            $table->json('source');
            $table->foreignId('fiscal_document_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['account_id', 'subdomain', 'provider_id', 'id'], 'ix_snapshots_identity_revision');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_xpress_snapshots');
    }
};
