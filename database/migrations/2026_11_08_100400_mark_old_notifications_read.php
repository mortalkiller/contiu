<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Notifications from before accounts link to URLs without the account (/expenses/5/edit), which
 * now give 404. Rewriting them is not worth it: they are only marked as read, so the bell starts
 * clean and they stay in the list.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notifications')->whereNull('read_at')->update(['read_at' => now()]);
    }

    /** Which ones were unread before is not kept, and they are harmless read. */
    public function down(): void {}
};
