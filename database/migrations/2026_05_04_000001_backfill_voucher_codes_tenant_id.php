<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill voucher_codes.tenant_id from voucher_codes.venue_id → venues.tenant_id.
 *
 * Existing vouchers were created via VoucherCodeService before tenant_id was
 * being set on creation, so they all have tenant_id=NULL — which makes
 * voucher login fail with "Invalid voucher code" because the lookup is
 * scoped by tenant. Pull the tenant from the linked venue.
 */
return new class extends Migration {
    public function up(): void
    {
        // MySQL: use a JOIN-style UPDATE to copy venues.tenant_id into voucher_codes.tenant_id
        DB::statement('
            UPDATE voucher_codes vc
            INNER JOIN venues v ON v.id = vc.venue_id
            SET vc.tenant_id = v.tenant_id
            WHERE vc.tenant_id IS NULL
        ');
    }

    public function down(): void
    {
        // We don't NULL them back out — the data is correct now.
    }
};
