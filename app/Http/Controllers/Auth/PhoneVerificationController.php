<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\PhoneVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class PhoneVerificationController extends Controller
{
    public function __construct(
        protected PhoneVerificationService $phoneVerificationService
    ) {}

    /**
     * Send OTP to a phone number.
     */
    public function sendOtp(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => [
                'required',
                'string',
                'max:20',
                'regex:/^\+?[1-9]\d{1,14}$/',
            ],
            'purpose' => ['sometimes', 'string', 'in:registration,login,update,mfa'],
        ]);

        $phone = $request->input('phone');
        $purpose = $request->input('purpose', 'registration');

        // Rate limiting
        $key = 'phone-otp:' . $phone;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'phone' => ["Too many OTP requests. Please try again in {$seconds} seconds."],
            ]);
        }

        RateLimiter::hit($key, 60);

        // Check if user is authenticated for update purpose
        $user = $request->user();
        if ($purpose === 'update' && !$user) {
            throw ValidationException::withMessages([
                'phone' => ['Authentication required to update phone number.'],
            ]);
        }

        // Send OTP
        $verification = $this->phoneVerificationService->sendOtp($phone, $purpose, $user);

        return response()->json([
            'message' => 'OTP sent successfully.',
            'expires_at' => $verification->expires_at->toIso8601String(),
        ]);
    }

    /**
     * Verify OTP code.
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
            'purpose' => ['sometimes', 'string', 'in:registration,login,update,mfa'],
        ]);

        $phone = $request->input('phone');
        $code = $request->input('code');
        $purpose = $request->input('purpose', 'registration');

        // Rate limiting for verification attempts
        $key = 'phone-verify:' . $phone;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'code' => ["Too many verification attempts. Please try again in {$seconds} seconds."],
            ]);
        }

        $verified = $this->phoneVerificationService->verifyOtp($phone, $code, $purpose);

        if (!$verified) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages([
                'code' => ['Invalid or expired verification code.'],
            ]);
        }

        RateLimiter::clear($key);

        // If user is authenticated and this is an update, mark phone as verified
        $user = $request->user();
        if ($user && $purpose === 'update') {
            $user->update(['phone' => $phone]);
            $user->markPhoneAsVerified();
        }

        return response()->json([
            'message' => 'Phone number verified successfully.',
            'verified' => true,
        ]);
    }

    /**
     * Update authenticated user's phone number.
     */
    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => [
                'required',
                'string',
                'max:20',
                'regex:/^\+?[1-9]\d{1,14}$/',
                'unique:users,phone,' . $request->user()->id,
            ],
        ]);

        $phone = $request->input('phone');

        // Send OTP for verification
        $verification = $this->phoneVerificationService->sendOtp(
            $phone,
            'update',
            $request->user()
        );

        return response()->json([
            'message' => 'Verification code sent to your new phone number.',
            'expires_at' => $verification->expires_at->toIso8601String(),
        ]);
    }

    /**
     * Resend OTP to authenticated user's pending phone.
     */
    public function resendOtp(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'phone' => [
                'required',
                'string',
                'max:20',
                'regex:/^\+?[1-9]\d{1,14}$/',
            ],
            'purpose' => ['sometimes', 'string', 'in:registration,update,mfa'],
        ]);

        $phone = $request->input('phone');
        $purpose = $request->input('purpose', 'update');

        // Rate limiting
        $key = 'phone-otp:' . $phone;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            $seconds = RateLimiter::availableIn($key);
            throw ValidationException::withMessages([
                'phone' => ["Too many OTP requests. Please try again in {$seconds} seconds."],
            ]);
        }

        RateLimiter::hit($key, 60);

        // Send new OTP
        $verification = $this->phoneVerificationService->sendOtp($phone, $purpose, $user);

        return response()->json([
            'message' => 'Verification code resent.',
            'expires_at' => $verification->expires_at->toIso8601String(),
        ]);
    }
}
