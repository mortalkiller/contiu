<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_email_aliases', function (Blueprint $table): void {
            $table->foreignId('account_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('recipient')->unique();
        });

        Schema::create('invoice_email_deliveries', function (Blueprint $table): void {
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->char('message_hash', 64);
            $table->timestamp('received_at');
            $table->primary(['account_id', 'message_hash']);
        });

        Schema::create('invoice_email_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_id')->nullable()->constrained()->nullOnDelete();
            $table->char('message_hash', 64);
            $table->char('pdf_hash', 64);
            $table->uuid('file_id');
            $table->string('filename');
            $table->text('qr_text')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'pdf_hash']);
            $table->foreign(['account_id', 'message_hash'])->references(['account_id', 'message_hash'])
                ->on('invoice_email_deliveries')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_email_attachments');
        Schema::dropIfExists('invoice_email_deliveries');
        Schema::dropIfExists('invoice_email_aliases');
    }
};
