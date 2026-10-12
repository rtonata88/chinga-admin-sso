<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kulipi Kuna USSD design (docs/kulipi-kuna/2026-10-12-kulipi-ussd-design.md) U3/U4:
 * pluggable providers per tenant, USSD service codes, which tenants a channel client
 * may act for, per-language channel strings, mobile-money transactions, an SMS log,
 * the identity a mobile-money KYC gives a player, and the subscribers the simulator
 * provider plays (development and demos only; never consulted by a real driver).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_providers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->enum('kind', ['ussd', 'mobile_money', 'kyc', 'sms']);
            $table->string('driver', 40);
            $table->string('name');
            $table->text('config')->nullable();          // encrypted JSON (secrets included)
            $table->enum('status', ['active', 'disabled'])->default('active');
            $table->timestamps();
            $table->index(['tenant_id', 'kind', 'status']);
        });

        Schema::create('ussd_service_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_provider_id')->constrained()->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('game_id')->constrained()->restrictOnDelete();
            $table->string('code', 40);                  // e.g. *123*45#
            $table->enum('status', ['active', 'disabled'])->default('active');
            $table->timestamps();
            $table->unique(['channel_provider_id', 'code']);
        });

        Schema::create('channel_client_tenants', function (Blueprint $table) {
            $table->id();
            $table->char('oauth_client_id', 36);
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['oauth_client_id', 'tenant_id']);
        });

        Schema::create('channel_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('language', 10);
            $table->string('key', 80);
            $table->text('text');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'language', 'key']);
        });

        Schema::create('mobile_money_transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('channel_provider_id')->constrained()->restrictOnDelete();
            $table->enum('direction', ['deposit', 'withdrawal']);
            $table->decimal('amount', 14, 2);
            $table->decimal('fee', 14, 2)->default(0);
            $table->char('currency', 3);
            $table->string('msisdn', 20);
            $table->string('reference', 60)->unique();   // mm_dep_<id> / mm_wd_<id>: ours, idempotent
            $table->string('provider_reference', 120)->nullable();
            $table->enum('status', ['initiated', 'pending', 'succeeded', 'failed', 'unknown', 'reversed'])->default('initiated');
            $table->foreignId('withdrawal_request_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('wallet_transaction_id')->nullable();
            $table->string('last_error')->nullable();
            $table->unsignedInteger('status_checks')->default(0);
            $table->timestamp('next_check_at')->nullable();
            $table->json('callbacks')->nullable();        // every callback, raw, with its signature result
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'next_check_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('sms_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('channel_provider_id')->nullable()->constrained()->nullOnDelete();
            $table->string('msisdn', 20);
            $table->text('text');
            $table->string('purpose', 40);               // e.g. kk.sms.commitment
            $table->enum('status', ['queued', 'sent', 'failed'])->default('queued');
            $table->string('provider_reference', 120)->nullable();
            $table->string('last_error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['msisdn', 'created_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('kyc_status', 20)->nullable()->after('kyc_verified_at');
            $table->string('kyc_provider', 40)->nullable()->after('kyc_status');
            $table->timestamp('kyc_checked_at')->nullable()->after('kyc_provider');
            $table->string('terms_version', 20)->nullable()->after('terms_accepted_at');
        });

        Schema::create('channel_sim_subscribers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('msisdn', 20);
            $table->string('label', 60);                 // what the demo picker shows
            $table->string('name')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->enum('kyc', ['verified', 'unverified', 'unavailable'])->default('verified');
            $table->decimal('money_balance', 14, 2)->default(0);
            $table->string('pin', 10)->default('1234');
            $table->timestamps();
            $table->unique(['tenant_id', 'msisdn']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_sim_subscribers');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['kyc_status', 'kyc_provider', 'kyc_checked_at', 'terms_version']);
        });
        Schema::dropIfExists('sms_messages');
        Schema::dropIfExists('mobile_money_transactions');
        Schema::dropIfExists('channel_messages');
        Schema::dropIfExists('channel_client_tenants');
        Schema::dropIfExists('ussd_service_codes');
        Schema::dropIfExists('channel_providers');
    }
};
