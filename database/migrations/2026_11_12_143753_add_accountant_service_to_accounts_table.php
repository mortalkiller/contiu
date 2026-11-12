<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The certified-accountant service (AccountantService): which one the account asked for
 * (simplified | organised) and when, so the partner can be introduced, with or without a Stripe
 * subscription to it yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('accountant_service')->nullable()->after('billing_exempt');
            $table->timestamp('accountant_service_requested_at')->nullable()->after('accountant_service');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn(['accountant_service', 'accountant_service_requested_at']);
        });
    }
};
