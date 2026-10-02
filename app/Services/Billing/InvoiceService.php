<?php

namespace App\Services\Billing;

use App\Models\Game;
use App\Models\Tenant;
use App\Models\TenantInvoice;
use App\Models\TenantInvoicePayment;
use App\Models\User;
use App\Services\LiveGameStats;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reseller invoicing. `compute` prices a period live from every game
 * backend (the preview); `issue` freezes that into a TenantInvoice;
 * payments are recorded against the issued invoice and drive its status.
 *
 *   GGR  = wagered − paid out
 *   tax  = max(GGR, 0) × tax_pct
 *   NGR  = GGR − tax
 *   tenant_share = max(NGR, 0) × revenue_share_pct   (reseller)
 *   amount due   = NGR − tenant_share, floored at zero
 */
class InvoiceService
{
    public function __construct(private LiveGameStats $liveStats) {}

    public static function number(Tenant $tenant, Carbon $from): string
    {
        $prefix = strtoupper(substr(preg_replace('/[^a-z0-9]/i', '', $tenant->uuid), 0, 6));

        return sprintf('INV-%s-%s', $prefix, $from->format('Ym'));
    }

    /** The live figures for a period, in the shape the invoice view renders. */
    public function compute(Tenant $tenant, Carbon $from, Carbon $to): array
    {
        // Every game with a backend, not only the tenant's currently enabled ones: a game
        // disabled after the period still owes its figures. Engines answer zero for no play.
        $games = Game::query()
            ->whereIn('status', ['active', 'development'])
            ->orderBy('name')
            ->get()
            ->filter(fn (Game $game) => $game->hasBackend())
            ->values();

        $perGame = [];
        $unavailable = [];
        $bets = 0;
        $players = 0;
        $wagered = 0.0;
        $paidOut = 0.0;
        foreach ($this->liveStats->summaryForTenant($games, $tenant, $from->toIso8601String(), $to->toIso8601String()) as $entry) {
            if ($entry['error'] !== null) {
                $unavailable[] = $entry['game']->name;

                continue;
            }
            $s = $entry['stats'];
            $gameWagered = (float) ($s['total_wagered'] ?? 0);
            $gamePaidOut = (float) ($s['total_paid_out'] ?? 0);
            $perGame[] = [
                'name' => $entry['game']->name,
                'bets_placed' => (int) ($s['bets_placed'] ?? 0),
                'active_players' => (int) ($s['active_players'] ?? 0),
                'total_wagered' => $gameWagered,
                'total_paid_out' => $gamePaidOut,
                'ggr' => $gameWagered - $gamePaidOut,
            ];
            $bets += (int) ($s['bets_placed'] ?? 0);
            $players += (int) ($s['active_players'] ?? 0);
            $wagered += $gameWagered;
            $paidOut += $gamePaidOut;
        }

        $ggr = $wagered - $paidOut;
        $taxPct = (float) ($tenant->tax_pct ?? 0);
        $tax = $ggr > 0 ? $ggr * ($taxPct / 100) : 0.0;
        $ngr = $ggr - $tax;
        $sharePct = (float) ($tenant->revenue_share_pct ?? 0);
        $tenantShare = $ngr > 0 ? $ngr * ($sharePct / 100) : 0.0;
        $platformShare = $ngr - $tenantShare;

        return [
            'number' => self::number($tenant, $from),
            'period_from' => $from,
            'period_to' => $to,
            'bets_placed' => $bets,
            'active_players' => $players, // summed per game: a player active in two games counts twice
            'total_wagered' => round($wagered, 2),
            'total_paid_out' => round($paidOut, 2),
            'ggr' => round($ggr, 2),
            'tax_pct' => $taxPct,
            'tax' => round($tax, 2),
            'ngr' => round($ngr, 2),
            'revenue_share_pct' => $sharePct,
            'tenant_share' => round($tenantShare, 2),
            'platform_share' => round($platformShare, 2),
            'amount_due' => round(max(0.0, $platformShare), 2),
            'games' => $perGame,
            'unavailable' => $unavailable,
        ];
    }

