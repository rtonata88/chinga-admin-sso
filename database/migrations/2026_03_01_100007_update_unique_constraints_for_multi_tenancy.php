<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Users: replace global unique with per-tenant composite unique.
        // MySQL treats NULL as distinct in unique indexes, so platform users
        // (tenant_id=NULL) won't be DB-enforced for duplicates — this is
        // handled at the application level in CreateNewUser and FortifyServiceProvider.
        Schema::table('users', function (Blueprint $table) {
            // Only drop indexes that exist — the DB may not have them
            if ($this->hasIndex('users', 'users_email_unique')) {
                $table->dropUnique('users_email_unique');
            }
            if ($this->hasIndex('users', 'users_username_unique')) {
                $table->dropUnique('users_username_unique');
            }
            if ($this->hasIndex('users', 'users_phone_unique')) {
                $table->dropUnique('users_phone_unique');
            }

            $table->unique(['tenant_id', 'email'], 'users_tenant_email_unique');
            $table->unique(['tenant_id', 'username'], 'users_tenant_username_unique');
            $table->unique(['tenant_id', 'phone'], 'users_tenant_phone_unique');
        });

        // Venues: update slug uniqueness
        Schema::table('venues', function (Blueprint $table) {
            if ($this->hasIndex('venues', 'venues_slug_unique')) {
                $table->dropUnique('venues_slug_unique');
            }

            $table->unique(['tenant_id', 'slug'], 'venues_tenant_slug_unique');
        });

        // Voucher codes: update code uniqueness
        Schema::table('voucher_codes', function (Blueprint $table) {
            if ($this->hasIndex('voucher_codes', 'voucher_codes_code_unique')) {
                $table->dropUnique('voucher_codes_code_unique');
            }

            $table->unique(['tenant_id', 'code'], 'voucher_codes_tenant_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('voucher_codes', function (Blueprint $table) {
            $table->dropUnique('voucher_codes_tenant_code_unique');
            $table->unique('code');
        });

        Schema::table('venues', function (Blueprint $table) {
            $table->dropUnique('venues_tenant_slug_unique');
            $table->unique('slug');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_tenant_email_unique');
            $table->dropUnique('users_tenant_username_unique');
            $table->dropUnique('users_tenant_phone_unique');
            $table->unique('email');
            $table->unique('username');
            $table->unique('phone');
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $indexes = collect($connection->select("PRAGMA index_list({$table})"));

            return $indexes->contains('name', $indexName);
        }

        $indexes = collect($connection->select("SHOW INDEX FROM {$table}"));

        return $indexes->contains('Key_name', $indexName);
    }
};
