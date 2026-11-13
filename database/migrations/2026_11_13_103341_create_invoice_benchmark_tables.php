<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_benchmark_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('sha256', 64);
            $table->string('mime', 32);
            $table->unsignedInteger('bytes');
            $table->json('dimensions');
            $table->string('fixture')->nullable();
            $table->longText('ground_truth')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
            $table->index(['account_id', 'user_id', 'sha256']);
        });

        Schema::create('invoice_benchmark_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('document_id')->constrained('invoice_benchmark_documents')->cascadeOnDelete();
            $table->string('mode', 16);
            $table->string('status', 16);
            $table->string('provider', 16)->nullable();
            $table->string('model', 64)->nullable();
            $table->string('region', 8)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->longText('result')->nullable();
            $table->longText('ai_output')->nullable();
            $table->longText('qr_output')->nullable();
            $table->longText('review')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
            $table->unique(['document_id', 'mode']);
        });

        Schema::create('invoice_benchmark_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('run_id')->constrained('invoice_benchmark_runs')->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->json('usage')->nullable();
            $table->json('pricing');
            $table->string('estimated_usd', 24)->nullable();
            $table->timestamp('created_at');
            $table->unique(['run_id', 'attempt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_benchmark_requests');
        Schema::dropIfExists('invoice_benchmark_runs');
        Schema::dropIfExists('invoice_benchmark_documents');
    }
};
