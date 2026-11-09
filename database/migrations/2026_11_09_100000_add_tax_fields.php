<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where suppliers and clients are established, whether a client is a business, whether an expense
 * is goods; and the two exemption switches give way to the new per-account tax settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('suppliers', 'seat')) {
            Schema::table('suppliers', fn (Blueprint $table) => $table->string('seat')->default('pt'));
            Schema::table('clients', function (Blueprint $table) {
                $table->string('seat')->default('pt');
                $table->boolean('is_business')->default(false);
            });
            Schema::table('expenses', fn (Blueprint $table) => $table->boolean('is_goods')->default(false));
        }

        DB::table('settings')->whereIn('key', ['irs_exempt', 'ss_first_year_exempt'])->delete();
    }

    /** The deleted settings are not brought back: their defaults are what the accounts had anyway. */
    public function down(): void
    {
        Schema::table('expenses', fn (Blueprint $table) => $table->dropColumn('is_goods'));
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn(['seat', 'is_business']));
        Schema::table('suppliers', fn (Blueprint $table) => $table->dropColumn('seat'));
    }
};
