<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** When each reminder went out, so the daily run warns only once per receivable. */
    public function up(): void
    {
        Schema::table('receivables', function (Blueprint $table) {
            $table->timestamp('due_soon_notified_at')->nullable();
            $table->timestamp('overdue_notified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('receivables', function (Blueprint $table) {
            $table->dropColumn(['due_soon_notified_at', 'overdue_notified_at']);
        });
    }
};
