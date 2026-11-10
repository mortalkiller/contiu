<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_security_declarations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('quarter', 7);
            $table->json('amounts');
            $table->timestamp('recorded_at');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->index(['account_id', 'quarter', 'id'], 'ss_declarations_account_quarter_revision');
        });

        Schema::create('social_security_contributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->date('contribution_month');
            $table->unsignedBigInteger('amount');
            $table->timestamp('confirmed_at');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->index(['account_id', 'contribution_month', 'id'], 'ss_contributions_account_month_revision');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_security_contributions');
        Schema::dropIfExists('social_security_declarations');
    }
};
