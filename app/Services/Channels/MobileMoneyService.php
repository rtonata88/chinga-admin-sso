<?php

namespace App\Services\Channels;

use App\Channels\ChannelRegistry;
use App\Channels\Contracts\ProviderOutcome;
use App\Channels\Msisdn;
use App\Models\MobileMoneyTransaction;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Services\WalletService;
use App\Services\WithdrawalService;
use App\Support\Currencies;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Mobile money as the play balance's rail (USSD design U-D7 to U-D9). Deposits move
 * money from the subscriber's mobile-money account into their SSO wallet; withdrawals
 * from the wallet back to the same, registered number. The wallet balance is the only
 * balance a player is shown.
 *
 *  - A deposit credits the wallet ONCE, by reference mm_dep_<id>, and only on a
 *    verified success (callback or status), never on the request.
 *  - A withdrawal holds the wallet first (the existing withdrawal workflow), then pays
 *    out. A definite failure refunds the hold; an unknown outcome is NEVER refunded
 *    blindly: it is checked again and, failing that, left for a person.
 */
class MobileMoneyService
{
    public function __construct(
        private readonly ChannelRegistry $registry,
        private readonly WalletService $wallets,
        private readonly WithdrawalService $withdrawals,
        private readonly SmsService $sms,
    ) {}

    /** Per-tenant limits, in the tenant's currency (tenants.settings.mobile_money). */
    public function limits(Tenant $tenant): array
    {
        $defaults = ['min_deposit' => '5.00', 'max_deposit' => '5000.00', 'min_withdrawal' => '5.00', 'max_withdrawal' => '5000.00'];
        $config = $tenant->settings['mobile_money'] ?? [];

        return array_merge($defaults, is_array($config) ? $config : []);
    }

    public function deposit(User $user, string $amount): MobileMoneyTransaction
    {
        $tenant = Tenant::findOrFail($user->tenant_id);
        $limits = $this->limits($tenant);
        $this->assertAmount($amount, $limits['min_deposit'], $limits['max_deposit'], $tenant);
        $provider = $this->registry->provider($tenant, 'mobile_money') ?? throw new \RuntimeException('No mobile-money provider for this tenant.');
        $wallet = $user->getOrCreateWallet();

        $txn = DB::transaction(function () use ($tenant, $user, $provider, $amount, $wallet) {
            $txn = MobileMoneyTransaction::create([
                'tenant_id' => $tenant->id, 'user_id' => $user->id, 'channel_provider_id' => $provider->id,
                'direction' => 'deposit', 'amount' => $amount, 'currency' => $wallet->currency,
                'msisdn' => $user->phone, 'reference' => 'pending-'.bin2hex(random_bytes(8)), 'status' => 'initiated',
            ]);
            $txn->update(['reference' => "mm_dep_{$txn->id}"]);

            return $txn;
        });
        $this->apply($txn, $this->call(fn () => $this->registry->money($provider)->collect($txn)));

        return $txn->fresh();
    }

    /** @return array{transaction: ?MobileMoneyTransaction, request: WithdrawalRequest} */
    public function withdraw(User $user, string $amount): array
    {
        $tenant = Tenant::findOrFail($user->tenant_id);
        $limits = $this->limits($tenant);
        $this->assertAmount($amount, $limits['min_withdrawal'], $limits['max_withdrawal'], $tenant);
        $provider = $this->registry->provider($tenant, 'mobile_money') ?? throw new \RuntimeException('No mobile-money provider for this tenant.');

        // Holds the funds (only winnings are withdrawable) and auto-approves under the tenant's threshold.
        $request = $this->withdrawals->request($user, [
            'amount' => $amount,
            'payment_method' => 'mobile_money',
            'payment_details' => ['phone_number' => $user->phone, 'provider' => $provider->name],
        ]);
        if ($request->status !== 'approved') {
            return ['transaction' => null, 'request' => $request];
        }

        return ['transaction' => $this->disburse($request), 'request' => $request->fresh()];
    }

    /** Pays out an approved mobile-money withdrawal request, to the registered number only. */
    public function disburse(WithdrawalRequest $request): MobileMoneyTransaction
    {
        $user = User::findOrFail($request->user_id);
        $tenant = Tenant::findOrFail($request->tenant_id);
        $provider = $this->registry->provider($tenant, 'mobile_money') ?? throw new \RuntimeException('No mobile-money provider for this tenant.');
        $txn = MobileMoneyTransaction::firstOrCreate(
            ['withdrawal_request_id' => $request->id],
            [
                'tenant_id' => $tenant->id, 'user_id' => $user->id, 'channel_provider_id' => $provider->id,
                'direction' => 'withdrawal', 'amount' => $request->net_amount, 'fee' => $request->fee_amount,
                'currency' => $request->currency, 'msisdn' => $user->phone,
                'reference' => 'mm_wd_wr'.$request->id, 'status' => 'initiated',
            ],
        );
        if ($txn->status !== 'initiated') {
            return $txn;
        }
        $this->apply($txn, $this->call(fn () => $this->registry->money($provider)->disburse($txn)));

        return $txn->fresh();
    }

