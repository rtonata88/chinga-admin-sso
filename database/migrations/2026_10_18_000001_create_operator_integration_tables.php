<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Operator integration (docs/platform/operator-integration.md): an operator embeds our
 * games on its own site and launches them for its own players, server to server.
 *
 * - operator_integrations: one per tenant, its signing secret and wallet mode
 *   (hosted: money sits in our wallet, moved by signed transfers; seamless: the
 *   operator keeps it and we call its wallet API on every movement).
 * - users.external_player_id: the operator's id for the player, unique per tenant.
 *   It is the operator's identifier, never ours.
 * - operator_launches: one-time launch codes (stored hashed), exchanged once.
 * - operator_transfers: hosted-mode transfers, idempotent by the operator's reference.
 * - operator_calls: every signed call in or out, for the console and disputes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operator_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();
            $table->uuid('operator_key')->unique();
            $table->text('secret');
            $table->text('previous_secret')->nullable();
            $table->timestamp('secret_rotated_at')->nullable();
            $table->enum('wallet_mode', ['hosted', 'seamless'])->default('hosted');
            $table->string('wallet_base_url', 500)->nullable();
            $table->json('allowed_ips')->nullable();
            $table->json('embed_origins')->nullable();
            $table->enum('status', ['active', 'suspended'])->default('active');
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('external_player_id', 100)->nullable()->after('user_type');
            $table->unique(['tenant_id', 'external_player_id']);
        });

        Schema::create('operator_launches', function (Blueprint $table) {
            $table->id();
            $table->char('code_hash', 64)->unique();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->string('lang', 10)->nullable();
            $table->string('return_url', 500)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('operator_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operator_integration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 100);
            $table->enum('direction', ['in', 'out']);
            $table->decimal('amount', 15, 2);
            $table->char('currency', 3);
            $table->foreignId('wallet_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['operator_integration_id', 'reference']);
        });

        Schema::create('operator_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operator_integration_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('direction', ['in', 'out']);
            $table->string('endpoint', 100);
            $table->string('reference', 100)->nullable();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('outcome', 40);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->char('body_sha256', 64)->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['operator_integration_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_calls');
        Schema::dropIfExists('operator_transfers');
        Schema::dropIfExists('operator_launches');
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'external_player_id']);
            $table->dropColumn('external_player_id');
        });
        Schema::dropIfExists('operator_integrations');
    }
};
