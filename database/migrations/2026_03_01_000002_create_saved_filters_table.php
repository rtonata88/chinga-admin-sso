<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_filters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('filterable_type')->index();
            $table->string('name');
            $table->boolean('is_shared')->default(false);
            $table->boolean('is_favorite')->default(false);
            $table->boolean('is_default')->default(false);
            $table->json('criteria');
            $table->json('sort_config')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_filters');
    }
};
