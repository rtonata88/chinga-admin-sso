<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\FantasyAdminClient;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class TenantInvoiceController extends Controller
{
    public function __construct(protected FantasyAdminClient $fantasyAdminClient) {}

    /**
     * Render a printable invoice for a reseller tenant covering the
     * given period. The amount due is `platform_profit` — the slice
     * of NGR that flows to the platform after the tenant's revenue
     * share. Direct tenants don't have a tenant↔platform billing
     * relationship in this model and are rejected with 404.
     *
     * The view is a self-contained A4 Blade document (not Inertia) so
     * it prints cleanly: the same markup that's previewed in the
     * browser is what lands on paper / in the saved PDF. Period
     * defaults to the current calendar month if from/to aren't passed.
     */
    public function show(Request $request, string $tenantUuid): View
    {
        $user = $request->user();

        $tenant = Tenant::where('uuid', $tenantUuid)
            ->orWhere('slug', $tenantUuid)
            ->firstOrFail();

        if (!$user->isPlatformAdmin() && $user->tenant_id !== $tenant->id) {
            abort(403);
        }

        if (($tenant->business_model ?? 'reseller') !== 'reseller') {
            abort(404, 'Invoices are only generated for reseller tenants.');
        }

        $from = $request->query('from')
            ? \Carbon\Carbon::parse($request->query('from'))
            : now()->startOfMonth();
        $to = $request->query('to')
            ? \Carbon\Carbon::parse($request->query('to'))
            : now();

        // chinga-fantasy stores the SSO tenant slug in its `tenant_uuid`
        // column (TODO: backfill to real UUID — see DashboardController).
        // Try slug first; fall back to UUID so this still works once the
        // backfill happens.
        $fromIso = $from->toIso8601String();
        $toIso = $to->toIso8601String();

        $stats = $this->fantasyAdminClient->statsSummary($tenant->slug, $fromIso, $toIso);
        if ((int) ($stats['bets_placed'] ?? 0) === 0 && $tenant->uuid) {
            $stats = $this->fantasyAdminClient->statsSummary($tenant->uuid, $fromIso, $toIso);
        }

        $wagered = (float) ($stats['total_wagered'] ?? 0);
        $paidOut = (float) ($stats['total_paid_out'] ?? 0);
        $ggr = $wagered - $paidOut;

        $taxPct = (float) ($tenant->tax_pct ?? 0);
        $tax = $ggr > 0 ? $ggr * ($taxPct / 100) : 0;
        $ngr = $ggr - $tax;

        $sharePct = (float) ($tenant->revenue_share_pct ?? 0);
        $tenantProfit = $ngr > 0 ? $ngr * ($sharePct / 100) : 0.0;
        $platformProfit = $ngr - $tenantProfit;
        $amountDue = max(0.0, $platformProfit);

        return view('invoices.tenant', [
            'tenant' => [
                'uuid' => $tenant->uuid,
                'name' => $tenant->name,
                'business_model' => $tenant->business_model,
                'revenue_share_pct' => $sharePct,
                'tax_pct' => $taxPct,
            ],
            'period' => [
                'from' => $from->toIso8601String(),
                'to' => $to->toIso8601String(),
            ],
            'invoice' => [
                'number' => $this->invoiceNumber($tenant->uuid, $from),
                'issued_at' => now()->toIso8601String(),
                'due_at' => now()->addDays(14)->toIso8601String(),
            ],
            'activity' => [
                'bets_placed' => (int) ($stats['bets_placed'] ?? 0),
                'active_players' => (int) ($stats['active_players'] ?? 0),
                'total_wagered' => $wagered,
                'total_paid_out' => $paidOut,
            ],
            'breakdown' => [
                'ggr' => $ggr,
                'tax' => $tax,
                'ngr' => $ngr,
                'tenant_profit' => $tenantProfit,
                'platform_profit' => $platformProfit,
                'amount_due' => $amountDue,
            ],
        ]);
    }

    private function invoiceNumber(string $tenantUuid, \Carbon\Carbon $from): string
    {
        $prefix = strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $tenantUuid), 0, 6));
        return sprintf('INV-%s-%s', $prefix, $from->format('Ym'));
    }
}
