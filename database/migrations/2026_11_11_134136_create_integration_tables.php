<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('category', 24);
            $table->string('provider', 32);
            $table->text('credentials');
            $table->json('settings')->nullable();
            $table->foreignId('connected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('last_synced_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'id']);
            // One provider per kind of integration: an account never has two billing providers at once.
            $table->unique(['account_id', 'category']);
        });

        Schema::create('integration_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('integration_connection_id');
            // Empty while the record only exists at the provider.
            $table->nullableMorphs('record');
            $table->string('remote_type', 32);
            $table->string('remote_id', 64);
            $table->string('state', 16);
            $table->json('remote')->nullable();
            $table->char('remote_hash', 64)->nullable();
            $table->dateTime('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['integration_connection_id', 'remote_type', 'remote_id'], 'integration_records_remote_unique');
            $table->index(['account_id', 'integration_connection_id', 'state'], 'integration_records_state_index');
            $table->foreign(['account_id', 'integration_connection_id'], 'integration_records_connection_foreign')
                ->references(['account_id', 'id'])->on('integration_connections')->cascadeOnDelete();
        });

        Schema::create('integration_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('integration_connection_id');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('kind', 32);
            $table->string('status', 16);
            // Names the intent, so repeating it finds the first attempt instead of starting another.
            $table->string('key', 100)->nullable();
            $table->nullableMorphs('subject');
            $table->string('summary');
            $table->text('detail')->nullable();
            $table->timestamps();

            $table->unique(['account_id', 'key']);
            $table->index(['account_id', 'integration_connection_id', 'created_at'], 'integration_operations_recent_index');
            $table->foreign(['account_id', 'integration_connection_id'], 'integration_operations_connection_foreign')
                ->references(['account_id', 'id'])->on('integration_connections')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_operations');
        Schema::dropIfExists('integration_records');
        Schema::dropIfExists('integration_connections');
    }
};
