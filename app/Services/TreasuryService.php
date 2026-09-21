<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\TreasurySetting;
use App\Models\WalletTransaction;
use App\Models\WithdrawalRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Player liability and distributable profit, from the wallet ledger.
 *
 * Deposits are money held for players, not income. What the business
 * may take out is what is left once every wallet is backed:
 *
 *   distributable = bank balance
 *                 − owed to players (wallet balances)
 *                 − held for withdrawal (requested/approved, already off the balance)
 *                 − variance reserve
 *                 − tax provision (tax_pct × month-to-date GGR, floored at zero)
 *
 * The bank balance, reserve and tax rate are entered by hand
 * (TreasurySetting); everything else is read from the ledger. All
 * arithmetic is bcmath on 2dp strings — never floats.
 */
class TreasuryService
{
    public const DORMANT_DAYS = 90;

    public function position(): array
    {
        $settings = TreasurySetting::current();
        $now = CarbonImmutable::now();
        $dormantBefore = $now->subDays(self::DORMANT_DAYS);
        $monthStart = $now->startOfMonth();

        $tenants = Tenant::query()->orderBy('name')->get(['id', 'uuid', 'name', 'slug', 'business_model']);

        $wallets = DB::table('wallets')
            ->selectRaw('tenant_id, COUNT(*) AS wallets, SUM(balance > 0) AS funded, COALESCE(SUM(balance), 0) AS owed')
            ->selectRaw('COALESCE(SUM(CASE WHEN balance > 0 AND updated_at < ? THEN balance ELSE 0 END), 0) AS dormant', [$dormantBefore])
            ->where('status', '<>', 'closed')
            ->groupBy('tenant_id')
            ->get()
            ->keyBy('tenant_id');

        $held = WithdrawalRequest::query()
            ->selectRaw('tenant_id, COUNT(*) AS n, COALESCE(SUM(amount), 0) AS amount')
            ->whereIn('status', ['requested', 'approved'])
            ->groupBy('tenant_id')
            ->get()
            ->keyBy('tenant_id');

        $paidOut = WithdrawalRequest::query()
            ->selectRaw('tenant_id, COALESCE(SUM(net_amount), 0) AS amount')
            ->where('status', 'paid')
            ->groupBy('tenant_id')
            ->get()
            ->keyBy('tenant_id');

        $ledger = WalletTransaction::query()
            ->join('wallets', 'wallets.id', '=', 'wallet_transactions.wallet_id')
            ->selectRaw('wallets.tenant_id')
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'deposit' AND performed_by IS NOT NULL THEN amount ELSE 0 END), 0) AS staff_deposits")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'deposit' AND performed_by IS NULL THEN amount ELSE 0 END), 0) AS player_deposits")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'bet' THEN amount ELSE 0 END), 0) AS bets")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'win' THEN amount ELSE 0 END), 0) AS wins")
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'bet' AND wallet_transactions.created_at >= ? THEN amount ELSE 0 END), 0) AS month_bets", [$monthStart])
            ->selectRaw("COALESCE(SUM(CASE WHEN type = 'win' AND wallet_transactions.created_at >= ? THEN amount ELSE 0 END), 0) AS month_wins", [$monthStart])
            ->groupBy('wallets.tenant_id')
            ->get()
            ->keyBy('tenant_id');

        $rows = [];
        $totals = [
            'wallets' => 0, 'funded' => 0, 'owed' => '0.00', 'dormant' => '0.00', 'held' => '0.00', 'held_count' => 0,
            'staff_deposits' => '0.00', 'player_deposits' => '0.00', 'paid_out' => '0.00', 'ledger_cash' => '0.00',
            'lifetime_ggr' => '0.00', 'month_ggr' => '0.00',
        ];
        foreach ($tenants as $tenant) {
            $w = $wallets->get($tenant->id);
            $h = $held->get($tenant->id);
            $p = $paidOut->get($tenant->id);
            $l = $ledger->get($tenant->id);
            $staff = $this->money($l->staff_deposits ?? 0);
            $player = $this->money($l->player_deposits ?? 0);
            $out = $this->money($p->amount ?? 0);
            $row = [
                'tenant_uuid' => $tenant->uuid,
                'tenant_name' => $tenant->name,
                'business_model' => $tenant->business_model ?? 'reseller',
                'wallets' => (int) ($w->wallets ?? 0),
                'funded' => (int) ($w->funded ?? 0),
                'owed' => $this->money($w->owed ?? 0),
                'dormant' => $this->money($w->dormant ?? 0),
                'held' => $this->money($h->amount ?? 0),
                'held_count' => (int) ($h->n ?? 0),
                'staff_deposits' => $staff,
                'player_deposits' => $player,
                'paid_out' => $out,
                // Cash the ledger says came in and stayed: every deposit less every paid withdrawal.
                'ledger_cash' => bcsub(bcadd($staff, $player, 2), $out, 2),
                'lifetime_ggr' => bcsub($this->money($l->bets ?? 0), $this->money($l->wins ?? 0), 2),
                'month_ggr' => bcsub($this->money($l->month_bets ?? 0), $this->money($l->month_wins ?? 0), 2),
            ];
            if ($row['wallets'] === 0 && bccomp($row['ledger_cash'], '0', 2) === 0) {
                continue; // a tenant with no wallets and no cash has nothing to show
            }
            $rows[] = $row;
            foreach (['wallets', 'funded', 'held_count'] as $k) {
                $totals[$k] += $row[$k];
            }
            foreach (['owed', 'dormant', 'held', 'staff_deposits', 'player_deposits', 'paid_out', 'ledger_cash', 'lifetime_ggr', 'month_ggr'] as $k) {
                $totals[$k] = bcadd($totals[$k], $row[$k], 2);
            }
        }

        $bank = $this->money($settings->bank_balance);
        $reserve = $this->money($settings->variance_reserve);
        $taxPct = $this->money($settings->tax_pct);
        $liability = bcadd($totals['owed'], $totals['held'], 2);
        $taxable = bccomp($totals['month_ggr'], '0', 2) > 0 ? $totals['month_ggr'] : '0.00';
        $taxProvision = bcdiv(bcmul($taxable, $taxPct, 4), '100', 2);
        $distributable = bcsub(bcsub(bcsub($bank, $liability, 2), $reserve, 2), $taxProvision, 2);
        $surplus = bcsub($bank, $liability, 2);

        return [
            'settings' => [
                'bank_balance' => $bank,
                'bank_balance_as_of' => $settings->bank_balance_as_of?->toDateString(),
                'variance_reserve' => $reserve,
                'tax_pct' => $taxPct,
                'updated_at' => $settings->updated_at?->toIso8601String(),
            ],
            'summary' => [
                'bank_balance' => $bank,
                'owed' => $totals['owed'],
                'held' => $totals['held'],
                'liability' => $liability,
                'surplus' => $surplus,
                'covered' => bccomp($surplus, '0', 2) >= 0,
                'coverage_pct' => bccomp($liability, '0', 2) > 0 ? bcdiv(bcmul($bank, '100', 4), $liability, 1) : null,
                'reserve' => $reserve,
                'tax_provision' => $taxProvision,
                'distributable' => $distributable,
                'ledger_cash' => $totals['ledger_cash'],
                'lifetime_ggr' => $totals['lifetime_ggr'],
                'month_ggr' => $totals['month_ggr'],
                'dormant' => $totals['dormant'],
            ],
            'tenants' => $rows,
            'totals' => $totals,
            'as_of' => $now->toIso8601String(),
            'month' => $monthStart->toDateString(),
            'dormant_days' => self::DORMANT_DAYS,
        ];
    }

    private function money(mixed $v): string
    {
        return bcadd((string) ($v ?? '0'), '0', 2);
    }
}
