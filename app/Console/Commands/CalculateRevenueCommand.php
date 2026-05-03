<?php

namespace App\Console\Commands;

use App\Models\GameSession;
use App\Models\Tenant;
use App\Models\TenantRevenueRecord;
use App\Models\VoucherTransaction;
use App\Models\WalletTransaction;
use Carbon\Carbon;
use Illuminate\Console\Command;

class CalculateRevenueCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'revenue:calculate {--period=daily : The period to calculate (daily, weekly, monthly)}';

    /**
     * The console command description.
     */
    protected $description = 'Calculate revenue from game session transactions for each tenant and game';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $period = $this->option('period');

        if (! in_array($period, ['daily', 'weekly', 'monthly'])) {
            $this->error("Invalid period: {$period}. Must be daily, weekly, or monthly.");

            return self::FAILURE;
        }

        [$periodStart, $periodEnd] = $this->getPeriodBounds($period);

        $this->info("Calculating {$period} revenue for period: {$periodStart->toDateString()} to {$periodEnd->toDateString()}");

        $tenants = Tenant::where('status', 'active')->get();

        if ($tenants->isEmpty()) {
            $this->warn('No active tenants found.');

            return self::SUCCESS;
        }

        $totalRecords = 0;

        foreach ($tenants as $tenant) {
            $this->info("Processing tenant: {$tenant->name} (ID: {$tenant->id})");

            $gameIds = GameSession::where('tenant_id', $tenant->id)
                ->whereBetween('started_at', [$periodStart, $periodEnd])
                ->distinct()
                ->pluck('game_id');

            if ($gameIds->isEmpty()) {
                $this->line("  No game activity found for this tenant.");

                continue;
            }

            foreach ($gameIds as $gameId) {
                $sessionIds = GameSession::where('tenant_id', $tenant->id)
                    ->where('game_id', $gameId)
                    ->whereBetween('started_at', [$periodStart, $periodEnd])
                    ->pluck('id');

                // Sum wallet transactions for these sessions
                $walletBets = WalletTransaction::whereIn('game_session_id', $sessionIds)
                    ->where('type', 'bet')
                    ->sum('amount');

                $walletWins = WalletTransaction::whereIn('game_session_id', $sessionIds)
                    ->where('type', 'win')
                    ->sum('amount');

                // Sum voucher transactions for these sessions
                $voucherBets = VoucherTransaction::whereIn('game_session_id', $sessionIds)
                    ->where('type', 'bet')
                    ->sum('amount');

                $voucherWins = VoucherTransaction::whereIn('game_session_id', $sessionIds)
                    ->where('type', 'win')
                    ->sum('amount');

                // Revenue stack:
                //   GGR  = bets - wins
                //   tax  = GGR * tax_pct (jurisdictional, snapshotted at calc time)
                //   NGR  = GGR - tax
                //   reseller: tenant = NGR * revenue_share_pct, platform = NGR - tenant
                //   direct:   tenant = 0,                       platform = NGR
                $totalBets = bcadd((string) $walletBets, (string) $voucherBets, 2);
                $totalWins = bcadd((string) $walletWins, (string) $voucherWins, 2);
                $grossGamingRevenue = bcsub($totalBets, $totalWins, 2);

                $taxPct = (string) ($tenant->tax_pct ?? '0.00');
                $taxAmount = bcdiv(bcmul($grossGamingRevenue, $taxPct, 4), '100', 2);
                $netGamingRevenue = bcsub($grossGamingRevenue, $taxAmount, 2);

                $businessModel = $tenant->business_model ?? 'reseller';
                $revenueSharePct = (string) ($tenant->revenue_share_pct ?? '0.00');

                if ($businessModel === 'direct') {
                    $tenantShare = '0.00';
                    $chingaShare = $netGamingRevenue;
                } else {
                    $tenantShare = bcdiv(bcmul($netGamingRevenue, $revenueSharePct, 4), '100', 2);
                    $chingaShare = bcsub($netGamingRevenue, $tenantShare, 2);
                }

                TenantRevenueRecord::updateOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'game_id' => $gameId,
                        'period_type' => $period,
                        'period_start' => $periodStart->toDateString(),
                    ],
                    [
                        'period_end' => $periodEnd->toDateString(),
                        'total_bets' => $totalBets,
                        'total_wins' => $totalWins,
                        'gross_gaming_revenue' => $grossGamingRevenue,
                        'tax_pct' => $taxPct,
                        'tax_amount' => $taxAmount,
                        'net_gaming_revenue' => $netGamingRevenue,
                        'business_model' => $businessModel,
                        'revenue_share_pct' => $revenueSharePct,
                        'tenant_share' => $tenantShare,
                        'chinga_share' => $chingaShare,
                        'status' => 'calculated',
                        'calculated_at' => now(),
                    ]
                );

                $this->line("  Game ID {$gameId} ({$businessModel}): GGR={$grossGamingRevenue} tax={$taxAmount} NGR={$netGamingRevenue} tenant={$tenantShare} chinga={$chingaShare}");
                $totalRecords++;
            }
        }

        $this->info("Revenue calculation complete. {$totalRecords} records upserted.");

        return self::SUCCESS;
    }

    /**
     * Get the start and end dates for the given period.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function getPeriodBounds(string $period): array
    {
        return match ($period) {
            'daily' => [
                Carbon::yesterday()->startOfDay(),
                Carbon::today()->startOfDay(),
            ],
            'weekly' => [
                Carbon::now()->subWeek()->startOfWeek(),
                Carbon::now()->startOfWeek(),
            ],
            'monthly' => [
                Carbon::now()->subMonth()->startOfMonth(),
                Carbon::now()->startOfMonth(),
            ],
        };
    }
}
