<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\SecurityAuditService;
use App\Services\Auth\SmsMfaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class SmsMfaController extends Controller
{
    public function __construct(
        protected SmsMfaService $smsMfaService,
        protected SecurityAuditService $auditService
    ) {}

    /**
     * Get SMS MFA status for the current user.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'enabled' => $this->smsMfaService->isEnabled($user),
            'is_preferred' => $this->smsMfaService->isPreferred($user),
            'masked_phone' => $this->smsMfaService->getMaskedPhone($user),
        ]);
    }

    /**
     * Start SMS MFA setup process.
     */
    public function enable(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => [
                'required',
                'string',
                'max:20',
                'regex:/^\+?[1-9]\d{1,14}$/',
            ],
        ]);

        $user = $request->user();
        $phone = $request->input('phone');

        // Rate limiting
        $key = 'sms-mfa-setup:' . $user->id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'phone' => ["Too many setup attempts. Please try again in {$seconds} seconds."],
            ]);
        }

        RateLimiter::hit($key, 300);

        $verification = $this->smsMfaService->enable($user, $phone);

        return response()->json([
            'message' => 'Verification code sent. Please verify to complete setup.',
            'expires_at' => $verification->expires_at->toIso8601String(),
        ]);
    }

    /**
     * Verify and complete SMS MFA setup.
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => [
                'required',
                'string',
                'max:20',
                'regex:/^\+?[1-9]\d{1,14}$/',
            ],
            'code' => ['required', 'string', 'size:6'],
        ]);

        $user = $request->user();
        $phone = $request->input('phone');
        $code = $request->input('code');

        $verified = $this->smsMfaService->confirmSetup($user, $phone, $code);

        if (!$verified) {
            throw ValidationException::withMessages([
                'code' => ['Invalid or expired verification code.'],
            ]);
        }

        // Log the event
        $this->auditService->logMfaEnabled($user, 'sms');

        return response()->json([
            'message' => 'SMS two-factor authentication has been enabled.',
            'enabled' => true,
        ]);
    }

    /**
     * Disable SMS MFA.
     */
    public function disable(Request $request): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string', 'current_password'],
        ]);

        $user = $request->user();

        if (!$this->smsMfaService->isEnabled($user)) {
            throw ValidationException::withMessages([
                'sms_mfa' => ['SMS MFA is not enabled.'],
            ]);
        }

        $this->smsMfaService->disable($user);

        // Log the event
        $this->auditService->logMfaDisabled($user, 'sms');

        return response()->json([
            'message' => 'SMS two-factor authentication has been disabled.',
            'enabled' => false,
        ]);
    }

    /**
     * Set SMS as preferred MFA method.
     */
    public function setPreferred(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$this->smsMfaService->isEnabled($user)) {
            throw ValidationException::withMessages([
                'sms_mfa' => ['SMS MFA must be enabled first.'],
            ]);
        }

        $this->smsMfaService->setAsPreferred($user);

        return response()->json([
            'message' => 'SMS set as preferred MFA method.',
            'preferred_method' => 'sms',
        ]);
    }
}
