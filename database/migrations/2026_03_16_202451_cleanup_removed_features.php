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
        Schema::dropIfExists('kyc_documents');
        Schema::dropIfExists('responsible_gambling_settings');
        Schema::dropIfExists('self_exclusions');
        Schema::dropIfExists('phone_verifications');
        Schema::dropIfExists('login_notifications');
        Schema::dropIfExists('form_configurations');
        Schema::dropIfExists('saved_filters');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'sms_mfa_phone',
                'sms_mfa_enabled',
                'preferred_mfa_method',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Pre-production — no rollback needed.
    }
};
