<?php

namespace App\Services\Auth;

use App\Models\PhoneVerification;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class SmsMfaService
{
    /**
     * OTP expiration time in minutes.
     */
    protected int $expirationMinutes = 5;

    /**
     * Maximum verification attempts.
     */
    protected int $maxAttempts = 5;

    public function __construct(
        protected PhoneVerificationService $phoneVerificationService
    ) {}

    /**
     * Enable SMS MFA for a user.
     */
    public function enable(User $user, string $phone): PhoneVerification
    {
        // Send OTP for verification
        return $this->phoneVerificationService->sendOtp($phone, 'mfa', $user);
    }

    /**
     * Confirm SMS MFA setup after verifying OTP.
     */
    public function confirmSetup(User $user, string $phone, string $code): bool
    {
        $verified = $this->phoneVerificationService->verifyOtp($phone, $code, 'mfa');

        if (!$verified) {
            return false;
        }

        $user->update([
            'sms_mfa_phone' => $phone,
            'sms_mfa_enabled' => true,
        ]);

        return true;
    }

    /**
     * Disable SMS MFA for a user.
     */
    public function disable(User $user): void
    {
        $user->update([
            'sms_mfa_phone' => null,
            'sms_mfa_enabled' => false,
        ]);

        // If SMS was the preferred method, switch to TOTP
        if ($user->preferred_mfa_method === 'sms') {
            $user->update(['preferred_mfa_method' => 'totp']);
        }
    }

    /**
     * Send SMS MFA challenge code.
     */
    public function sendChallenge(User $user): PhoneVerification
    {
        if (!$user->sms_mfa_enabled || !$user->sms_mfa_phone) {
            throw new \RuntimeException('SMS MFA is not enabled for this user');
        }

        return $this->phoneVerificationService->sendOtp(
            $user->sms_mfa_phone,
            'mfa',
            $user
        );
    }

    /**
     * Verify SMS MFA challenge code.
     */
    public function verifyChallenge(User $user, string $code): bool
    {
        if (!$user->sms_mfa_enabled || !$user->sms_mfa_phone) {
            return false;
        }

        return $this->phoneVerificationService->verifyOtp(
            $user->sms_mfa_phone,
            $code,
            'mfa'
        );
    }

    /**
     * Check if user has SMS MFA enabled.
     */
    public function isEnabled(User $user): bool
    {
        return $user->sms_mfa_enabled && $user->sms_mfa_phone !== null;
    }

    /**
     * Check if SMS MFA is the preferred method.
     */
    public function isPreferred(User $user): bool
    {
        return $user->preferred_mfa_method === 'sms' && $this->isEnabled($user);
    }

    /**
     * Set SMS as the preferred MFA method.
     */
    public function setAsPreferred(User $user): void
    {
        if (!$this->isEnabled($user)) {
            throw new \RuntimeException('SMS MFA must be enabled first');
        }

        $user->update(['preferred_mfa_method' => 'sms']);
    }

    /**
     * Get masked phone number.
     */
    public function getMaskedPhone(User $user): ?string
    {
        if (!$user->sms_mfa_phone) {
            return null;
        }

        $phone = $user->sms_mfa_phone;
        $length = strlen($phone);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return substr($phone, 0, 3) . str_repeat('*', $length - 5) . substr($phone, -2);
    }
}
