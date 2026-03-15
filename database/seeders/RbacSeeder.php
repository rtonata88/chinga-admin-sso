<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RbacSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedRoles();
        $this->seedPermissions();
        $this->seedRolePermissions();
    }

    private function seedRoles(): void
    {
        $roles = [
            ['name' => 'platform_super_admin', 'display_name' => 'Platform Super Admin', 'description' => 'Full platform access with system configuration', 'is_platform_role' => true],
            ['name' => 'platform_admin', 'display_name' => 'Platform Admin', 'description' => 'Platform management and tenant oversight', 'is_platform_role' => true],
            ['name' => 'tenant_admin', 'display_name' => 'Tenant Admin', 'description' => 'Full tenant management access', 'is_platform_role' => false],
            ['name' => 'tenant_manager', 'display_name' => 'Tenant Manager', 'description' => 'Tenant operations management', 'is_platform_role' => false],
            ['name' => 'player', 'display_name' => 'Player', 'description' => 'End-user player account', 'is_platform_role' => false],
        ];

        foreach ($roles as $role) {
            DB::table('roles')->updateOrInsert(
                ['name' => $role['name']],
                array_merge($role, ['created_at' => now(), 'updated_at' => now()])
            );
        }
    }

    private function seedPermissions(): void
    {
        $groups = [
            'tenants' => [
                'tenants.view' => 'View Tenants',
                'tenants.create' => 'Create Tenants',
                'tenants.update' => 'Update Tenants',
                'tenants.delete' => 'Delete Tenants',
                'tenants.manage-status' => 'Manage Tenant Status',
            ],
            'games' => [
                'games.view' => 'View Games',
                'games.create' => 'Create Games',
                'games.update' => 'Update Games',
                'games.delete' => 'Delete Games',
                'games.assign' => 'Assign Games to Tenants',
            ],
            'users' => [
                'users.view' => 'View Users',
                'users.create' => 'Create Users',
                'users.update' => 'Update Users',
                'users.delete' => 'Delete Users',
                'users.manage-status' => 'Manage User Status',
                'users.manage-roles' => 'Manage User Roles',
            ],
            'venues' => [
                'venues.view' => 'View Venues',
                'venues.create' => 'Create Venues',
                'venues.update' => 'Update Venues',
                'venues.delete' => 'Delete Venues',
                'venues.manage-staff' => 'Manage Venue Staff',
                'venues.manage-terminals' => 'Manage Venue Terminals',
            ],
            'voucher-codes' => [
                'voucher-codes.view' => 'View Voucher Codes',
                'voucher-codes.create' => 'Create Voucher Codes',
                'voucher-codes.manage' => 'Manage Voucher Codes',
            ],
            'kyc' => [
                'kyc.view' => 'View KYC Documents',
                'kyc.review' => 'Review KYC Documents',
                'kyc.set-level' => 'Set KYC Levels',
            ],
            'reports' => [
                'reports.view' => 'View Reports',
                'reports.export' => 'Export Reports',
                'reports.revenue' => 'View Revenue Reports',
            ],
            'oauth-clients' => [
                'oauth-clients.view' => 'View OAuth Clients',
                'oauth-clients.create' => 'Create OAuth Clients',
                'oauth-clients.update' => 'Update OAuth Clients',
                'oauth-clients.delete' => 'Delete OAuth Clients',
            ],
            'responsible-gambling' => [
                'responsible-gambling.view' => 'View Responsible Gambling Settings',
                'responsible-gambling.manage' => 'Manage Responsible Gambling Settings',
            ],
        ];

        foreach ($groups as $group => $permissions) {
            foreach ($permissions as $name => $displayName) {
                DB::table('permissions')->updateOrInsert(
                    ['name' => $name],
                    [
                        'display_name' => $displayName,
                        'group' => $group,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }

    private function seedRolePermissions(): void
    {
        $rolePermissions = [
            'platform_super_admin' => '*', // All permissions
            'platform_admin' => [
                'tenants.*', 'games.*', 'users.*', 'venues.*', 'voucher-codes.*',
                'kyc.*', 'reports.*', 'oauth-clients.*', 'responsible-gambling.*',
            ],
            'tenant_admin' => [
                'users.view', 'users.create', 'users.update', 'users.manage-status',
                'venues.*', 'voucher-codes.*', 'kyc.*',
                'reports.view', 'reports.export',
                'oauth-clients.view', 'oauth-clients.create', 'oauth-clients.update', 'oauth-clients.delete',
                'responsible-gambling.view', 'responsible-gambling.manage',
                'games.view',
            ],
            'tenant_manager' => [
                'users.view', 'users.update',
                'venues.view', 'venues.update', 'venues.manage-staff', 'venues.manage-terminals',
                'voucher-codes.*',
                'kyc.view', 'kyc.review',
                'reports.view',
                'responsible-gambling.view',
                'games.view',
            ],
            'player' => [],
        ];

        $allPermissions = DB::table('permissions')->get();
        $roles = DB::table('roles')->get()->keyBy('name');

        foreach ($rolePermissions as $roleName => $permissionPatterns) {
            $role = $roles[$roleName] ?? null;
            if (! $role) {
                continue;
            }

            // Clear existing
            DB::table('role_permissions')->where('role_id', $role->id)->delete();

            if ($permissionPatterns === '*') {
                // All permissions
                $permIds = $allPermissions->pluck('id');
            } else {
                $permIds = collect();
                foreach ($permissionPatterns as $pattern) {
                    if (str_ends_with($pattern, '.*')) {
                        $group = str_replace('.*', '', $pattern);
                        $permIds = $permIds->merge(
                            $allPermissions->where('group', $group)->pluck('id')
                        );
                    } else {
                        $perm = $allPermissions->firstWhere('name', $pattern);
                        if ($perm) {
                            $permIds->push($perm->id);
                        }
                    }
                }
            }

            $inserts = $permIds->unique()->map(fn ($permId) => [
                'role_id' => $role->id,
                'permission_id' => $permId,
            ])->toArray();

            if (! empty($inserts)) {
                DB::table('role_permissions')->insert($inserts);
            }
        }
    }
}
