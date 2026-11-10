<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->string('proof_path')->nullable();
            $table->string('proof_reference')->nullable();
            $table->string('attribution')->nullable();
            $table->boolean('is_reviewed')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table): void {
            $table->dropColumn(['proof_path', 'proof_reference', 'attribution', 'is_reviewed']);
        });
    }
};
