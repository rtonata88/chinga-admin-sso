<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CompanyProfile;
use App\Models\Tenant;
use App\Models\TenantInvoice;
use App\Services\Billing\InvoiceService;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The reseller invoice. `show` previews a period live from the game
 * backends unless that period has already been issued, in which case the
 * stored invoice is shown instead. `issue` freezes a preview (platform
 * admins). `issued` renders a stored invoice by number for anyone allowed
 * to see that tenant.
 */
class TenantInvoiceController extends Controller
{
    public function __construct(protected InvoiceService $invoices) {}

    public function show(Request $request, string $tenantUuid): View|RedirectResponse
    {
        $tenant = $this->tenantFor($request, $tenantUuid);
        [$from, $to] = $this->period($request);

        $existing = TenantInvoice::where('number', InvoiceService::number($tenant, $from))->first();
        if ($existing && ! $existing->isVoid()) {
            return redirect()->route('invoices.show', $existing->number);
        }

        $f = $this->invoices->compute($tenant, $from, $to);

        return view('invoices.tenant', $this->viewData($tenant, $f, [
            'number' => $f['number'],
            'issued_at' => now()->toIso8601String(),
            'due_at' => now()->addDays(CompanyProfile::current()->payment_terms_days)->toIso8601String(),
            'complete' => $f['unavailable'] === [],
            'status' => 'preview',
            'can_issue' => $request->user()->isPlatformAdmin() && $f['unavailable'] === [],
            'issue_url' => route('admin.tenant.invoice.issue', ['tenant_uuid' => $tenant->uuid]),
            'period_query' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
        ], $f['games'], $f['unavailable'], null));
    }

    public function issue(Request $request, string $tenantUuid): RedirectResponse
    {
        abort_unless($request->user()->isPlatformAdmin(), 403);
        $tenant = $this->tenantFor($request, $tenantUuid);
        [$from, $to] = $this->period($request);

        $invoice = $this->invoices->issue($tenant, $from, $to, $request->user(), CompanyProfile::current()->payment_terms_days);

        return redirect()->route('invoices.show', $invoice->number)->with('success', "Invoice {$invoice->number} issued.");
    }

    public function issued(Request $request, string $number): View
    {
        $invoice = TenantInvoice::with(['tenant', 'payments.recorder'])->where('number', $number)->firstOrFail();
        $user = $request->user();
        if (! $user->isPlatformAdmin() && $user->tenant_id !== $invoice->tenant_id) {
            abort(403);
        }

        $f = [
            'bets_placed' => $invoice->bets_placed,
            'active_players' => $invoice->active_players,
            'total_wagered' => (float) $invoice->total_wagered,
            'total_paid_out' => (float) $invoice->total_paid_out,
            'ggr' => (float) $invoice->ggr,
            'tax_pct' => (float) $invoice->tax_pct,
            'tax' => (float) $invoice->tax,
            'ngr' => (float) $invoice->ngr,
            'revenue_share_pct' => (float) $invoice->revenue_share_pct,
            'tenant_share' => (float) $invoice->tenant_share,
            'platform_share' => (float) $invoice->ngr - (float) $invoice->tenant_share,
            'amount_due' => (float) $invoice->amount_due,
            'period_from' => $invoice->period_from,
            'period_to' => $invoice->period_to,
        ];

        return view('invoices.tenant', $this->viewData($invoice->tenant, $f, [
            'number' => $invoice->number,
            'issued_at' => $invoice->issued_at->toIso8601String(),
            'due_at' => $invoice->due_at->toIso8601String(),
            'complete' => true,
            'status' => $invoice->status,
            'overdue' => $invoice->isOverdue(),
            'amount_paid' => (float) $invoice->amount_paid,
            'outstanding' => $invoice->outstanding(),
            'paid_at' => $invoice->paid_at?->toIso8601String(),
            'voided_at' => $invoice->voided_at?->toIso8601String(),
            'void_reason' => $invoice->void_reason,
            'can_issue' => false,
            'can_manage' => $user->isPlatformAdmin(),
            'payment_url' => route('platform.invoices.payments.store', $invoice),
            'void_url' => route('platform.invoices.void', $invoice),
            'payments' => $invoice->payments->map(fn ($p) => [
                'amount' => (float) $p->amount,
                'paid_at' => $p->paid_at->toIso8601String(),
                'method' => $p->method,
                'reference' => $p->reference,
                'note' => $p->note,
                'recorded_by' => $p->recorder?->name,
            ])->all(),
        ], $invoice->games ?? [], [], $invoice));
    }

    private function tenantFor(Request $request, string $tenantUuid): Tenant
    {
        $user = $request->user();
        $tenant = Tenant::where('uuid', $tenantUuid)->orWhere('slug', $tenantUuid)->firstOrFail();
        if (! $user->isPlatformAdmin() && $user->tenant_id !== $tenant->id) {
            abort(403);
        }
        if (($tenant->business_model ?? 'reseller') !== 'reseller') {
            abort(404, 'Invoices are only generated for reseller tenants.');
        }

        return $tenant;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function period(Request $request): array
    {
        $from = $request->input('from') ? Carbon::parse($request->input('from')) : now()->startOfMonth();
        $to = $request->input('to') ? Carbon::parse($request->input('to')) : now();

        return [$from, $to];
    }

    private function viewData(Tenant $tenant, array $f, array $invoice, array $games, array $unavailable, ?TenantInvoice $record): array
    {
        $company = CompanyProfile::current();

        return [
            'company' => [
                'name' => $company->displayName(),
                'trading_name' => $company->trading_name && $company->trading_name !== $company->displayName() ? $company->trading_name : null,
                'registration_number' => $company->registration_number,
                'vat_number' => $company->vat_number,
                'address_lines' => $company->addressLines(),
                'email' => $company->email ?: 'platform@playchinga.com',
                'phone' => $company->phone,
                'bank' => $company->hasBankDetails() ? [
                    'bank_name' => $company->bank_name,
                    'account_name' => $company->bank_account_name,
                    'account_number' => $company->bank_account_number,
                    'branch_code' => $company->bank_branch_code,
                ] : null,
                'payment_terms_days' => $company->payment_terms_days,
            ],
            'tenant' => [
                'uuid' => $tenant->uuid,
                'name' => $tenant->name,
                'legal_name' => $tenant->legal_name,
                'business_model' => $tenant->business_model,
                'revenue_share_pct' => $f['revenue_share_pct'],
                'tax_pct' => $f['tax_pct'],
            ],
            'period' => [
                'from' => Carbon::parse($f['period_from'])->toIso8601String(),
                'to' => Carbon::parse($f['period_to'])->toIso8601String(),
            ],
            'invoice' => $invoice,
            'activity' => [
                'bets_placed' => $f['bets_placed'],
                'active_players' => $f['active_players'],
                'total_wagered' => $f['total_wagered'],
                'total_paid_out' => $f['total_paid_out'],
                'games' => $games,
                'unavailable' => $unavailable,
            ],
            'breakdown' => [
                'ggr' => $f['ggr'],
                'tax' => $f['tax'],
                'ngr' => $f['ngr'],
                'tenant_profit' => $f['tenant_share'],
                'platform_profit' => $f['platform_share'],
                'amount_due' => $f['amount_due'],
            ],
            'record' => $record,
        ];
    }
}
