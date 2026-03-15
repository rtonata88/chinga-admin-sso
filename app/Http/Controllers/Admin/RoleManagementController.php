<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\RoleManagementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleManagementController extends Controller
{
    public function __construct(
        protected RoleManagementService $roleService
    ) {}

    public function listRoles(Request $request): JsonResponse
    {
        $this->checkPermission($request);

        $tenant = $this->getTenantOrFail();
        $roles = $this->roleService->getAssignableRoles($request->user(), $tenant->id);

        return response()->json([
            'success' => true,
            'data' => $roles->map(fn ($role) => [
                'name' => $role->name,
                'display_name' => $role->display_name,
                'description' => $role->description,
            ])->values(),
        ]);
    }

    public function getUserRoles(Request $request, string $uuid): JsonResponse
    {
        $this->checkPermission($request);

        $tenant = $this->getTenantOrFail();
        $user = User::where('uuid', $uuid)->firstOrFail();

        $roles = $user->roles()
            ->wherePivot('tenant_id', $tenant->id)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $roles->map(fn ($role) => [
                'name' => $role->name,
                'display_name' => $role->display_name,
                'description' => $role->description,
            ])->values(),
        ]);
    }

    public function assignRole(Request $request, string $uuid): JsonResponse
    {
        $this->checkPermission($request);

        $request->validate(['role' => 'required|string']);

        $tenant = $this->getTenantOrFail();
        $target = User::where('uuid', $uuid)->firstOrFail();

        try {
            $this->roleService->assignRole($request->user(), $target, $request->input('role'), $tenant->id);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Role assigned successfully.',
        ]);
    }

    public function removeRole(Request $request, string $uuid, string $role): JsonResponse
    {
        $this->checkPermission($request);

        $tenant = $this->getTenantOrFail();
        $target = User::where('uuid', $uuid)->firstOrFail();

        try {
            $this->roleService->removeRole($request->user(), $target, $role, $tenant->id);
        } catch (AuthorizationException $e) {
            abort(403, $e->getMessage());
        }

        return response()->json([
            'success' => true,
            'message' => 'Role removed successfully.',
        ]);
    }

    private function checkPermission(Request $request): void
    {
        if (! $request->user()->hasPermission('users.manage-roles')) {
            abort(403, 'You do not have permission to manage roles.');
        }
    }

    private function getTenantOrFail(): \App\Models\Tenant
    {
        $tenant = app('current_tenant');
        if (! $tenant) {
            abort(422, 'Tenant context is required for this operation.');
        }
        return $tenant;
    }
}
