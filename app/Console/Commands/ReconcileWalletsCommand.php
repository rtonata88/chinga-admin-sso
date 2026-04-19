<?php

namespace App\Console\Commands;

use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReconcileWalletsCommand extends Command
{
    protected $signature = 'wallets:reconcile
                            {--wallet= : Reconcile a single wallet by id}
                            {--alert : Insert a wallet_reconciliation_alerts row for each drift found (default: true)}';

    protected $description = 'Assert wallet.balance matches the sum of its wallet_transactions and the balance_after of the latest transaction. Inserts alert rows on drift.';

    public function handle(): int
    {
        $query = Wallet::query();
        if ($walletId = $this->option('wallet')) {
            $query->where('id', $walletId);
        }

        $total = (clone $query)->count();
        if ($total === 0) {
            $this->info('No wallets to reconcile.');

            return self::SUCCESS;
        }

        $this->info("Reconciling {$total} wallet(s)...");

        $driftCount = 0;
        $alertsCreated = 0;

        $query->orderBy('id')->chunkById(200, function ($wallets) use (&$driftCount, &$alertsCreated) {
            foreach ($wallets as $wallet) {
                $result = $this->reconcileWallet($wallet);

                if ($result['drift']) {
                    $driftCount++;
                    if ($this->option('alert') !== false) {
                        $this->recordAlert($wallet, $result);
                        $alertsCreated++;
                    }

                    Log::warning('Wallet reconciliation drift', [
                        'wallet_id' => $wallet->id,
                        'user_id' => $wallet->user_id,
                        'tenant_id' => $wallet->tenant_id,
                        'recorded_balance' => (string) $wallet->balance,
                        'expected_from_sum' => $result['expected_from_sum'],
                        'expected_from_last_tx' => $result['expected_from_last_tx'],
                        'drift_from_sum' => $result['drift_from_sum'],
                        'drift_from_last_tx' => $result['drift_from_last_tx'],
                    ]);
                }
            }
        });

        $this->info("Reconciliation complete: {$driftCount} wallet(s) with drift, {$alertsCreated} alert(s) recorded.");

        return $driftCount === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function reconcileWallet(Wallet $wallet): array
    {
        $sum = WalletTransaction::where('wallet_id', $wallet->id)
            ->selectRaw("
                COALESCE(SUM(
                    CASE
                        WHEN type IN ('deposit', 'win') THEN amount
                        WHEN type IN ('withdrawal', 'bet') THEN -amount
                        WHEN type = 'adjustment' THEN amount
                        ELSE 0
                    END
                ), 0) AS total
            ")->value('total');

        $expectedFromSum = number_format((float) $sum, 2, '.', '');

        $lastTx = WalletTransaction::where('wallet_id', $wallet->id)
            ->orderByDesc('id')
            ->first();

        $expectedFromLastTx = $lastTx ? (string) $lastTx->balance_after : null;

        $recorded = (string) $wallet->balance;

        $driftFromSum = bcsub($recorded, $expectedFromSum, 2);
        $driftFromLastTx = $expectedFromLastTx !== null
            ? bcsub($recorded, $expectedFromLastTx, 2)
            : null;

        $drift = bccomp($driftFromSum, '0', 2) !== 0
            || ($driftFromLastTx !== null && bccomp($driftFromLastTx, '0', 2) !== 0);

        return [
            'drift' => $drift,
            'recorded' => $recorded,
            'expected_from_sum' => $expectedFromSum,
            'expected_from_last_tx' => $expectedFromLastTx,
            'drift_from_sum' => $driftFromSum,
            'drift_from_last_tx' => $driftFromLastTx,
        ];
    }

    private function recordAlert(Wallet $wallet, array $result): void
    {
        DB::table('wallet_reconciliation_alerts')->insert([
            'wallet_id' => $wallet->id,
            'recorded_balance' => $result['recorded'],
            'expected_from_sum' => $result['expected_from_sum'],
            'expected_from_last_tx' => $result['expected_from_last_tx'],
            'drift_from_sum' => $result['drift_from_sum'],
            'drift_from_last_tx' => $result['drift_from_last_tx'],
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
