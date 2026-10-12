<?php

namespace App\Channels\Contracts;

use App\Models\MobileMoneyTransaction;
use Illuminate\Http\Request;

/**
 * A mobile-money rail (USSD design U-D5, U-D8, U-D9). Every call is keyed by our own
 * reference, so a retry never moves money twice. Collect asks the subscriber to approve
 * on their own phone with their PIN; we never see a PIN.
 */
interface MobileMoneyProvider
{
    /** Ask the subscriber to pay us (a deposit). Usually answers pending; the outcome comes by callback or status. */
    public function collect(MobileMoneyTransaction $txn): ProviderOutcome;

    /** Pay the subscriber (a withdrawal), to their own registered number only. */
    public function disburse(MobileMoneyTransaction $txn): ProviderOutcome;

    /** Where a transaction stands at the provider, for the status sweeper. */
    public function status(MobileMoneyTransaction $txn): ProviderOutcome;

    /** A callback's verified outcome, or null when its signature does not check out. */
    public function verifyCallback(Request $request): ?CallbackOutcome;
}
