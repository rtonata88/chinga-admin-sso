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
            'simulator' => $this->simulator($tenant, new SimulatorKyc($tenant->id)),
            default => throw new \RuntimeException("No KYC driver {$p->driver}"),
        };
    }

    public function money(ChannelProvider $p): MobileMoneyProvider
    {
        return match ($p->driver) {
            'simulator' => $this->simulator($p->tenant, new SimulatorMoney),
            default => throw new \RuntimeException("No mobile-money driver {$p->driver}"),
        };
    }

    public function sms(ChannelProvider $p): SmsProvider
    {
        return match ($p->driver) {
            'simulator' => $this->simulator($p->tenant, new SimulatorSms),
            default => throw new \RuntimeException("No SMS driver {$p->driver}"),
        };
    }

    /**
     * Whether the simulator providers may run for this tenant. They need CHANNEL_SIMULATOR on,
     * and outside local development and tests the tenant must also be listed in
     * CHANNEL_SIMULATOR_TENANTS: a sandbox tenant, never one with real players. A simulated
     * mobile-money approval credits the wallet with no money behind it.
     */
    public static function simulatorAllowed(?Tenant $tenant): bool
    {
        if (! config('channels.simulator') || $tenant === null) {
            return false;
        }
        $listed = config('channels.simulator_tenants', []);
        if ($listed === [] && app()->environment(['local', 'testing'])) {
            return true;
        }

        return in_array($tenant->uuid, $listed, true);
    }

    /** @template T @param T $driver @return T */
    private function simulator(?Tenant $tenant, object $driver): object
    {
        if (! self::simulatorAllowed($tenant)) {
            throw new \RuntimeException('Simulator providers are disabled for this tenant (CHANNEL_SIMULATOR, CHANNEL_SIMULATOR_TENANTS).');
        }

        return $driver;
    }
}
