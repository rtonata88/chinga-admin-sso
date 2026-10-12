<?php

namespace App\Channels\Simulator;

use App\Channels\Contracts\KycProvider;
use App\Channels\Contracts\KycResult;
use App\Channels\Contracts\KycUnavailable;
use Illuminate\Support\Facades\DB;

/** Plays a mobile-money operator's KYC from channel_sim_subscribers. Development and demos only. */
class SimulatorKyc implements KycProvider
{
    public function __construct(private readonly int $tenantId) {}

    public function lookup(string $msisdn): KycResult
    {
        $s = DB::table('channel_sim_subscribers')->where('tenant_id', $this->tenantId)->where('msisdn', $msisdn)->first();
        if ($s === null) {
            return new KycResult('not_found');
        }
        if ($s->kyc === 'unavailable') {
            throw new KycUnavailable('Simulated KYC outage');
        }

        return new KycResult($s->kyc === 'verified' ? 'verified' : 'unverified', $s->name, $s->date_of_birth);
    }
}
