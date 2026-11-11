<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->unique()->constrained()->cascadeOnDelete();
            $table->char('country_code', 2);
            $table->string('street')->nullable();
            $table->string('postal_code', 32)->nullable();
            $table->string('locality')->nullable();
            $table->string('district_code', 6)->nullable();
            $table->string('municipality_code', 6)->nullable();
            $table->string('parish_code', 6)->nullable();
            $table->string('district')->nullable();
            $table->string('parish')->nullable();
            $table->timestamps();

            $table->foreign('country_code')->references('code')->on('countries');

            foreach (['district_code', 'municipality_code', 'parish_code'] as $column) {
                $table->foreign($column)->references('code')->on('portuguese_administrative_areas');
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_addresses');
    }
};
