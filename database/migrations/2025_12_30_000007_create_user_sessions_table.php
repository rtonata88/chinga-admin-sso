<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('session_id')->unique();
            $table->string('ip_address', 45);
            $table->text('user_agent');
            $table->string('device_type', 50)->nullable();
            $table->string('browser', 100)->nullable();
            $table->string('platform', 100)->nullable();
            $table->string('country_code', 3)->nullable();
            $table->string('city', 100)->nullable();
            $table->boolean('is_current')->default(false);
            $table->timestamp('last_active_at');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['user_id', 'last_active_at']);
            $table->index(['session_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
    }
};
