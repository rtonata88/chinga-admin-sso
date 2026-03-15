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
            // Identity fields
            $table->uuid('uuid')->unique()->after('id');
            $table->string('username', 50)->unique()->nullable()->after('email');

            // Phone verification
            $table->string('phone', 20)->unique()->nullable()->after('username');
            $table->timestamp('phone_verified_at')->nullable()->after('phone');

            // Personal info
            $table->date('date_of_birth')->nullable()->after('phone_verified_at');
            $table->string('country_code', 3)->nullable()->after('date_of_birth');

            // Terms acceptance
            $table->timestamp('terms_accepted_at')->nullable()->after('country_code');

            // Profile
            $table->string('display_name', 100)->nullable()->after('terms_accepted_at');
            $table->string('avatar_url', 500)->nullable()->after('display_name');
            $table->string('timezone', 50)->default('UTC')->after('avatar_url');
            $table->string('language', 10)->default('en')->after('timezone');

            // Account status
            $table->enum('status', ['active', 'suspended', 'banned', 'self_excluded'])->default('active')->after('language');

            // Security - lockout
            $table->unsignedInteger('failed_login_attempts')->default(0)->after('status');
            $table->timestamp('locked_until')->nullable()->after('failed_login_attempts');

            // Last login tracking
            $table->timestamp('last_login_at')->nullable()->after('locked_until');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');

            // Soft deletes
            $table->softDeletes();

            // Indexes
            $table->index('status');
            $table->index('country_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropIndex(['status']);
            $table->dropIndex(['country_code']);
            $table->dropColumn([
                'uuid',
                'username',
                'phone',
                'phone_verified_at',
                'date_of_birth',
                'country_code',
                'terms_accepted_at',
                'display_name',
                'avatar_url',
                'timezone',
                'language',
                'status',
                'failed_login_attempts',
                'locked_until',
                'last_login_at',
                'last_login_ip',
            ]);
        });
    }
};
