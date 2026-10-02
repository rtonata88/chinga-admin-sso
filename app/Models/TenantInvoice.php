<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An issued reseller invoice: frozen figures plus the payments recorded against it. */
class TenantInvoice extends Model
{
    public const STATUS_ISSUED = 'issued';

    public const STATUS_PART_PAID = 'part_paid';

    public const STATUS_PAID = 'paid';

    public const STATUS_VOID = 'void';

    protected $fillable = [
        'number', 'tenant_id', 'period_from', 'period_to', 'currency', 'status',
        'bets_placed', 'active_players', 'total_wagered', 'total_paid_out', 'ggr',
        'tax_pct', 'tax', 'ngr', 'revenue_share_pct', 'tenant_share', 'amount_due', 'amount_paid',
        'games', 'unavailable', 'issued_at', 'due_at', 'issued_by', 'paid_at',
        'voided_at', 'voided_by', 'void_reason', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'period_from' => 'date',
            'period_to' => 'date',
            'issued_at' => 'datetime',
            'due_at' => 'date',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
            'games' => 'array',
            'unavailable' => 'array',
            'total_wagered' => 'decimal:2',
            'total_paid_out' => 'decimal:2',
            'ggr' => 'decimal:2',
            'tax_pct' => 'decimal:2',
            'tax' => 'decimal:2',
            'ngr' => 'decimal:2',
            'revenue_share_pct' => 'decimal:2',
            'tenant_share' => 'decimal:2',
            'amount_due' => 'decimal:2',
            'amount_paid' => 'decimal:2',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(TenantInvoicePayment::class)->orderBy('paid_at')->orderBy('id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function outstanding(): float
    {
        return max(0.0, round((float) $this->amount_due - (float) $this->amount_paid, 2));
    }

    public function isVoid(): bool
    {
        return $this->status === self::STATUS_VOID;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }

    public function isOverdue(): bool
    {
        return ! $this->isVoid() && ! $this->isPaid() && $this->due_at->isPast();
    }

    /** Recompute amount_paid and status from the payments; call after any change to them. */
    public function refreshStatus(): self
    {
        if ($this->isVoid()) {
            return $this;
        }
        $paid = round((float) $this->payments()->sum('amount'), 2);
        $due = round((float) $this->amount_due, 2);
        $this->amount_paid = $paid;
        if ($paid >= $due) {
            $this->status = self::STATUS_PAID;
            $this->paid_at = $this->paid_at ?? ($this->payments()->latest('paid_at')->value('paid_at') ?? now());
        } elseif ($paid > 0) {
            $this->status = self::STATUS_PART_PAID;
            $this->paid_at = null;
        } else {
            $this->status = self::STATUS_ISSUED;
            $this->paid_at = null;
        }
        $this->save();

        return $this;
    }
}
