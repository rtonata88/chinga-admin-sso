<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Every settings save for every game, with before and after RTP (Kulipi Kuna K4 design A2, PRD §9). */
    public function up(): void
    {
        Schema::create('game_settings_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('scope', 16);
            $table->json('before');
            $table->json('after');
            $table->decimal('rtp_before', 6, 4)->nullable();
            $table->decimal('rtp_after', 6, 4)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['game_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_settings_audits');
    }
};
