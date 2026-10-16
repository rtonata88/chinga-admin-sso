<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * The commercial split of one tenant's play, exactly (bcmath, never float).
 * Every figure is in that tenant's own currency; the split is never applied
 * to money summed across tenants, because that could mix currencies.
 *
 *   GGR  = wagered − paid_out
 *   tax  = max(GGR, 0) × tax_pct / 100          (no tax on losses)
 *   NGR  = GGR − tax
 *   reseller: tenant_profit   = max(NGR, 0) × revenue_share_pct / 100
 *             platform_profit = NGR − tenant_profit
 *   direct:   tenant_profit   = 0
 *             platform_profit = NGR
 *
 * tax and tenant_profit are rounded half-up to the cent; NGR and
 * platform_profit are the exact remainders, so the parts always add up.
 */
final class RevenueSplit
{
    /** @return array{ggr: string, tax: string, ngr: string, tenant_profit: string, platform_profit: string} */
    public static function compute(?Tenant $tenant, mixed $wagered, mixed $paidOut): array
    {
        $ggr = bcsub(self::decimal($wagered), self::decimal($paidOut), 2);

        $taxPct = $tenant ? self::decimal($tenant->tax_pct ?? 0) : '0';
        $tax = bccomp($ggr, '0', 2) > 0 ? self::percentOf($ggr, $taxPct) : '0.00';
        $ngr = bcsub($ggr, $tax, 2);

        if (($tenant->business_model ?? 'reseller') === 'direct') {
            $tenantProfit = '0.00';
        } else {
            $sharePct = $tenant ? self::decimal($tenant->revenue_share_pct ?? 0) : '0';
            $tenantProfit = bccomp($ngr, '0', 2) > 0 ? self::percentOf($ngr, $sharePct) : '0.00';
        }

        return [
            'ggr' => $ggr,
            'tax' => $tax,
            'ngr' => $ngr,
            'tenant_profit' => $tenantProfit,
            'platform_profit' => bcsub($ngr, $tenantProfit, 2),
        ];
    }

    /** A DB/JSON amount (string, int, or float from an older endpoint) as a bcmath decimal string. */
    public static function decimal(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '0';
        }
        if (is_float($v)) {
            return number_format($v, 2, '.', '');
        }
        $s = trim((string) $v);

        return preg_match('/^-?\d+(\.\d+)?$/', $s) ? $s : '0';
    }

    /** amount × pct / 100, rounded half-up to the cent. amount is positive here. */
    private static function percentOf(string $amount, string $pct): string
    {
        $raw = bcdiv(bcmul($amount, $pct, 10), '100', 10);

        return bcadd($raw, '0.005', 2);
    }
}
