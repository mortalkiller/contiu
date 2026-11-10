<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The palette the user chose: a built-in id ("clean-saas-a") or "custom:{user_palettes.id}".
     * Null means the default Contiu palette.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('palette', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('palette');
        });
    }
};
