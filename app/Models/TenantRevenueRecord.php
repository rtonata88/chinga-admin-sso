<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantRevenueRecord extends Model
{
    protected $fillable = [
        'tenant_id',
        'game_id',
        'period_type',
        'period_start',
        'period_end',
        'total_bets',
        'total_wins',
        'gross_gaming_revenue',
        'tax_pct',
        'tax_amount',
        'net_gaming_revenue',
        'business_model',
        'revenue_share_pct',
        'chinga_share',
        'tenant_share',
        'status',
        'calculated_at',
        'confirmed_at',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'total_bets' => 'decimal:2',
            'total_wins' => 'decimal:2',
            'gross_gaming_revenue' => 'decimal:2',
            'tax_pct' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'net_gaming_revenue' => 'decimal:2',
            'revenue_share_pct' => 'decimal:2',
            'chinga_share' => 'decimal:2',
            'tenant_share' => 'decimal:2',
            'calculated_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
