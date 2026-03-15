<?php

namespace App\Services\Auth;

use App\Models\PhoneVerification;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class PhoneVerificationService
{
    /**
     * OTP expiration time in minutes.
     */
    protected int $expirationMinutes = 10;

    /**
     * Maximum verification attempts.
     */
    protected int $maxAttempts = 5;

    /**
     * Send OTP to a phone number.
     */
    public function sendOtp(string $phone, string $purpose = 'registration', ?User $user = null): PhoneVerification
    {
        // Invalidate any existing pending verifications
        $this->invalidatePending($phone, $purpose);

        // Generate OTP
        $otp = $this->generateOtp();

        // Create verification record
        $verification = PhoneVerification::create([
            'user_id' => $user?->id,
            'phone' => $phone,
            'otp_code' => Hash::make($otp),
            'purpose' => $purpose,
            'expires_at' => now()->addMinutes($this->expirationMinutes),
        ]);

        // Send OTP via SMS
        $this->dispatchSms($phone, $otp);

        return $verification;
    }

    /**
     * Verify OTP code.
     */
    public function verifyOtp(string $phone, string $code, string $purpose = 'registration'): bool
    {
        $verification = PhoneVerification::forPhone($phone)
            ->forPurpose($purpose)
            ->pending()
            ->latest()
            ->first();

        if (!$verification) {
            return false;
        }

        if ($verification->hasExceededAttempts($this->maxAttempts)) {
            return false;
        }

        if (!Hash::check($code, $verification->otp_code)) {
            $verification->incrementAttempts();
            return false;
        }

        $verification->markAsVerified();

        return true;
    }

    /**
     * Check if there's a valid pending verification.
     */
    public function hasPendingVerification(string $phone, string $purpose = 'registration'): bool
    {
        return PhoneVerification::forPhone($phone)
            ->forPurpose($purpose)
            ->pending()
            ->exists();
    }

    /**
     * Get the latest pending verification.
     */
    public function getPendingVerification(string $phone, string $purpose = 'registration'): ?PhoneVerification
    {
        return PhoneVerification::forPhone($phone)
            ->forPurpose($purpose)
            ->pending()
            ->latest()
            ->first();
    }

    /**
     * Invalidate all pending verifications for a phone number.
     */
    public function invalidatePending(string $phone, string $purpose): void
    {
        PhoneVerification::forPhone($phone)
            ->forPurpose($purpose)
            ->pending()
            ->update(['expires_at' => now()]);
    }

    /**
     * Generate a 6-digit OTP.
     */
    protected function generateOtp(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Dispatch SMS with OTP.
     */
    protected function dispatchSms(string $phone, string $otp): void
    {
        // TODO: Integrate with SMS provider (Twilio, Vonage, etc.)
        // For now, log in development
        if (app()->environment('local', 'testing')) {
            logger()->info("Phone OTP for {$phone}: {$otp}");
        }

        // In production, dispatch to queue:
        // dispatch(new SendSmsOtp($phone, $otp));
    }

    /**
     * Clean up expired verifications.
     */
    public function cleanupExpired(): int
    {
        return PhoneVerification::where('expires_at', '<', now()->subDay())
            ->delete();
    }
}