    /** Freeze the live figures for a period into an invoice. One per tenant per period number. */
    public function issue(Tenant $tenant, Carbon $from, Carbon $to, User $by, int $termsDays): TenantInvoice
    {
        $number = self::number($tenant, $from);
        if (TenantInvoice::where('number', $number)->exists()) {
            throw ValidationException::withMessages(['number' => "Invoice {$number} has already been issued."]);
        }
        $figures = $this->compute($tenant, $from, $to);
        if ($figures['unavailable'] !== []) {
            throw ValidationException::withMessages([
                'figures' => 'Figures could not be fetched for '.implode(', ', $figures['unavailable']).'. Issue the invoice once every backend is reachable.',
            ]);
        }

        $invoice = TenantInvoice::create([
            'number' => $number,
            'tenant_id' => $tenant->id,
            'period_from' => $from->toDateString(),
            'period_to' => $to->toDateString(),
            'currency' => $tenant->currency ?: 'NAD',
            'status' => $figures['amount_due'] > 0 ? TenantInvoice::STATUS_ISSUED : TenantInvoice::STATUS_PAID,
            'bets_placed' => $figures['bets_placed'],
            'active_players' => $figures['active_players'],
            'total_wagered' => $figures['total_wagered'],
            'total_paid_out' => $figures['total_paid_out'],
            'ggr' => $figures['ggr'],
            'tax_pct' => $figures['tax_pct'],
            'tax' => $figures['tax'],
            'ngr' => $figures['ngr'],
            'revenue_share_pct' => $figures['revenue_share_pct'],
            'tenant_share' => $figures['tenant_share'],
            'amount_due' => $figures['amount_due'],
            'amount_paid' => 0,
            'games' => $figures['games'],
            'unavailable' => [],
            'issued_at' => now(),
            'due_at' => now()->addDays($termsDays)->toDateString(),
            'issued_by' => $by->id,
            'paid_at' => $figures['amount_due'] > 0 ? null : now(),
        ]);

        return $invoice;
    }

    /** Record a payment; refuses more than is outstanding and anything on a void invoice. */
    public function recordPayment(TenantInvoice $invoice, array $data, User $by): TenantInvoicePayment
    {
        if ($invoice->isVoid()) {
            throw ValidationException::withMessages(['amount' => 'This invoice is void; nothing can be paid against it.']);
        }
        $amount = round((float) $data['amount'], 2);
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'The amount must be more than zero.']);
        }
        $outstanding = $invoice->outstanding();
        if ($amount > $outstanding + 0.005) {
            throw ValidationException::withMessages([
                'amount' => sprintf('Only %s %s is outstanding on this invoice.', $invoice->currency, number_format($outstanding, 2)),
            ]);
        }

        return DB::transaction(function () use ($invoice, $data, $by, $amount) {
            $payment = $invoice->payments()->create([
                'amount' => $amount,
                'paid_at' => $data['paid_at'],
                'method' => $data['method'] ?? 'bank_transfer',
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
                'recorded_by' => $by->id,
            ]);
            $invoice->refreshStatus();

            return $payment;
        });
    }

    public function void(TenantInvoice $invoice, User $by, ?string $reason): TenantInvoice
    {
        if ($invoice->payments()->exists()) {
            throw ValidationException::withMessages(['void' => 'An invoice with payments recorded cannot be voided. Remove the payments first.']);
        }
        $invoice->forceFill([
            'status' => TenantInvoice::STATUS_VOID,
            'voided_at' => now(),
            'voided_by' => $by->id,
            'void_reason' => $reason,
        ])->save();

        return $invoice;
    }

    public function deletePayment(TenantInvoicePayment $payment): void
    {
        $invoice = $payment->invoice;
        DB::transaction(function () use ($payment, $invoice) {
            $payment->delete();
            $invoice->refreshStatus();
        });
    }
}
