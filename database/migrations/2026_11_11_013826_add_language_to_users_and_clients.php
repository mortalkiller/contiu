<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'clients'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->string('language', 16)->default('pt-PT'));
        }
    }

    public function down(): void
    {
        foreach (['clients', 'users'] as $table) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn('language'));
        }
    }
};