    /**
     * Records a provider's answer and settles money exactly once. Safe to call again
     * with the same answer (callbacks repeat; status checks overlap).
     */
    public function apply(MobileMoneyTransaction $txn, ProviderOutcome $outcome, ?array $raw = null): void
    {
        $settled = DB::transaction(function () use ($txn, $outcome, $raw) {
            $locked = MobileMoneyTransaction::lockForUpdate()->findOrFail($txn->id);
            if ($raw !== null) {
                $locked->callbacks = array_merge($locked->callbacks ?? [], [['at' => now()->toIso8601String(), 'body' => $raw]]);
            }
            if (in_array($locked->status, ['succeeded', 'failed', 'reversed'], true)) {
                $locked->save();

                return null; // already final; a repeat changes nothing
            }
            $locked->provider_reference ??= $outcome->providerReference;
            $locked->status_checks = $locked->status_checks + ($raw === null ? 1 : 0);
            if ($outcome->status === 'pending' || $outcome->status === 'unknown') {
                $locked->status = $outcome->status === 'unknown' ? 'unknown' : 'pending';
                $locked->last_error = $outcome->error;
                $locked->next_check_at = now()->addSeconds(min(3600, 30 * (2 ** min(6, $locked->status_checks))));
                $locked->save();

                return null;
            }
            $locked->status = $outcome->status;
            $locked->last_error = $outcome->error;
            $locked->next_check_at = null;
            $locked->settled_at = now();
            if ($locked->direction === 'deposit' && $outcome->status === 'succeeded') {
                $wallet = User::findOrFail($locked->user_id)->getOrCreateWallet();
                $tx = $this->wallets->deposit($wallet, (string) $locked->amount, null, $locked->reference);
                $locked->wallet_transaction_id = $tx->id;
            }
            if ($locked->direction === 'withdrawal' && $locked->withdrawal_request_id !== null) {
                $request = WithdrawalRequest::findOrFail($locked->withdrawal_request_id);
                if ($outcome->status === 'succeeded') {
                    $this->withdrawals->markPaid($request, null, $locked->provider_reference ?? $locked->reference);
                } else {
                    $this->withdrawals->reject($request, null, 'Mobile-money payout failed: '.($outcome->error ?? 'declined'));
                }
            }
            $locked->save();

            return $locked;
        });
        if ($settled !== null) {
            $this->notifySettled($settled);
        }
    }

    private function notifySettled(MobileMoneyTransaction $txn): void
    {
        $user = User::find($txn->user_id);
        $tenant = Tenant::find($txn->tenant_id);
        if ($user === null || $tenant === null) {
            return;
        }
        $profile = Currencies::forTenant($tenant);
        $balance = Currencies::format((string) $user->getOrCreateWallet()->fresh()->balance, $profile);
        $key = match ([$txn->direction, $txn->status]) {
            ['deposit', 'succeeded'] => 'sms.deposit_ok',
            ['deposit', 'failed'] => 'sms.deposit_failed',
            ['withdrawal', 'succeeded'] => 'sms.withdraw_ok',
            default => 'sms.withdraw_failed',
        };
        $this->sms->sendKey($tenant, $user, $txn->msisdn, $key, [
            'amount' => Currencies::format((string) $txn->amount, $profile),
            'balance' => $balance,
            'provider' => $txn->provider?->name ?? 'mobile money',
            'ref' => $txn->reference,
        ]);
    }

    /** A provider call that throws is an unknown outcome, never a failure: money may have moved. */
    private function call(callable $fn): ProviderOutcome
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            Log::warning('channel.money_call_failed', ['error' => $e->getMessage()]);

            return new ProviderOutcome('unknown', null, mb_substr($e->getMessage(), 0, 250));
        }
    }

    private function assertAmount(string $amount, string $min, string $max, Tenant $tenant): void
    {
        $profile = Currencies::forTenant($tenant);
        $pattern = $profile['decimals'] === 0 ? '/^\d{1,9}$/' : '/^\d{1,9}(\.\d{1,2})?$/';
        if (! preg_match($pattern, $amount) || bccomp($amount, $min, 2) < 0 || bccomp($amount, $max, 2) > 0) {
            throw new \InvalidArgumentException(sprintf('Enter an amount from %s to %s.', Currencies::format($min, $profile), Currencies::format($max, $profile)));
        }
    }

    /** For logs only. */
    public static function masked(MobileMoneyTransaction $txn): string
    {
        return Msisdn::mask($txn->msisdn);
    }
}
