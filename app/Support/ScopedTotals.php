<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Money totals for an admin page, per currency (Currencies::totalsByCurrency).
 * A platform admin's totals are one entry per currency present, and none at
 * all when there are no rows (the page shows '—'). A tenant-scoped page always
 * carries its tenant's currency, with zeros when there are no rows, so a tenant
 * admin sees "P0.00" rather than a dash or another currency.
 */
final class ScopedTotals
{
    /**
     * @param  iterable<array<string, mixed>|object>  $rows
     * @param  list<string>  $fields
     * @return list<array<string, string>>
     */
    public static function of(iterable $rows, array $fields, ?Tenant $tenant = null, string $currencyKey = 'currency'): array
    {
        $totals = Currencies::totalsByCurrency($rows, $fields, $currencyKey);
        if ($tenant && $totals === []) {
            $totals[] = ['currency' => self::currencyOf($tenant)] + array_fill_keys($fields, '0.00');
        }

        return $totals;
    }

    /** The tenant the request is scoped to (a tenant admin, or a tenant domain); null for the platform view. */
    public static function scopeTenant(): ?Tenant
    {
        $tenant = app()->bound('current_tenant') ? app('current_tenant') : null;

        return $tenant instanceof Tenant ? $tenant : null;
    }

    /** A tenant's currency code; an unsupported code is an error, never silently NAD. */
    public static function currencyOf(?Tenant $tenant): ?string
    {
        return $tenant ? Currencies::forTenant($tenant)['code'] : null;
    }
}
