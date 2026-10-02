<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantInvoice;
use App\Models\TenantInvoicePayment;
use App\Services\Billing\InvoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Issued invoices and their payments. Platform admins see every tenant
 * and can record payments or void; a tenant admin sees their own
 * invoices read-only (the same page, without the actions).
 */
class InvoiceController extends Controller
{
    public function __construct(protected InvoiceService $invoices) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $manage = $user->isPlatformAdmin();

        $query = TenantInvoice::with('tenant:id,uuid,name,slug')->orderByDesc('period_from')->orderByDesc('id');
        if (! $manage) {
            $query->where('tenant_id', $user->tenant_id);
        } elseif ($request->filled('tenant_uuid')) {
            $query->whereHas('tenant', fn ($q) => $q->where('uuid', $request->query('tenant_uuid')));
        }
        if ($request->filled('status')) {
            $status = $request->query('status');
            $status === 'overdue'
                ? $query->whereIn('status', [TenantInvoice::STATUS_ISSUED, TenantInvoice::STATUS_PART_PAID])->whereDate('due_at', '<', now())
                : $query->where('status', $status);
        }

        $rows = $query->get()->map(fn (TenantInvoice $i) => [
            'id' => $i->id,
            'number' => $i->number,
            'tenant_uuid' => $i->tenant?->uuid,
            'tenant_name' => $i->tenant?->name,
            'period_from' => $i->period_from->toDateString(),
            'period_to' => $i->period_to->toDateString(),
            'issued_at' => $i->issued_at->toIso8601String(),
            'due_at' => $i->due_at->toDateString(),
            'status' => $i->status,
            'overdue' => $i->isOverdue(),
            'currency' => $i->currency,
            'ggr' => (float) $i->ggr,
            'amount_due' => (float) $i->amount_due,
            'amount_paid' => (float) $i->amount_paid,
            'outstanding' => $i->outstanding(),
            'paid_at' => $i->paid_at?->toIso8601String(),
        ])->values();

        $open = $rows->filter(fn ($r) => ! in_array($r['status'], [TenantInvoice::STATUS_PAID, TenantInvoice::STATUS_VOID], true));

        return Inertia::render('invoices/index', [
            'rows' => $rows,
            'totals' => [
                'count' => $rows->count(),
                'outstanding' => round($open->sum('outstanding'), 2),
                'overdue' => round($open->filter(fn ($r) => $r['overdue'])->sum('outstanding'), 2),
                'paid' => round($rows->where('status', '!=', TenantInvoice::STATUS_VOID)->sum('amount_paid'), 2),
            ],
            'tenants' => $manage
                ? Tenant::where('business_model', 'reseller')->orderBy('name')->get(['uuid', 'name'])->map(fn ($t) => ['uuid' => $t->uuid, 'name' => $t->name])
                : [],
            'filters' => ['tenant_uuid' => $request->query('tenant_uuid'), 'status' => $request->query('status')],
            'can_manage' => $manage,
            'methods' => TenantInvoicePayment::METHODS,
        ]);
    }

    public function storePayment(Request $request, TenantInvoice $invoice): RedirectResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:999999999'],
            'paid_at' => ['required', 'date', 'before_or_equal:today'],
            'method' => ['required', 'in:'.implode(',', TenantInvoicePayment::METHODS)],
            'reference' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $this->invoices->recordPayment($invoice, $data, $request->user());

        return back()->with('success', sprintf('Payment of %s %s recorded on %s.', $invoice->currency, number_format((float) $data['amount'], 2), $invoice->number));
    }

    public function destroyPayment(Request $request, TenantInvoice $invoice, TenantInvoicePayment $payment): RedirectResponse
    {
        abort_unless($payment->tenant_invoice_id === $invoice->id, 404);
        $this->invoices->deletePayment($payment);

        return back()->with('success', 'Payment removed.');
    }

    public function void(Request $request, TenantInvoice $invoice): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:200']]);
        $this->invoices->void($invoice, $request->user(), $data['reason'] ?? null);

        return back()->with('success', "Invoice {$invoice->number} voided.");
    }
}
