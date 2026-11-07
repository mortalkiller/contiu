<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A bank line can be explained by several records (a client paying two invoices at once, a
     * platform payout = income − commission), so the single "matched" morph becomes a links table.
     * Each record belongs to one line at most.
     */
    public function up(): void
    {
        Schema::create('bank_transaction_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_transaction_id')->constrained()->cascadeOnDelete();
            $table->morphs('linkable');
            $table->boolean('created_from_line')->default(false); // undo deletes the record
            $table->boolean('marked_paid')->default(false); // undo puts the expense back to unpaid
            $table->timestamps();

            $table->unique(['linkable_type', 'linkable_id']);
        });

        DB::table('bank_transactions')->whereNotNull('matched_id')->orderBy('id')->get()
            ->each(fn (object $line) => DB::table('bank_transaction_links')->insert([
                'bank_transaction_id' => $line->id,
                'linkable_type' => $line->matched_type,
                'linkable_id' => $line->matched_id,
                'created_from_line' => $line->status === 'created',
                'marked_paid' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]));

        DB::table('bank_transactions')->where('status', 'created')->update(['status' => 'matched']);

        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropMorphs('matched');
        });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->nullableMorphs('matched');
        });

        Schema::dropIfExists('bank_transaction_links');
    }
};
