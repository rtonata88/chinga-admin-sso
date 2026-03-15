<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(true);
            $table->json('custom_settings')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'game_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_games');
    }
};
