<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Emails sent to clients (payment reminders, statements), always after Pedro reviewed them. */
    public function up(): void
    {
        Schema::create('client_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // payment_reminder | statement
            $table->string('to');
            $table->string('subject');
            $table->text('body');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('sent_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_messages');
    }
};
