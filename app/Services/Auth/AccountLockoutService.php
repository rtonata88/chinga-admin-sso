<?php

namespace App\Services\Auth;

use App\Models\LoginAttempt;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class AccountLockoutService
{
    /**
     * Maximum failed attempts before lockout.
     */
    protected int $maxAttempts = 5;

    /**
     * Lockout duration in minutes.
     */
    protected int $lockoutMinutes = 30;

    /**
     * Window for counting failed attempts (minutes).
     */
    protected int $attemptWindow = 30;

    /**
     * Check if the user is locked out.
     */
    public function isLockedOut(User $user): bool
    {
        return $user->isLocked();
    }

    /**
     * Get remaining lockout time in seconds.
     */
    public function getRemainingLockoutTime(User $user): int
    {
        if (!$user->isLocked()) {
            return 0;
        }

        return max(0, $user->locked_until->diffInSeconds(now()));
    }

    /**
     * Record a failed login attempt.
     */
    public function recordFailedAttempt(string $email, string $ip, ?string $userAgent = null, ?string $reason = null): void
    {
        LoginAttempt::create([
            'email' => $email,
            'ip_address' => $ip,
            'successful' => false,
            'failure_reason' => $reason ?? 'invalid_credentials',
            'user_agent' => $userAgent,
            'tenant_id' => app('current_tenant')?->id,
        ]);

        // Check if we should lock the user
        $user = User::where('email', $email)->first();
        if ($user) {
            $attempts = $user->incrementFailedLoginAttempts();

            if ($attempts >= $this->maxAttempts) {
                $this->lockAccount($user);
            }
        }

        // Also track by IP for rate limiting
        $this->incrementIpAttempts($ip);
    }

    /**
     * Record a successful login.
     */
    public function recordSuccessfulLogin(User $user, string $ip, ?string $userAgent = null): void
    {
        LoginAttempt::create([
            'email' => $user->email,
            'ip_address' => $ip,
            'successful' => true,
            'user_agent' => $userAgent,
            'tenant_id' => $user->tenant_id ?? app('current_tenant')?->id,
        ]);

        // Reset failed attempts on successful login
        $user->resetFailedLoginAttempts();

        // Update last login info
        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => $ip,
        ]);
    }

    /**
     * Lock a user's account.
     */
    public function lockAccount(User $user): void
    {
        $user->lockAccount($this->lockoutMinutes);

        // Fire event for audit logging
        event(new \Illuminate\Auth\Events\Lockout(request()));
    }

    /**
     * Unlock a user's account.
     */
    public function unlockAccount(User $user): void
    {
        $user->unlockAccount();
    }

    /**
     * Get the number of recent failed attempts for an email.
     */
    public function getFailedAttemptCount(string $email): int
    {
        return LoginAttempt::recentFailedCount($email, $this->attemptWindow);
    }

    /**
     * Get the number of recent failed attempts for an IP.
     */
    public function getFailedAttemptCountByIp(string $ip): int
    {
        return LoginAttempt::recentFailedCountByIp($ip, $this->attemptWindow);
    }

    /**
     * Check if an IP is rate limited.
     */
    public function isIpRateLimited(string $ip): bool
    {
        $key = $this->getIpCacheKey($ip);
        $attempts = Cache::get($key, 0);

        return $attempts >= ($this->maxAttempts * 3); // Higher threshold for IP
    }

    /**
     * Increment IP attempt counter.
     */
    protected function incrementIpAttempts(string $ip): void
    {
        $key = $this->getIpCacheKey($ip);
        $attempts = Cache::get($key, 0) + 1;

        Cache::put($key, $attempts, now()->addMinutes($this->attemptWindow));
    }

    /**
     * Get the cache key for IP rate limiting.
     */
    protected function getIpCacheKey(string $ip): string
    {
        return "login_attempts:{$ip}";
    }

    /**
     * Clear IP rate limit.
     */
    public function clearIpRateLimit(string $ip): void
    {
        Cache::forget($this->getIpCacheKey($ip));
    }
}
