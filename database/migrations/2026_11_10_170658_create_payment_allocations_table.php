<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::table('payments')->leftJoin('receivables', function ($join): void {
            $join->on('receivables.id', '=', 'payments.receivable_id')
                ->on('receivables.account_id', '=', 'payments.account_id');
        })->whereNull('receivables.id')->exists()) {
            throw new RuntimeException('Existem recebimentos com uma cobrança em falta ou de outra conta.');
        }

        Schema::table('payments', fn (Blueprint $table) => $table->boolean('is_split')->default(false));

        Schema::create('payment_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id');
            $table->foreignId('payment_id');
            $table->foreignId('receivable_id');
            $table->bigInteger('amount');
            $table->date('allocated_on');
            $table->unique(['account_id', 'payment_id', 'receivable_id'], 'payment_allocations_pair_unique');
            $table->index(['account_id', 'receivable_id']);
            $table->foreign(['account_id', 'payment_id'])->references(['account_id', 'id'])->on('payments')->cascadeOnDelete();
            $table->foreign(['account_id', 'receivable_id'])->references(['account_id', 'id'])->on('receivables')->noActionOnDelete();
        });

        DB::table('payment_allocations')->insertUsing(
            ['account_id', 'payment_id', 'receivable_id', 'amount', 'allocated_on'],
            DB::table('payments')->select('account_id', 'id', 'receivable_id', 'amount', 'paid_on'),
        );
    }

    public function down(): void
    {
        if (DB::table('payments')->where('is_split', true)->exists()) {
            throw new RuntimeException('Existem recebimentos repartidos que não cabem no esquema anterior.');
        }

        Schema::dropIfExists('payment_allocations');
        Schema::table('payments', fn (Blueprint $table) => $table->dropColumn('is_split'));
    }
};
