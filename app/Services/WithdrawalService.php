<?php

namespace App\Services;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Venue;
use App\Models\Wallet;
use App\Models\WithdrawalRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Player-initiated withdrawal workflow.
 *
 *   request()    — player submits, wallet is debited (held)
 *   approve()    — admin OK; money still held, ready to be paid out-of-band
 *   markPaid()   — admin records the actual payout; debit stands
 *   reject()     — admin denies; wallet is refunded
 *   cancel()     — player cancels before approval; wallet is refunded
 */
class WithdrawalService
{
    public const VALID_METHODS = ['bank_transfer', 'venue_cash', 'mobile_money', 'voucher'];

    public function __construct(
        protected WalletService $walletService,
    ) {}

    /**
     * Tenant-level withdrawal config (with safe defaults). Stored in
     * tenants.settings.withdrawals — per-tenant overrides of platform defaults.
     */
    public function tenantConfig(Tenant $tenant): array
    {
        $defaults = [
            'allowed_methods' => self::VALID_METHODS,
            'min_amount' => '50.00',
            'max_amount' => '10000.00',
            'daily_limit' => '50000.00',
            'fee_pct' => '0.00',
            'auto_approve_under' => '0.00',
        ];

        $config = $tenant->settings['withdrawals'] ?? [];

        return array_merge($defaults, is_array($config) ? $config : []);
    }

