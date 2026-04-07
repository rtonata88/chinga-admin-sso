<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RoleManagementService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PlatformRoleManagementController extends Controller
{
    public function __construct(
        protected RoleManagementService $roleService
    ) {}

    public function listRoles(Request $request): JsonResponse
    {
        $this->checkPermission($request);

        $roles = $this->roleService->getAssignableRoles($request->user());

        return response()->json([
            'success' => true,
            'data' => $roles->map(fn ($role) => [
                'name' => $role->name,
                'display_name' => $role->display_name,
                'description' => $role->description,
                'is_platform_role' => $role->is_platform_role,
            ])->values(),
        ]);
    }

    public function listUsers(Request $request): JsonResponse
    {
        $query = User::withoutGlobalScope(TenantScope::class)->with(['roles', 'tenant']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
            });
        }

        if ($request->filled('tenant_id')) {
            $query->where('tenant_id', $request->input('tenant_id'));
        }

        if ($request->filled('role')) {
            $roleName = $request->input('role');
            $query->whereHas('roles', fn ($q) => $q->where('name', $roleName));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        $query->orderBy('created_at', 'desc');

        $users = $query->paginate($request->input('per_page', 25));

        return response()->json([
            'success' => true,
            'data' => collect($users->items())->map(fn (User $user) => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
                'tenant_id' => $user->tenant_id,
                'tenant_name' => $user->tenant?->name,
                'roles' => $user->roles->map(fn ($role) => [
                    'name' => $role->name,
                    'display_name' => $role->display_name,
                    'tenant_id' => $role->pivot->tenant_id,
                ])->values(),
                'created_at' => $user->created_at,
                'last_login_at' => $user->last_login_at,
            ]),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    public function createUser(Request $request): JsonResponse
    {
        $this->checkPermission($request);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => 'required|string|min:8',
            'tenant_id' => 'nullable|integer|exists:tenants,id',
        ]);

        $user = User::withoutGlobalScope(TenantScope::class)->create([
            'uuid' => Str::uuid()->toString(),
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'tenant_id' => $validated['tenant_id'] ?? null,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'User created successfully.',
            'data' => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
            ],
        ], 201);
    }

    public function getUserRoles(Request $request, string $uuid): JsonResponse
    {
        $this->checkPermission($request);

        $user = User::withoutGlobalScope(TenantScope::class)
            ->where('uuid', $uuid)
            ->firstOrFail();

        $roles = $user->roles;

        return response()->json([
            'success' => true,
            'data' => $roles->map(fn ($role) => [
                'name' => $role->name,
                'display_name' => $role->display_name,
                'description' => $role->description,
                'is_platform_role' => $role->is_platform_role,
                'tenant_id' => $role->pivot->tenant_id,
            ])->values(),
        ]);
    }

    public function assignRole(Request $request, string $uuid): JsonResponse
    {
        $this->checkPermission($request);

        $request->validate([
            'role' => 'required|string',
            'tenant_id' => 'nullable|integer',
        ]);

        $target = User::withoutGlobalScope(TenantScope::class)
            ->where('uuid', $uuid)
            ->firstOrFail();

        try {
            $this->roleService->assignRole(
                $request->user(),
                $target,
                $request->input('role'),
                $request->input('tenant_id')
            );
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

        $target = User::withoutGlobalScope(TenantScope::class)
            ->where('uuid', $uuid)
            ->firstOrFail();

        $tenantId = $request->query('tenant_id') ? (int) $request->query('tenant_id') : null;

        try {
            $this->roleService->removeRole($request->user(), $target, $role, $tenantId);
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
}
