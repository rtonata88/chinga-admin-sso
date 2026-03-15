<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ResponsibleGamblingSetting extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'daily_deposit_limit',
        'weekly_deposit_limit',
        'monthly_deposit_limit',
        'pending_daily_limit',
        'pending_weekly_limit',
        'pending_monthly_limit',
        'pending_limits_effective_at',
        'session_time_limit_minutes',
        'session_loss_limit',
        'reality_check_interval_minutes',
        'daily_wager_limit',
        'weekly_wager_limit',
        'login_time_start',
        'login_time_end',
    ];

    protected $casts = [
        'daily_deposit_limit' => 'decimal:2',
        'weekly_deposit_limit' => 'decimal:2',
        'monthly_deposit_limit' => 'decimal:2',
        'pending_daily_limit' => 'decimal:2',
        'pending_weekly_limit' => 'decimal:2',
        'pending_monthly_limit' => 'decimal:2',
        'pending_limits_effective_at' => 'datetime',
        'session_time_limit_minutes' => 'integer',
        'session_loss_limit' => 'decimal:2',
        'reality_check_interval_minutes' => 'integer',
        'daily_wager_limit' => 'decimal:2',
        'weekly_wager_limit' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Check if there are pending limit increases.
     */
    public function hasPendingLimits(): bool
    {
        return $this->pending_limits_effective_at !== null
            && $this->pending_limits_effective_at->isFuture();
    }

    /**
     * Apply pending limit increases if cooling-off period has passed.
     */
    public function applyPendingLimits(): bool
    {
        if (! $this->pending_limits_effective_at || $this->pending_limits_effective_at->isFuture()) {
            return false;
        }

        $updated = false;

        if ($this->pending_daily_limit !== null) {
            $this->daily_deposit_limit = $this->pending_daily_limit;
            $this->pending_daily_limit = null;
            $updated = true;
        }

        if ($this->pending_weekly_limit !== null) {
            $this->weekly_deposit_limit = $this->pending_weekly_limit;
            $this->pending_weekly_limit = null;
            $updated = true;
        }

        if ($this->pending_monthly_limit !== null) {
            $this->monthly_deposit_limit = $this->pending_monthly_limit;
            $this->pending_monthly_limit = null;
            $updated = true;
        }

        if ($updated) {
            $this->pending_limits_effective_at = null;
            $this->save();
        }

        return $updated;
    }

    /**
     * Check if login is allowed at the current time.
     */
    public function isLoginTimeAllowed(): bool
    {
        if (! $this->login_time_start || ! $this->login_time_end) {
            return true;
        }

        $now = now()->format('H:i:s');
        $start = $this->login_time_start;
        $end = $this->login_time_end;

        // Handle overnight ranges (e.g., 22:00 to 06:00)
        if ($start > $end) {
            return $now >= $start || $now <= $end;
        }

        return $now >= $start && $now <= $end;
    }

    /**
     * Get the effective daily deposit limit.
     */
    public function getEffectiveDailyLimit(): ?float
    {
        $this->applyPendingLimits();

        return $this->daily_deposit_limit ? (float) $this->daily_deposit_limit : null;
    }

    /**
     * Get the effective weekly deposit limit.
     */
    public function getEffectiveWeeklyLimit(): ?float
    {
        $this->applyPendingLimits();

        return $this->weekly_deposit_limit ? (float) $this->weekly_deposit_limit : null;
    }

    /**
     * Get the effective monthly deposit limit.
     */
    public function getEffectiveMonthlyLimit(): ?float
    {
        $this->applyPendingLimits();

        return $this->monthly_deposit_limit ? (float) $this->monthly_deposit_limit : null;
    }
}
