<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        Schema::table('venues', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        Schema::table('voucher_codes', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        Schema::table('security_audit_logs', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        Schema::table('login_attempts', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
        });

        Schema::table('kyc_documents', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        Schema::table('responsible_gambling_settings', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        Schema::table('self_exclusions', function (Blueprint $table) {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });

        // Add tenant_id to oauth_clients if the table exists
        if (Schema::hasTable('oauth_clients')) {
            Schema::table('oauth_clients', function (Blueprint $table) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
            });
        }
    }

    public function down(): void
    {
        $tables = [
            'users', 'venues', 'voucher_codes', 'security_audit_logs',
            'kyc_documents', 'responsible_gambling_settings', 'self_exclusions',
        ];

        foreach ($tables as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropConstrainedForeignId('tenant_id');
            });
        }

        Schema::table('login_attempts', function (Blueprint $table) {
            $table->dropColumn('tenant_id');
        });

        if (Schema::hasTable('oauth_clients') && Schema::hasColumn('oauth_clients', 'tenant_id')) {
            Schema::table('oauth_clients', function (Blueprint $table) {
                $table->dropColumn('tenant_id');
            });
        }
    }
};
