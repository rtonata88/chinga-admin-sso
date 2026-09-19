<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-client scope restriction (PRD §4 P8). Passport 13's Client model
 * already casts and honours a `scopes` attribute (Client::hasScope filters
 * token scopes through it) but ships no column. NULL keeps a client
 * unrestricted, so every existing client, including Fantasy's and the
 * SSO-internal one, behaves exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->json('scopes')->nullable()->after('grant_types');
        });
    }

    public function down(): void
    {
        Schema::table('oauth_clients', function (Blueprint $table) {
            $table->dropColumn('scopes');
        });
    }
};
