<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ensure the required roles exist (seeders run after migrations)
        $superAdminRole = DB::table('roles')->where('name', 'platform_super_admin')->first();
        if (! $superAdminRole) {
            DB::table('roles')->insert([
                'name' => 'platform_super_admin',
                'display_name' => 'Platform Super Admin',
                'description' => 'Full platform access with system configuration',
                'is_platform_role' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $superAdminRole = DB::table('roles')->where('name', 'platform_super_admin')->first();
        }

        $adminRole = DB::table('roles')->where('name', 'platform_admin')->first();
        if (! $adminRole) {
            DB::table('roles')->insert([
                'name' => 'platform_admin',
                'display_name' => 'Platform Admin',
                'description' => 'Platform management and tenant oversight',
                'is_platform_role' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $adminRole = DB::table('roles')->where('name', 'platform_admin')->first();
        }

        if ($superAdminRole) {
            $superAdmins = DB::table('users')->where('is_super_admin', true)->get();
            foreach ($superAdmins as $user) {
                DB::table('user_roles')->insertOrIgnore([
                    'user_id' => $user->id,
                    'role_id' => $superAdminRole->id,
                    'tenant_id' => null,
                    'created_at' => now(),
                ]);
            }
        }

        if ($adminRole) {
            $admins = DB::table('users')
                ->where('is_admin', true)
                ->where('is_super_admin', false)
                ->get();

            foreach ($admins as $user) {
                DB::table('user_roles')->insertOrIgnore([
                    'user_id' => $user->id,
                    'role_id' => $adminRole->id,
                    'tenant_id' => null,
                    'created_at' => now(),
                ]);
            }
        }

        // Drop old admin flag columns
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_admin', 'is_super_admin']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false)->after('status');
            $table->boolean('is_super_admin')->default(false)->after('is_admin');
        });

        // Restore admin flags from RBAC
        $superAdminRole = DB::table('roles')->where('name', 'platform_super_admin')->first();
        $adminRole = DB::table('roles')->where('name', 'platform_admin')->first();

        if ($superAdminRole) {
            $userIds = DB::table('user_roles')
                ->where('role_id', $superAdminRole->id)
                ->pluck('user_id');

            DB::table('users')->whereIn('id', $userIds)->update([
                'is_admin' => true,
                'is_super_admin' => true,
            ]);
        }

        if ($adminRole) {
            $userIds = DB::table('user_roles')
                ->where('role_id', $adminRole->id)
                ->pluck('user_id');

            DB::table('users')->whereIn('id', $userIds)->update([
                'is_admin' => true,
            ]);
        }
    }
};
