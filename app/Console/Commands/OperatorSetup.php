<?php

namespace App\Console\Commands;

use App\Models\OperatorIntegration;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Creates or updates a tenant's operator integration, and rotates its secret. A new
 * secret is printed once and never again: hand it to the operator over a secure
 * channel. After a rotation the previous secret keeps verifying for 24 hours.
 */
class OperatorSetup extends Command
{
    protected $signature = 'operators:setup {tenant : slug or uuid}
        {--mode= : hosted or seamless}
        {--wallet-url= : the operator wallet API base (seamless)}
        {--origin=* : a site allowed to embed the games, e.g. https://www.operator.com}
        {--ip=* : an address or range the operator calls from (none = any)}
        {--rotate : issue a new secret}
        {--suspend : stop accepting calls}
        {--activate : accept calls again}';

    protected $description = 'Set up an operator integration (launch, transfers, seamless wallet) for a tenant';

    public function handle(): int
    {
        $key = (string) $this->argument('tenant');
        $tenant = Tenant::where('slug', $key)->orWhere('uuid', $key)->first();
        if ($tenant === null) {
            $this->error("No tenant {$key}.");

            return self::FAILURE;
        }
        $mode = $this->option('mode');
        if ($mode !== null && ! in_array($mode, [OperatorIntegration::MODE_HOSTED, OperatorIntegration::MODE_SEAMLESS], true)) {
            $this->error('--mode must be hosted or seamless.');

            return self::FAILURE;
        }
        $walletUrl = $this->option('wallet-url');
        if ($walletUrl !== null && ! str_starts_with($walletUrl, 'https://') && ! app()->isLocal()) {
            $this->error('--wallet-url must be https.');

            return self::FAILURE;
        }
        foreach ($this->option('origin') as $origin) {
            if (! preg_match('#^https://[a-z0-9.-]+(:\d+)?$#i', $origin) && ! app()->isLocal()) {
                $this->error("Not an https origin: {$origin} (scheme and host only, no path).");

                return self::FAILURE;
            }
        }

        $integration = OperatorIntegration::where('tenant_id', $tenant->id)->first();
        $newSecret = null;
        if ($integration === null) {
            $newSecret = Str::random(48);
            $integration = OperatorIntegration::create([
                'tenant_id' => $tenant->id,
                'operator_key' => (string) Str::uuid(),
                'secret' => $newSecret,
                'wallet_mode' => $mode ?? OperatorIntegration::MODE_HOSTED,
                'status' => 'active',
            ]);
        } elseif ($this->option('rotate')) {
            $newSecret = Str::random(48);
            $integration->fill(['previous_secret' => $integration->secret, 'secret' => $newSecret, 'secret_rotated_at' => now()]);
        }

        if ($mode !== null && $integration->exists && $mode !== $integration->wallet_mode) {
            // Money held under one mode must never be shown or spent under the other.
            $funded = $integration->fundedPlayerWallets();
            if ($funded > 0) {
                $this->error("{$funded} operator player wallet(s) still hold a {$integration->wallet_mode} balance. Settle them before changing the mode.");

                return self::FAILURE;
            }
        }

        $integration->fill(array_filter([
            'wallet_mode' => $mode,
            'wallet_base_url' => $walletUrl,
            'embed_origins' => $this->option('origin') ?: null,
            'allowed_ips' => $this->option('ip') ?: null,
            'status' => $this->option('suspend') ? 'suspended' : ($this->option('activate') ? 'active' : null),
        ], fn ($v) => $v !== null));
        if ($integration->isSeamless() && ! $integration->wallet_base_url) {
            $this->error('A seamless integration needs --wallet-url.');

            return self::FAILURE;
        }
        $integration->save();

        $this->table(['Field', 'Value'], [
            ['Tenant', "{$tenant->name} ({$tenant->slug})"],
            ['Operator key (X-Chinga-Operator)', $integration->operator_key],
            ['Wallet mode', $integration->wallet_mode],
            ['Wallet URL', $integration->wallet_base_url ?? '—'],
            ['Embed origins', implode(', ', $integration->embed_origins ?? []) ?: '—'],
            ['Allowed IPs', implode(', ', $integration->allowed_ips ?? []) ?: 'any'],
            ['Status', $integration->status],
        ]);
        if ($newSecret !== null) {
            $this->warn('Secret (shown once, give it to the operator over a secure channel):');
            $this->line($newSecret);
        }

        return self::SUCCESS;
    }
}
