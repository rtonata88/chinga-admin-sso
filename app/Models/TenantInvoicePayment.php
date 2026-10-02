<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One payment a tenant made against an issued invoice, as recorded by an admin. */
class TenantInvoicePayment extends Model
{
    public const METHODS = ['bank_transfer', 'cash', 'other'];

    protected $fillable = ['tenant_invoice_id', 'amount', 'paid_at', 'method', 'reference', 'note', 'recorded_by'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'paid_at' => 'date'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(TenantInvoice::class, 'tenant_invoice_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
