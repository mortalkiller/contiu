<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Bank statement lines imported from CSV, matched to payments or expenses. */
    public function up(): void
    {
        Schema::create('bank_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('hash')->unique();
            $table->date('booked_on');
            $table->text('description');
            $table->decimal('amount', 12, 2); // + credit, − debit
            $table->decimal('balance', 12, 2)->nullable();
            $table->string('status')->default('pending'); // BankTransactionStatus
            $table->nullableMorphs('matched');
            $table->string('import_batch')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_transactions');
    }
};
