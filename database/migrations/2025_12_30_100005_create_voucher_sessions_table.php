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
        Schema::create('voucher_sessions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('voucher_code_id')->constrained()->onDelete('cascade');
            $table->foreignId('terminal_id')
                ->nullable()
                ->constrained('venue_terminals')
                ->onDelete('set null');
            $table->unsignedBigInteger('game_client_id')->nullable(); // OAuth client ID
            $table->string('session_token')->unique();
            $table->string('ip_address', 45)->nullable();
            $table->decimal('balance_start', 15, 2);
            $table->decimal('balance_end', 15, 2)->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->enum('end_reason', [
                'logout',
                'timeout',
                'cashed_out',
                'forced'
            ])->nullable();
            $table->timestamps();

            $table->index('session_token');
            $table->index(['voucher_code_id', 'ended_at']);
        });

        // Add foreign key for current_session_id in voucher_codes
        Schema::table('voucher_codes', function (Blueprint $table) {
            $table->foreign('current_session_id')
                ->references('id')
                ->on('voucher_sessions')
                ->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('voucher_codes', function (Blueprint $table) {
            $table->dropForeign(['current_session_id']);
        });

        Schema::dropIfExists('voucher_sessions');
    }
};
