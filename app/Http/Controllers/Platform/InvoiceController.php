<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantInvoice;
use App\Models\TenantInvoicePayment;
use App\Services\Billing\InvoiceService;
use App\Support\Currencies;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Issued invoices and their payments. Platform admins see every tenant
 * and can record payments or void; a tenant admin sees their own
 * invoices read-only (the same page, without the actions).
 *
 * Every invoice is in its own currency (its tenant's, fixed at issue), so
 * the list totals are one figure per currency and never added across them.
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

        $invoices = $query->get();
        $outstanding = fn (TenantInvoice $i): string => bccomp((string) $i->amount_due, (string) $i->amount_paid, 2) > 0
            ? bcsub((string) $i->amount_due, (string) $i->amount_paid, 2)
            : '0.00';

        $rows = $invoices->map(fn (TenantInvoice $i) => [
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
            // Every figure on the row is in this currency; amounts are exact decimal strings.
            'currency' => $i->currency,
            'ggr' => (string) $i->ggr,
            'amount_due' => (string) $i->amount_due,
            'amount_paid' => (string) $i->amount_paid,
            'outstanding' => $outstanding($i),
            'paid_at' => $i->paid_at?->toIso8601String(),
        ])->values();

        $closed = [TenantInvoice::STATUS_PAID, TenantInvoice::STATUS_VOID];
        $byCurrency = Currencies::totalsByCurrency($invoices->map(fn (TenantInvoice $i) => [
            'currency' => $i->currency,
            'outstanding' => in_array($i->status, $closed, true) ? '0' : $outstanding($i),
            'overdue' => ! in_array($i->status, $closed, true) && $i->isOverdue() ? $outstanding($i) : '0',
            'paid' => $i->isVoid() ? '0' : (string) $i->amount_paid,
        ]), ['outstanding', 'overdue', 'paid']);

        return Inertia::render('invoices/index', [
            'rows' => $rows,
            'totals' => [
                'count' => $rows->count(),
                // [{currency, outstanding, overdue, paid}]: one entry per currency, replacing
                // the scalar outstanding/overdue/paid that added currencies together.
                'by_currency' => $byCurrency,
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
