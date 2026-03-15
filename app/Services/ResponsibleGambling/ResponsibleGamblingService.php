<?php

namespace App\Services\ResponsibleGambling;

use App\Models\ResponsibleGamblingSetting;
use App\Models\SelfExclusion;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ResponsibleGamblingService
{
    /**
     * Cooling-off period for limit increases (in hours).
     */
    public const COOLING_OFF_HOURS = 24;

    /**
     * Available self-exclusion durations.
     */
    public const EXCLUSION_DURATIONS = [
        '24h' => 1,
        '7d' => 7,
        '30d' => 30,
        '90d' => 90,
    ];

    /**
     * Get or create settings for a user.
     */
    public function getSettings(User $user): ResponsibleGamblingSetting
    {
        return ResponsibleGamblingSetting::firstOrCreate(
            ['user_id' => $user->id],
            []
        );
    }

    /**
     * Update deposit limits.
     * Limit decreases are immediate, increases require 24h cooling-off.
     */
    public function updateDepositLimits(User $user, array $limits): ResponsibleGamblingSetting
    {
        $settings = $this->getSettings($user);

        $pendingChanges = [];
        $immediateChanges = [];

        // Check daily limit
        if (array_key_exists('daily', $limits)) {
            $newLimit = $limits['daily'];
            $currentLimit = $settings->daily_deposit_limit;

            if ($this->isLimitDecrease($currentLimit, $newLimit)) {
                $immediateChanges['daily_deposit_limit'] = $newLimit;
            } else {
                $pendingChanges['pending_daily_limit'] = $newLimit;
            }
        }

        // Check weekly limit
        if (array_key_exists('weekly', $limits)) {
            $newLimit = $limits['weekly'];
            $currentLimit = $settings->weekly_deposit_limit;

            if ($this->isLimitDecrease($currentLimit, $newLimit)) {
                $immediateChanges['weekly_deposit_limit'] = $newLimit;
            } else {
                $pendingChanges['pending_weekly_limit'] = $newLimit;
            }
        }

        // Check monthly limit
        if (array_key_exists('monthly', $limits)) {
            $newLimit = $limits['monthly'];
            $currentLimit = $settings->monthly_deposit_limit;

            if ($this->isLimitDecrease($currentLimit, $newLimit)) {
                $immediateChanges['monthly_deposit_limit'] = $newLimit;
            } else {
                $pendingChanges['pending_monthly_limit'] = $newLimit;
            }
        }

        // Apply immediate changes
        if (! empty($immediateChanges)) {
            $settings->fill($immediateChanges);
        }

        // Schedule pending changes
        if (! empty($pendingChanges)) {
            $settings->fill($pendingChanges);
            $settings->pending_limits_effective_at = now()->addHours(self::COOLING_OFF_HOURS);
        }

        $settings->save();

        return $settings;
    }

    /**
     * Check if a limit change is a decrease (immediate) or increase (needs cooling-off).
     */
    protected function isLimitDecrease(?float $current, ?float $new): bool
    {
        // Setting a limit where none existed is a decrease (more restrictive)
        if ($current === null && $new !== null) {
            return true;
        }

        // Removing a limit is an increase (less restrictive)
        if ($current !== null && $new === null) {
            return false;
        }

        // Lower number = more restrictive = decrease
        return $new !== null && $current !== null && $new < $current;
    }

    /**
     * Update session limits.
     */
    public function updateSessionLimits(User $user, array $limits): ResponsibleGamblingSetting
    {
        $settings = $this->getSettings($user);

        if (array_key_exists('time_limit_minutes', $limits)) {
            $settings->session_time_limit_minutes = $limits['time_limit_minutes'];
        }

        if (array_key_exists('loss_limit', $limits)) {
            $settings->session_loss_limit = $limits['loss_limit'];
        }

        $settings->save();

        return $settings;
    }

    /**
     * Update reality check interval.
     */
    public function updateRealityCheck(User $user, ?int $intervalMinutes): ResponsibleGamblingSetting
    {
        $settings = $this->getSettings($user);
        $settings->reality_check_interval_minutes = $intervalMinutes;
        $settings->save();

        return $settings;
    }

    /**
     * Update login time restrictions.
     */
    public function updateLoginTimeRestrictions(User $user, ?string $start, ?string $end): ResponsibleGamblingSetting
    {
        $settings = $this->getSettings($user);
        $settings->login_time_start = $start;
        $settings->login_time_end = $end;
        $settings->save();

        return $settings;
    }

    /**
     * Cancel pending limit increases.
     */
    public function cancelPendingLimits(User $user): ResponsibleGamblingSetting
    {
        $settings = $this->getSettings($user);

        $settings->pending_daily_limit = null;
        $settings->pending_weekly_limit = null;
        $settings->pending_monthly_limit = null;
        $settings->pending_limits_effective_at = null;
        $settings->save();

        return $settings;
    }

    /**
     * Create a self-exclusion.
     */
    public function createSelfExclusion(User $user, string $duration, ?string $reason = null): SelfExclusion
    {
        // Check if user already has an active exclusion
        if ($this->hasActiveExclusion($user)) {
            throw new \RuntimeException('User already has an active self-exclusion.');
        }

        return DB::transaction(function () use ($user, $duration, $reason) {
            $startsAt = now();
            $endsAt = null;
            $type = SelfExclusion::TYPE_TEMPORARY;

            if ($duration === 'permanent') {
                $type = SelfExclusion::TYPE_PERMANENT;
            } else {
                $days = self::EXCLUSION_DURATIONS[$duration] ?? 30;
                $endsAt = $startsAt->copy()->addDays($days);
            }

            $exclusion = SelfExclusion::create([
                'user_id' => $user->id,
                'type' => $type,
                'reason' => $reason,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
            ]);

            // Update user status
            $user->update(['status' => 'self_excluded']);

            return $exclusion;
        });
    }

    /**
     * Check if user has an active self-exclusion.
     */
    public function hasActiveExclusion(User $user): bool
    {
        return SelfExclusion::where('user_id', $user->id)
            ->active()
            ->exists();
    }

    /**
     * Get active exclusion for user.
     */
    public function getActiveExclusion(User $user): ?SelfExclusion
    {
        return SelfExclusion::where('user_id', $user->id)
            ->active()
            ->first();
    }

    /**
     * Get exclusion history for user.
     */
    public function getExclusionHistory(User $user)
    {
        return SelfExclusion::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get comprehensive status for a user.
     */
    public function getStatus(User $user): array
    {
        $settings = $this->getSettings($user);
        $activeExclusion = $this->getActiveExclusion($user);

        return [
            'deposit_limits' => [
                'daily' => $settings->getEffectiveDailyLimit(),
                'weekly' => $settings->getEffectiveWeeklyLimit(),
                'monthly' => $settings->getEffectiveMonthlyLimit(),
            ],
            'pending_limits' => $settings->hasPendingLimits() ? [
                'daily' => $settings->pending_daily_limit ? (float) $settings->pending_daily_limit : null,
                'weekly' => $settings->pending_weekly_limit ? (float) $settings->pending_weekly_limit : null,
                'monthly' => $settings->pending_monthly_limit ? (float) $settings->pending_monthly_limit : null,
                'effective_at' => $settings->pending_limits_effective_at?->toIso8601String(),
            ] : null,
            'session_limits' => [
                'time_limit_minutes' => $settings->session_time_limit_minutes,
                'loss_limit' => $settings->session_loss_limit ? (float) $settings->session_loss_limit : null,
            ],
            'reality_check_interval_minutes' => $settings->reality_check_interval_minutes,
            'wager_limits' => [
                'daily' => $settings->daily_wager_limit ? (float) $settings->daily_wager_limit : null,
                'weekly' => $settings->weekly_wager_limit ? (float) $settings->weekly_wager_limit : null,
            ],
            'login_time_restriction' => $settings->login_time_start ? [
                'start' => $settings->login_time_start,
                'end' => $settings->login_time_end,
                'allowed_now' => $settings->isLoginTimeAllowed(),
            ] : null,
            'self_exclusion' => $activeExclusion ? [
                'type' => $activeExclusion->type,
                'starts_at' => $activeExclusion->starts_at->toIso8601String(),
                'ends_at' => $activeExclusion->ends_at?->toIso8601String(),
                'remaining_days' => $activeExclusion->getRemainingDays(),
                'duration_label' => $activeExclusion->getDurationLabel(),
            ] : null,
            'is_excluded' => $activeExclusion !== null,
        ];
    }

    /**
     * Check if deposit is within limits.
     */
    public function canDeposit(User $user, float $amount, float $dailyTotal, float $weeklyTotal, float $monthlyTotal): array
    {
        $settings = $this->getSettings($user);

        $checks = [];

        // Daily limit
        $dailyLimit = $settings->getEffectiveDailyLimit();
        if ($dailyLimit !== null) {
            $remaining = $dailyLimit - $dailyTotal;
            $checks['daily'] = [
                'limit' => $dailyLimit,
                'used' => $dailyTotal,
                'remaining' => max(0, $remaining),
                'allowed' => $amount <= $remaining,
            ];
        }

        // Weekly limit
        $weeklyLimit = $settings->getEffectiveWeeklyLimit();
        if ($weeklyLimit !== null) {
            $remaining = $weeklyLimit - $weeklyTotal;
            $checks['weekly'] = [
                'limit' => $weeklyLimit,
                'used' => $weeklyTotal,
                'remaining' => max(0, $remaining),
                'allowed' => $amount <= $remaining,
            ];
        }

        // Monthly limit
        $monthlyLimit = $settings->getEffectiveMonthlyLimit();
        if ($monthlyLimit !== null) {
            $remaining = $monthlyLimit - $monthlyTotal;
            $checks['monthly'] = [
                'limit' => $monthlyLimit,
                'used' => $monthlyTotal,
                'remaining' => max(0, $remaining),
                'allowed' => $amount <= $remaining,
            ];
        }

        $allowed = empty($checks) || collect($checks)->every(fn ($check) => $check['allowed']);

        return [
            'allowed' => $allowed,
            'checks' => $checks,
            'max_deposit' => empty($checks) ? null : min(array_column($checks, 'remaining')),
        ];
    }
}
