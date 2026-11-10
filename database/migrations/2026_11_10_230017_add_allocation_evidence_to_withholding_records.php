<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withholding_records', function (Blueprint $table): void {
            $table->unsignedBigInteger('payment_allocation_id')->nullable();
            $table->unsignedBigInteger('receivable_id')->nullable();
            $table->unsignedBigInteger('original_document_id')->nullable();
            $table->bigInteger('receivable_amount')->nullable();
            $table->bigInteger('document_net_amount')->nullable();
            $table->date('document_date')->nullable();
            $table->string('document_number')->nullable();
            $table->boolean('settles_receivable')->default(false);

            $table->dropUnique('withholding_payment_unique');
            $table->index(['account_id', 'payment_id'], 'withholding_payment_idx');
            $table->index(['account_id', 'receivable_id'], 'withholding_receivable_idx');
            $table->unique(['account_id', 'payment_allocation_id'], 'withholding_allocation_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('withholding_records')->groupBy('account_id', 'payment_id')->havingRaw('count(*) > 1')->exists()) {
            throw new RuntimeException('Existem retenções de várias afetações do mesmo recebimento.');
        }

        Schema::table('withholding_records', function (Blueprint $table): void {
            $table->dropUnique('withholding_allocation_unique');
            $table->dropIndex('withholding_payment_idx');
            $table->dropIndex('withholding_receivable_idx');
            $table->unique(['account_id', 'payment_id'], 'withholding_payment_unique');

            $table->dropColumn([
                'payment_allocation_id', 'receivable_id', 'original_document_id', 'receivable_amount',
                'document_net_amount', 'document_date', 'document_number', 'settles_receivable',
            ]);
        });
    }
};
