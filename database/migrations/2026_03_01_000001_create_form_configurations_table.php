<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('form_configurations', function (Blueprint $table) {
            $table->id();
            $table->string('form_name')->index();
            $table->enum('scope', ['system', 'user']);
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->json('fieldsets')->nullable();
            $table->json('grid_columns')->nullable();
            $table->json('tab_order')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['form_name', 'scope', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_configurations');
    }
};
