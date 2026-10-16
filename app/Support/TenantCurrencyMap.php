<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Which currency an engine row's money is in, from the tenant it names
 * (USSD design U-D15). Engines report tenants by uuid, though chinga-fantasy
 * may put the SSO tenant slug in `tenant_uuid`, so both resolve here; the
 * map only reads, it never turns one into the other.
 *
 * Consoles tag each row with `currency` and never add different currencies
 * together: a cross-tenant figure is one figure per currency, no exchange rate.
 */
final class TenantCurrencyMap
{
    /** Totals for rows whose tenant the SSO does not know: shown under this code, never folded into a real currency. */
    public const UNKNOWN = '?';

    /** @param  array<string, string>  $codes  tenant uuid (lowercase) or slug → currency code */
    private function __construct(private readonly array $codes) {}

    public static function load(): self
    {
        $codes = [];
        foreach (Tenant::query()->get(['uuid', 'slug', 'currency']) as $tenant) {
            // As Currencies::forTenant: a tenant with no code set is NAD.
            $code = $tenant->currency ?: 'NAD';
            $codes[strtolower((string) $tenant->uuid)] = $code;
            if ($tenant->slug) {
                $codes[$tenant->slug] ??= $code;
            }
        }

        return new self($codes);
    }

    /** The tenant's currency code, or null when the SSO has no such tenant. */
    public function of(?string $tenant): ?string
    {
        if ($tenant === null || $tenant === '') {
            return null;
        }

        return $this->codes[strtolower($tenant)] ?? $this->codes[$tenant] ?? null;
    }

    /**
     * Each row with `currency` set from its tenant (null when unknown).
     *
     * @param  array<int|string, mixed>  $rows
     * @return array<int|string, mixed>
     */
    public function tag(array $rows, string $tenantKey = 'tenant_uuid'): array
    {
        return array_map(fn ($row) => is_array($row) ? $this->tagOne($row, $tenantKey) : $row, $rows);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function tagOne(array $row, string $tenantKey = 'tenant_uuid'): array
    {
        $tenant = $row[$tenantKey] ?? null;

        return $row + ['currency' => $this->of(is_string($tenant) ? $tenant : null)];
    }

    /**
     * The one currency a set of tenants' money is in, or null when they span
     * more than one (or none is known): a mixed figure has no currency to show.
     *
     * @param  iterable<string|null>  $tenants
     */
    public function single(iterable $tenants): ?string
    {
        $seen = [];
        foreach ($tenants as $tenant) {
            $seen[$this->of($tenant) ?? self::UNKNOWN] = true;
        }

        return count($seen) === 1 && ! isset($seen[self::UNKNOWN]) ? array_key_first($seen) : null;
    }

    /**
     * Money fields summed per currency (exact, bcmath), rows of unknown
     * tenants under UNKNOWN rather than dropped.
     *
     * @param  array<int|string, array<string, mixed>>  $rows
     * @param  list<string>  $fields
     * @return list<array<string, string>>
     */
    public function totals(array $rows, array $fields, string $tenantKey = 'tenant_uuid'): array
    {
        $tagged = array_map(function (array $row) use ($tenantKey) {
            $tenant = $row[$tenantKey] ?? null;

            return ['currency' => $this->of(is_string($tenant) ? $tenant : null) ?? self::UNKNOWN] + $row;
        }, $rows);

        return Currencies::totalsByCurrency($tagged, $fields);
    }
}
