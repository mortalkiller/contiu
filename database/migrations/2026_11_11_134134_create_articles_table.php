<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('code', 32);
            $table->string('name');
            $table->string('unit', 16)->default('un');
            $table->bigInteger('unit_price');
            $table->unsignedSmallInteger('vat_basis_points')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['account_id', 'id']);
            $table->unique(['account_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
