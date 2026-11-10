<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Colour palettes a user made for themselves (Perfil → Aparência). Colours are "#rrggbb".
     */
    public function up(): void
    {
        Schema::create('user_palettes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('primary_color', 7);
            $table->string('navigation_color', 7);
            $table->string('background_color', 7);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_palettes');
    }
};
