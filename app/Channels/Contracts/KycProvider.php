<?php

namespace App\Channels\Contracts;

/**
 * A mobile-money operator's subscriber KYC (USSD design U-D10). Age is computed by
 * us from the date of birth it returns; a player is never asked whether they are 18.
 */
interface KycProvider
{
    /** @throws KycUnavailable when the provider cannot answer now (not a "no") */
    public function lookup(string $msisdn): KycResult;
}
