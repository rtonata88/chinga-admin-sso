<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\SecurityAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserManagementController extends Controller
{
    public function __construct(
        protected SecurityAuditService $auditService
    ) {}

    /**
     * List all users with filters.
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::query();

        // Search
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        // Filter by KYC level
        if ($request->has('kyc_level')) {
            $query->where('kyc_level', $request->input('kyc_level'));
        }

        // Filter by email verified
        if ($request->has('email_verified')) {
            if ($request->boolean('email_verified')) {
                $query->whereNotNull('email_verified_at');
            } else {
                $query->whereNull('email_verified_at');
            }
        }

        // Filter by admin status (via RBAC roles)
        if ($request->has('is_admin')) {
            if ($request->boolean('is_admin')) {
                $query->whereHas('roles', fn ($q) => $q->whereIn('name', ['platform_super_admin', 'platform_admin', 'tenant_admin', 'tenant_manager']));
            } else {
                $query->whereDoesntHave('roles', fn ($q) => $q->whereIn('name', ['platform_super_admin', 'platform_admin', 'tenant_admin', 'tenant_manager']));
            }
        }

        // Sort
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_dir', 'desc');
        $query->orderBy($sortBy, $sortDir);

        $users = $query->paginate($request->input('per_page', 25));

        return response()->json([
            'success' => true,
            'data' => $users->items(),
            'meta' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    /**
     * Get user details.
     */
    public function show(string $uuid): JsonResponse
    {
        $user = User::where('uuid', $uuid)
            ->with(['kycDocuments', 'selfExclusions', 'responsibleGamblingSettings'])
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
                'phone' => $user->phone,
                'display_name' => $user->display_name,
                'avatar_url' => $user->avatar_url,
                'date_of_birth' => $user->date_of_birth?->toDateString(),
                'country_code' => $user->country_code,
                'timezone' => $user->timezone,
                'language' => $user->language,
                'status' => $user->status,
                'kyc_level' => $user->kyc_level,
                'kyc_level_name' => $user->kyc_level_name,
                'is_admin' => $user->isAdmin(),
                'is_super_admin' => $user->isSuperAdmin(),
                'roles' => $user->roles->pluck('name'),
                'email_verified_at' => $user->email_verified_at?->toIso8601String(),
                'phone_verified_at' => $user->phone_verified_at?->toIso8601String(),
                'kyc_verified_at' => $user->kyc_verified_at?->toIso8601String(),
                'terms_accepted_at' => $user->terms_accepted_at?->toIso8601String(),
                'last_login_at' => $user->last_login_at?->toIso8601String(),
                'last_login_ip' => $user->last_login_ip,
                'failed_login_attempts' => $user->failed_login_attempts,
                'locked_until' => $user->locked_until?->toIso8601String(),
                'two_factor_enabled' => $user->two_factor_confirmed_at !== null,
                'sms_mfa_enabled' => $user->sms_mfa_enabled,
                'created_at' => $user->created_at->toIso8601String(),
                'updated_at' => $user->updated_at->toIso8601String(),
                'kyc_documents' => $user->kycDocuments->map(fn ($doc) => [
                    'uuid' => $doc->uuid,
                    'document_type' => $doc->document_type,
                    'document_type_label' => $doc->document_type_label,
                    'status' => $doc->status,
                    'rejection_reason' => $doc->rejection_reason,
                    'created_at' => $doc->created_at->toIso8601String(),
                ]),
                'self_exclusions' => $user->selfExclusions->map(fn ($exc) => [
                    'id' => $exc->id,
                    'type' => $exc->type,
                    'is_active' => $exc->isActive(),
                    'starts_at' => $exc->starts_at->toIso8601String(),
                    'ends_at' => $exc->ends_at?->toIso8601String(),
                ]),
                'responsible_gambling' => $user->responsibleGamblingSettings ? [
                    'daily_deposit_limit' => $user->responsibleGamblingSettings->daily_deposit_limit,
                    'weekly_deposit_limit' => $user->responsibleGamblingSettings->weekly_deposit_limit,
                    'monthly_deposit_limit' => $user->responsibleGamblingSettings->monthly_deposit_limit,
                ] : null,
            ],
        ]);
    }

    /**
     * Update user details.
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $user = User::where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', Rule::unique('users')->where('tenant_id', $user->tenant_id)->ignore($user->id)],
            'username' => ['sometimes', 'nullable', 'string', 'max:50', Rule::unique('users')->where('tenant_id', $user->tenant_id)->ignore($user->id)],
            'phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'date_of_birth' => ['sometimes', 'nullable', 'date'],
            'country_code' => ['sometimes', 'nullable', 'string', 'size:2'],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:50'],
            'language' => ['sometimes', 'nullable', 'string', 'max:10'],
        ]);

        $oldValues = $user->only(array_keys($validated));
        $user->update($validated);

        $this->auditService->log(
            user: $user,
            action: 'admin.user.update',
            description: "Admin updated user {$user->email}",
            oldValues: $oldValues,
            newValues: $validated,
            performedBy: $request->user()
        );

        return response()->json([
            'success' => true,
            'message' => 'User updated successfully.',
            'data' => $user->fresh(),
        ]);
    }

    /**
     * Suspend a user.
     */
    public function suspend(Request $request, string $uuid): JsonResponse
    {
        $user = User::where('uuid', $uuid)->firstOrFail();

        if ($user->id === $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot suspend yourself.',
            ], 422);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $user->update(['status' => 'suspended']);

        $this->auditService->log(
            user: $user,
            action: 'admin.user.suspend',
            description: "Admin suspended user {$user->email}",
            newValues: ['reason' => $validated['reason'] ?? null],
            performedBy: $request->user()
        );

        return response()->json([
            'success' => true,
            'message' => 'User suspended successfully.',
        ]);
    }

    /**
     * Ban a user.
     */
    public function ban(Request $request, string $uuid): JsonResponse
    {
        $user = User::where('uuid', $uuid)->firstOrFail();

        if ($user->id === $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot ban yourself.',
            ], 422);
        }

        $validated = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $user->update(['status' => 'banned']);

        $this->auditService->log(
            user: $user,
            action: 'admin.user.ban',
            description: "Admin banned user {$user->email}",
            newValues: ['reason' => $validated['reason'] ?? null],
            performedBy: $request->user()
        );

        return response()->json([
            'success' => true,
            'message' => 'User banned successfully.',
        ]);
    }

    /**
     * Activate a user.
     */
    public function activate(Request $request, string $uuid): JsonResponse
    {
        $user = User::where('uuid', $uuid)->firstOrFail();

        $oldStatus = $user->status;
        $user->update(['status' => 'active']);

        $this->auditService->log(
            user: $user,
            action: 'admin.user.activate',
            description: "Admin activated user {$user->email}",
            oldValues: ['status' => $oldStatus],
            newValues: ['status' => 'active'],
            performedBy: $request->user()
        );

        return response()->json([
            'success' => true,
            'message' => 'User activated successfully.',
        ]);
    }

    /**
     * Reset user's password.
     */
    public function resetPassword(Request $request, string $uuid): JsonResponse
    {
        $user = User::where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'send_email' => ['boolean'],
            'new_password' => ['nullable', 'string', 'min:8'],
        ]);

        $newPassword = $validated['new_password'] ?? Str::random(16);
        $user->update(['password' => Hash::make($newPassword)]);

        // Reset failed attempts and unlock
        $user->unlockAccount();

        $this->auditService->log(
            user: $user,
            action: 'admin.user.reset_password',
            description: "Admin reset password for user {$user->email}",
            performedBy: $request->user()
        );

        $response = [
            'success' => true,
            'message' => 'Password reset successfully.',
        ];

        // Only include password if not sending email
        if (! ($validated['send_email'] ?? false)) {
            $response['temporary_password'] = $newPassword;
        }

        return response()->json($response);
    }

    /**
     * Unlock a locked user account.
     */
    public function unlock(Request $request, string $uuid): JsonResponse
    {
        $user = User::where('uuid', $uuid)->firstOrFail();

        $user->unlockAccount();

        $this->auditService->log(
            user: $user,
            action: 'admin.user.unlock',
            description: "Admin unlocked user {$user->email}",
            performedBy: $request->user()
        );

        return response()->json([
            'success' => true,
            'message' => 'User account unlocked successfully.',
        ]);
    }

    /**
     * Get user statistics.
     */
    public function stats(): JsonResponse
    {
        $stats = [
            'total_users' => User::count(),
            'active_users' => User::where('status', 'active')->count(),
            'suspended_users' => User::where('status', 'suspended')->count(),
            'banned_users' => User::where('status', 'banned')->count(),
            'self_excluded_users' => User::where('status', 'self_excluded')->count(),
            'verified_email' => User::whereNotNull('email_verified_at')->count(),
            'verified_phone' => User::whereNotNull('phone_verified_at')->count(),
            'two_factor_enabled' => User::whereNotNull('two_factor_confirmed_at')->count(),
            'kyc_levels' => [
                'unverified' => User::where('kyc_level', 0)->count(),
                'basic' => User::where('kyc_level', 1)->count(),
                'enhanced' => User::where('kyc_level', 2)->count(),
                'full' => User::where('kyc_level', 3)->count(),
            ],
            'registrations_today' => User::whereDate('created_at', today())->count(),
            'registrations_this_week' => User::whereBetween('created_at', [now()->startOfWeek(), now()])->count(),
            'registrations_this_month' => User::whereBetween('created_at', [now()->startOfMonth(), now()])->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}
