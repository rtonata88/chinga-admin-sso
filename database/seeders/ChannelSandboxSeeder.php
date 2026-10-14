<?php

namespace Database\Seeders;

use App\Models\ChannelProvider;
use App\Models\Tenant;
use Laravel\Passport\Client;

/**
 * The USSD integration sandbox (Orange's developers test our USSD endpoint against it): the
 * demo's setup on its own tenant, `orange-bw-sandbox`, with simulated Orange Money, KYC and
 * SMS, so no real money moves. Its gateway client is bound to this tenant only. Safe on the
 * production SSO only together with CHANNEL_SIMULATOR_TENANTS=<this tenant's uuid>, which
 * keeps the simulator away from every other tenant.
 *
 * Prints the provider and client secrets ONCE, when they are created: the provider secret
 * goes to Orange (the x-ussd-secret header), the client secret into the sandbox gateway's
 * environment. Neither is stored anywhere readable.
 */
class ChannelSandboxSeeder extends ChannelDemoSeeder
{
    public const TENANT_SLUG = 'orange-bw-sandbox';

    public const CLIENT_NAME = 'USSD gateway (sandbox)';

    protected function tenantName(): string
    {
        return 'Orange Botswana (sandbox)';
    }

    protected function ussdProviderName(): string
    {
        return 'Orange USSD (sandbox)';
    }

    protected function serviceCode(): string
    {
        return (string) config('channels.sandbox_code', static::CODE);
    }

    protected function report(Tenant $tenant, ChannelProvider $ussd, ?string $providerSecret, Client $client): void
    {
        $this->command?->info('USSD sandbox seeded.');
        $this->command?->line("  Tenant:           {$tenant->name} ({$tenant->uuid}), BWP");
        $this->command?->line('  Service code:     '.$this->serviceCode().' → Kulipi Kuna');
        $this->command?->line("  Provider key:     {$ussd->uuid}   (Orange calls POST /ussd/<provider key>)");
        $this->command?->line("  Gateway client:   {$client->id}");
        if ($client->plainSecret !== null) {
            $this->command?->warn("  Client secret:    {$client->plainSecret}   (shown once: put it in the gateway's SSO_CLIENT_SECRET)");
        }
        if ($providerSecret !== null) {
            $this->command?->warn("  Provider secret:  {$providerSecret}   (shown once: send to Orange securely, for the x-ussd-secret header)");
        }
        $this->command?->line("  Next: CHANNEL_SIMULATOR=true and CHANNEL_SIMULATOR_TENANTS={$tenant->uuid} in the SSO .env, then config:cache.");
    }
}
