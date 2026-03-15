<?php

namespace App\Http\Controllers\Venue;

use App\Http\Controllers\Controller;
use App\Models\VenueStaff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Authenticate venue staff.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'venue_id' => ['required', 'exists:venues,uuid'],
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        // Rate limiting
        $key = 'venue-login:' . $request->input('venue_id') . ':' . $request->input('username');
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'username' => ["Too many login attempts. Please try again in {$seconds} seconds."],
            ]);
        }

        $staff = VenueStaff::whereHas('venue', function ($q) use ($request) {
            $q->where('uuid', $request->input('venue_id'));
        })
            ->where('username', $request->input('username'))
            ->first();

        if (!$staff || !Hash::check($request->input('password'), $staff->password)) {
            RateLimiter::hit($key, 300);

            throw ValidationException::withMessages([
                'username' => ['The provided credentials are incorrect.'],
            ]);
        }

        if (!$staff->isActive()) {
            throw ValidationException::withMessages([
                'username' => ['Your account is not active.'],
            ]);
        }

        if (!$staff->venue->isActive()) {
            throw ValidationException::withMessages([
                'username' => ['This venue is not active.'],
            ]);
        }

        RateLimiter::clear($key);

        // Update login info
        $staff->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        // Create token
        $token = $staff->createToken('venue-staff', [
            'venue:' . $staff->venue_id,
            'role:' . $staff->role,
        ]);

        return response()->json([
            'message' => 'Login successful.',
            'token' => $token->plainTextToken,
            'staff' => [
                'uuid' => $staff->uuid,
                'username' => $staff->username,
                'display_name' => $staff->display_name,
                'role' => $staff->role,
            ],
            'venue' => [
                'uuid' => $staff->venue->uuid,
                'name' => $staff->venue->name,
                'currency' => $staff->venue->currency,
            ],
        ]);
    }

    /**
     * Quick PIN login (for same device).
     */
    public function pinLogin(Request $request): JsonResponse
    {
        $request->validate([
            'venue_id' => ['required', 'exists:venues,uuid'],
            'username' => ['required', 'string'],
            'pin' => ['required', 'string', 'size:4'],
        ]);

        // Rate limiting
        $key = 'venue-pin-login:' . $request->input('venue_id') . ':' . $request->input('username');
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'pin' => ["Too many PIN attempts. Please try again in {$seconds} seconds."],
            ]);
        }

        $staff = VenueStaff::whereHas('venue', function ($q) use ($request) {
            $q->where('uuid', $request->input('venue_id'));
        })
            ->where('username', $request->input('username'))
            ->first();

        if (!$staff || !$staff->verifyPin($request->input('pin'))) {
            RateLimiter::hit($key, 300);

            throw ValidationException::withMessages([
                'pin' => ['Invalid PIN.'],
            ]);
        }

        if (!$staff->isActive() || !$staff->venue->isActive()) {
            throw ValidationException::withMessages([
                'pin' => ['Account or venue is not active.'],
            ]);
        }

        RateLimiter::clear($key);

        $staff->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        $token = $staff->createToken('venue-staff-pin', [
            'venue:' . $staff->venue_id,
            'role:' . $staff->role,
        ]);

        return response()->json([
            'message' => 'PIN login successful.',
            'token' => $token->plainTextToken,
            'staff' => [
                'uuid' => $staff->uuid,
                'display_name' => $staff->display_name,
                'role' => $staff->role,
            ],
        ]);
    }

    /**
     * Logout venue staff.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Get current staff profile.
     */
    public function profile(Request $request): JsonResponse
    {
        $staff = $request->user();

        return response()->json([
            'staff' => [
                'uuid' => $staff->uuid,
                'username' => $staff->username,
                'email' => $staff->email,
                'phone' => $staff->phone,
                'display_name' => $staff->display_name,
                'role' => $staff->role,
                'has_pin' => !empty($staff->pin),
                'last_login_at' => $staff->last_login_at?->toIso8601String(),
            ],
            'venue' => [
                'uuid' => $staff->venue->uuid,
                'name' => $staff->venue->name,
                'currency' => $staff->venue->currency,
                'timezone' => $staff->venue->timezone,
            ],
            'permissions' => [
                'can_create_codes' => $staff->canCreateCodes(),
                'can_load_credits' => $staff->canLoadCredits(),
                'can_cashout' => $staff->canCashout(),
                'can_view_reports' => $staff->canViewReports(),
                'can_manage_staff' => $staff->canManageStaff(),
            ],
            'current_shift' => $staff->currentShift()?->only([
                'uuid',
                'started_at',
                'total_loads',
                'total_cashouts',
                'codes_created',
            ]),
        ]);
    }

    /**
     * Change password.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $request->user()->update([
            'password' => Hash::make($request->input('password')),
        ]);

        return response()->json([
            'message' => 'Password changed successfully.',
        ]);
    }

    /**
     * Set or update PIN.
     */
    public function setPin(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'pin' => ['required', 'string', 'size:4', 'regex:/^[0-9]{4}$/'],
        ]);

        $request->user()->setPin($request->input('pin'));

        return response()->json([
            'message' => 'PIN set successfully.',
        ]);
    }
}
