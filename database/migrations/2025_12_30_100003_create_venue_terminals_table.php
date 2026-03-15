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
        Schema::create('venue_terminals', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('venue_id')->constrained()->onDelete('cascade');
            $table->string('terminal_code', 50);
            $table->string('name', 100);
            $table->enum('type', ['kiosk', 'tablet', 'terminal', 'pos'])->default('terminal');
            $table->string('api_key'); // Hashed
            $table->enum('status', ['active', 'inactive', 'maintenance'])->default('active');
            $table->timestamp('last_heartbeat_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['venue_id', 'terminal_code']);
            $table->index('status');
            $table->index('api_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('venue_terminals');
    }
};
