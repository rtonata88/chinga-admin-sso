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
        Schema::table('users', function (Blueprint $table) {
            $table->string('sms_mfa_phone', 20)->nullable()->after('two_factor_recovery_codes');
            $table->boolean('sms_mfa_enabled')->default(false)->after('sms_mfa_phone');
            $table->enum('preferred_mfa_method', ['totp', 'sms'])->default('totp')->after('sms_mfa_enabled');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['sms_mfa_phone', 'sms_mfa_enabled', 'preferred_mfa_method']);
        });
    }
};