    /**
     * Player creates a withdrawal request. Validates against tenant config,
     * computes fee, debits the wallet (held), and optionally auto-approves
     * small amounts.
     */
    public function request(User $user, array $data): WithdrawalRequest
    {
        $tenant = Tenant::findOrFail($user->tenant_id);
        $config = $this->tenantConfig($tenant);
        // Eloquent caches `$user->wallet` from earlier reads; refresh to
        // avoid a stale balance check against $wallet->balance.
        $wallet = $user->getOrCreateWallet()->refresh();

        $amount = (string) ($data['amount'] ?? '0');
        $method = $data['payment_method'] ?? null;
        $details = $data['payment_details'] ?? [];

        $this->validateMethod($method, $config);
        $this->validateAmount($amount, $wallet, $config);
        $this->validatePaymentDetails($method, $details);
        $this->validateDailyLimit($user, $amount, $config);

        $feePct = $config['fee_pct'] ?? '0';
        $feeAmount = bcdiv(bcmul($amount, (string) $feePct, 4), '100', 2);
        $netAmount = bcsub($amount, $feeAmount, 2);

        return DB::transaction(function () use ($tenant, $user, $wallet, $amount, $feeAmount, $netAmount, $method, $details, $config) {
            $request = WithdrawalRequest::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'amount' => $amount,
                'fee_amount' => $feeAmount,
                'net_amount' => $netAmount,
                'currency' => $wallet->currency,
                'payment_method' => $method,
                'payment_details' => $details,
                'status' => 'requested',
            ]);

            // Hold the funds. Idempotent by withdrawal uuid.
            $hold = $this->walletService->withdraw(
                $wallet,
                $amount,
                $user,
                "withdrawal_{$request->uuid}_hold",
            );
            $request->update(['hold_transaction_id' => $hold->id]);

            // Auto-approve small amounts if configured.
            $autoApproveUnder = (string) ($config['auto_approve_under'] ?? '0');
            if (bccomp($autoApproveUnder, '0', 2) > 0
                && bccomp($amount, $autoApproveUnder, 2) <= 0) {
                $request->update([
                    'status' => 'approved',
                    'reviewed_at' => now(),
                    'notes' => 'Auto-approved (under threshold)',
                ]);
            }

            return $request->fresh();
        });
    }

    public function approve(WithdrawalRequest $request, User $admin, ?string $notes = null): WithdrawalRequest
    {
        $this->ensureStatus($request, ['requested']);

        $request->update([
            'status' => 'approved',
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'notes' => $notes,
        ]);

        return $request->fresh();
    }

    public function reject(WithdrawalRequest $request, User $admin, string $reason): WithdrawalRequest
    {
        $this->ensureStatus($request, ['requested', 'approved']);
        $this->refund($request, $admin);

        $request->update([
            'status' => 'rejected',
            'reviewed_by' => $admin->id,
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ]);

        return $request->fresh();
    }

    public function cancel(WithdrawalRequest $request, User $user): WithdrawalRequest
    {
        // Only the owner can cancel, and only before approval.
        if ($request->user_id !== $user->id) {
            throw new \RuntimeException('Cannot cancel a withdrawal that is not yours.');
        }
        $this->ensureStatus($request, ['requested']);
        $this->refund($request, $user);

        $request->update(['status' => 'cancelled']);

        return $request->fresh();
    }

    public function markPaid(WithdrawalRequest $request, User $admin, ?string $externalReference = null): WithdrawalRequest
    {
        $this->ensureStatus($request, ['approved']);

        $request->update([
            'status' => 'paid',
            'paid_by' => $admin->id,
            'paid_at' => now(),
            'external_reference' => $externalReference,
        ]);

        return $request->fresh();
    }

    private function refund(WithdrawalRequest $request, User $performedBy): void
    {
        if (!$request->hold_transaction_id) {
            return; // nothing to refund
        }
        if ($request->refund_transaction_id) {
            return; // already refunded
        }
        $hold = $request->holdTransaction;
        if (!$hold) {
            return;
        }

        $refund = $this->walletService->refundWithdrawalHold(
            $hold,
            $performedBy,
            "withdrawal_{$request->uuid}_refund",
            "Withdrawal refund ({$request->status})",
        );
        $request->update(['refund_transaction_id' => $refund->id]);
    }

    private function ensureStatus(WithdrawalRequest $request, array $allowed): void
    {
        if (!in_array($request->status, $allowed, true)) {
            throw new \RuntimeException(
                "Cannot perform action on withdrawal in status '{$request->status}' (allowed: ".implode(', ', $allowed).')'
            );
        }
    }

    private function validateMethod(?string $method, array $config): void
    {
        if (!$method || !in_array($method, self::VALID_METHODS, true)) {
            throw new \InvalidArgumentException('Invalid payment method.');
        }
        $allowed = $config['allowed_methods'] ?? self::VALID_METHODS;
        if (!in_array($method, $allowed, true)) {
            throw new \InvalidArgumentException("Payment method '$method' is not enabled for this tenant.");
        }
    }

    private function validateAmount(string $amount, Wallet $wallet, array $config): void
    {
        if (bccomp($amount, '0', 2) <= 0) {
            throw new \InvalidArgumentException('Amount must be greater than zero.');
        }
        $min = (string) ($config['min_amount'] ?? '0');
        $max = (string) ($config['max_amount'] ?? '0');
        if (bccomp($min, '0', 2) > 0 && bccomp($amount, $min, 2) < 0) {
            throw new \InvalidArgumentException("Minimum withdrawal is {$min}.");
        }
        if (bccomp($max, '0', 2) > 0 && bccomp($amount, $max, 2) > 0) {
            throw new \InvalidArgumentException("Maximum per-request withdrawal is {$max}.");
        }
        // Players can only withdraw what they've won — deposits stay locked
        // until they're played through and turn into winnings.
        if (!$wallet->hasSufficientWithdrawableBalance($amount)) {
            throw new \RuntimeException(
                'You can only withdraw funds you have won through play. Deposits are locked until you win with them.'
            );
        }
    }

    private function validateDailyLimit(User $user, string $amount, array $config): void
    {
        $limit = (string) ($config['daily_limit'] ?? '0');
        if (bccomp($limit, '0', 2) <= 0) {
            return;
        }

        $since = Carbon::now()->subDay();
        $todaysTotal = (string) WithdrawalRequest::where('user_id', $user->id)
            ->whereIn('status', ['requested', 'approved', 'paid'])
            ->where('created_at', '>=', $since)
            ->sum('amount');

        $projected = bcadd($todaysTotal, $amount, 2);
        if (bccomp($projected, $limit, 2) > 0) {
            throw new \RuntimeException(
                "Daily withdrawal limit ({$limit}) would be exceeded. Already requested in last 24h: {$todaysTotal}."
            );
        }
    }

    private function validatePaymentDetails(string $method, array $details): void
    {
        switch ($method) {
            case 'bank_transfer':
                foreach (['account_holder', 'account_number', 'bank_name'] as $key) {
                    if (empty($details[$key])) {
                        throw new \InvalidArgumentException("Missing payment_details.$key for bank_transfer.");
                    }
                }
                break;
            case 'mobile_money':
                foreach (['phone_number', 'provider'] as $key) {
                    if (empty($details[$key])) {
                        throw new \InvalidArgumentException("Missing payment_details.$key for mobile_money.");
                    }
                }
                break;
            case 'venue_cash':
                if (empty($details['venue_uuid'])) {
                    throw new \InvalidArgumentException('Missing payment_details.venue_uuid for venue_cash.');
                }
                if (!Venue::where('uuid', $details['venue_uuid'])->exists()) {
                    throw new \InvalidArgumentException('Selected venue not found.');
                }
                break;
            case 'voucher':
                // No required fields for voucher; system mints a code at payout time.
                break;
        }
    }
}
