<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Receita avulsa: income with no job or retainer behind it (a donation, a refund…). It owns one
     * receivable, paid on the spot, so every total built on receivables and payments includes it.
     */
    public function up(): void
    {
        Schema::create('one_off_incomes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('business_line')->default('Receitas avulsas');
            $table->string('title');
            $table->date('received_on');
            $table->decimal('net_amount', 12, 2);
            $table->decimal('vat_rate', 5, 4)->default(0);
            $table->foreignId('commission_expense_id')->nullable()->constrained('expenses')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('one_off_incomes');
    }
};
