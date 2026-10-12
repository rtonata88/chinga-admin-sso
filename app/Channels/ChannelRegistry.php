<?php

namespace App\Channels;

use App\Channels\Contracts\KycProvider;
use App\Channels\Contracts\MobileMoneyProvider;
use App\Channels\Contracts\SmsProvider;
use App\Channels\Simulator\SimulatorKyc;
use App\Channels\Simulator\SimulatorMoney;
use App\Channels\Simulator\SimulatorSms;
use App\Models\ChannelProvider;
use App\Models\Tenant;

/**
 * The tenant's active provider of each kind and its driver (USSD design U-D4, U-D5).
 * Providers are plugged in by configuration; a new rail is a new driver here, behind
 * the same contract. Simulator drivers run only when channels.simulator is on.
 */
class ChannelRegistry
{
    public function provider(Tenant $tenant, string $kind): ?ChannelProvider
    {
        return ChannelProvider::where('tenant_id', $tenant->id)->where('kind', $kind)->where('status', 'active')->orderBy('id')->first();
    }

    public function kyc(Tenant $tenant): ?KycProvider
    {
        $p = $this->provider($tenant, 'kyc');

        return $p === null ? null : match ($p->driver) {
            'simulator' => $this->simulator(new SimulatorKyc($tenant->id)),
            default => throw new \RuntimeException("No KYC driver {$p->driver}"),
        };
    }

    public function money(ChannelProvider $p): MobileMoneyProvider
    {
        return match ($p->driver) {
            'simulator' => $this->simulator(new SimulatorMoney),
            default => throw new \RuntimeException("No mobile-money driver {$p->driver}"),
        };
    }

    public function sms(ChannelProvider $p): SmsProvider
    {
        return match ($p->driver) {
            'simulator' => $this->simulator(new SimulatorSms),
            default => throw new \RuntimeException("No SMS driver {$p->driver}"),
        };
    }

    /** @template T @param T $driver @return T */
    private function simulator(object $driver): object
    {
        if (! config('channels.simulator')) {
            throw new \RuntimeException('Simulator providers are disabled (CHANNEL_SIMULATOR=false).');
        }

        return $driver;
    }
}
