<?php

namespace App\Channels\Simulator;

use App\Channels\Contracts\CallbackOutcome;
use App\Channels\Contracts\MobileMoneyProvider;
use App\Channels\Contracts\ProviderOutcome;
use App\Models\MobileMoneyTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Plays a mobile-money rail against channel_sim_subscribers. A collect stays pending
 * until the simulated phone approves it with the subscriber's PIN (SimulatorController),
 * as a real rail waits for the PIN prompt. A disbursement lands at once. Development and
 * demos only.
 */
class SimulatorMoney implements MobileMoneyProvider
{
    public function collect(MobileMoneyTransaction $txn): ProviderOutcome
    {
        return new ProviderOutcome('pending', 'SIM-'.$txn->reference);
    }

    public function disburse(MobileMoneyTransaction $txn): ProviderOutcome
    {
        $n = DB::table('channel_sim_subscribers')
            ->where('tenant_id', $txn->tenant_id)
            ->where('msisdn', $txn->msisdn)
            ->increment('money_balance', $txn->amount);

        return $n === 1
            ? new ProviderOutcome('succeeded', 'SIM-'.$txn->reference)
            : new ProviderOutcome('failed', null, 'No mobile-money account for this number');
    }

    public function status(MobileMoneyTransaction $txn): ProviderOutcome
    {
        return new ProviderOutcome($txn->status === 'succeeded' ? 'succeeded' : ($txn->status === 'failed' ? 'failed' : 'pending'), $txn->provider_reference);
    }

    public function verifyCallback(Request $request): ?CallbackOutcome
    {
        return null; // the simulator settles in-process, never by callback
    }
}
