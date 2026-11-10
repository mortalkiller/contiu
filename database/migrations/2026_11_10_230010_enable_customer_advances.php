<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->unique(['account_id', 'id'], 'clients_advance_account_unique'));
        Schema::table('payments', function (Blueprint $table): void {
            $table->foreignId('receivable_id')->nullable()->change();
            $table->foreignId('advance_client_id')->nullable();
            $table->index(['account_id', 'advance_client_id']);
            $table->foreign(['account_id', 'advance_client_id'])
                ->references(['account_id', 'id'])->on('clients')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        if (DB::table('payments')->whereNull('receivable_id')->exists()) {
            throw new RuntimeException('Existem sinais de clientes que não cabem no esquema anterior.');
        }

        Schema::table('payments', function (Blueprint $table): void {
            $table->dropForeign(['account_id', 'advance_client_id']);
            $table->dropIndex(['account_id', 'advance_client_id']);
            $table->dropColumn('advance_client_id');
            $table->foreignId('receivable_id')->change();
        });
        Schema::table('clients', fn (Blueprint $table) => $table->dropUnique('clients_advance_account_unique'));
    }
};
