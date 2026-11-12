<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** When the user agreed to news and tips by email (optional at sign-up); null: no consent. */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('marketing_opt_in_at')->nullable()->after('terms_version');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('marketing_opt_in_at');
        });
    }
};
