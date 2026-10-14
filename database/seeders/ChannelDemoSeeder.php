<?php

namespace Database\Seeders;

use App\Models\ChannelProvider;
use App\Models\Game;
use App\Models\Tenant;
use Database\Seeders\Concerns\CreatesOAuthClients;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;

/**
 * The USSD demo (Kulipi Kuna USSD design, U3–U5): an Orange Botswana demo tenant in
 * pula, simulator providers for USSD, Orange Money, KYC and SMS, the *123*45# code for
 * Kulipi Kuna, test subscribers for every KYC outcome, and the gateway's OAuth client
 * bound to the tenant. Idempotent. The client secret is written to
 * storage/app/ussd-gateway-client.json (gitignored), never printed.
 */
class ChannelDemoSeeder extends Seeder
{
    use CreatesOAuthClients;

    public const TENANT_SLUG = 'orange-bw-demo';

    public const CLIENT_NAME = 'USSD gateway (dev)';

    public const CODE = '*123*45#';

    protected function tenantName(): string
    {
        return 'Orange Botswana (demo)';
    }

    protected function ussdProviderName(): string
    {
        return 'USSD simulator';
    }

    protected function serviceCode(): string
    {
        return static::CODE;
    }

    public function run(): void
    {
        $game = Game::where('slug', 'kulipi-kuna')->firstOrFail();
        $tenant = Tenant::firstOrCreate(['slug' => static::TENANT_SLUG], [
            'uuid' => (string) Str::uuid(),
            'name' => $this->tenantName(),
            'contact_email' => 'demo@orange-bw.invalid',
            'contact_phone' => '+267 123',
            'country_code' => 'BW',
            'currency' => 'BWP',
            'timezone' => 'Africa/Gaborone',
            'status' => 'active',
        ]);
        $verifyBase = rtrim((string) config('channels.verify_base_url'), '/');
        $tenant->update(['settings' => array_merge($tenant->settings ?? [], [
            'channel_languages' => ['en'],
            'support_contact' => 'Orange customer care on 123',
            'terms_url' => "{$verifyBase}/terms",
            'kyc_retry_minutes' => 30,
            'mobile_money' => ['min_deposit' => '5.00', 'max_deposit' => '2000.00', 'min_withdrawal' => '5.00', 'max_withdrawal' => '2000.00'],
            'withdrawals' => ['min_amount' => '5.00', 'max_amount' => '2000.00', 'daily_limit' => '10000.00', 'fee_pct' => '0.00', 'auto_approve_under' => '1000.00', 'allowed_methods' => ['mobile_money']],
        ])]);
        if (! DB::table('tenant_games')->where('tenant_id', $tenant->id)->where('game_id', $game->id)->exists()) {
            DB::table('tenant_games')->insert(['tenant_id' => $tenant->id, 'game_id' => $game->id, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        }

        $provider = fn (string $kind, string $driver, string $name, array $config = []) => ChannelProvider::firstOrCreate(
            ['tenant_id' => $tenant->id, 'kind' => $kind, 'driver' => $driver],
            ['name' => $name, 'config' => $config, 'status' => 'active'],
        );
        $providerSecret = Str::random(40);
        $ussdExisted = ChannelProvider::where(['tenant_id' => $tenant->id, 'kind' => 'ussd', 'driver' => 'generic'])->exists();
        $ussd = $provider('ussd', 'generic', $this->ussdProviderName(), ['inbound' => [
            'protocol' => 'generic',
            'format' => 'json',
            'fields' => ['session' => 'sessionId', 'msisdn' => 'phoneNumber', 'code' => 'serviceCode', 'input' => 'text'],
            'input_mode' => 'cumulative',
            'separator' => '*',
            'reply' => 'prefix',
            'secret' => $providerSecret,
            'allow_ips' => [],
        ]]);
        $provider('mobile_money', 'simulator', 'Orange Money');
        $provider('kyc', 'simulator', 'Orange Money');
        $provider('sms', 'simulator', 'Orange SMS');

        DB::table('ussd_service_codes')->updateOrInsert(
            ['channel_provider_id' => $ussd->id, 'code' => $this->serviceCode()],
            ['tenant_id' => $tenant->id, 'game_id' => $game->id, 'status' => 'active', 'updated_at' => now(), 'created_at' => now()],
        );

        $subscribers = [
            ['+26771000001', 'Adult, verified', 'Kagiso Molefe', '1990-04-12', 'verified', '500.00'],
            ['+26771000002', 'Adult, low balance', 'Neo Sebina', '1996-09-03', 'verified', '20.00'],
            ['+26771000003', 'No date of birth', 'Tumelo Dube', null, 'verified', '300.00'],
            ['+26771000004', 'Under 18', 'Lesedi Kgosi', now()->subYears(16)->toDateString(), 'verified', '100.00'],
            ['+26771000005', 'KYC not verified', 'Mpho Ramotswe', '1988-02-20', 'unverified', '250.00'],
            ['+26771000006', 'KYC service down', 'Boitumelo Phiri', '1992-11-30', 'unavailable', '250.00'],
        ];
        foreach ($subscribers as [$msisdn, $label, $name, $dob, $kyc, $balance]) {
            DB::table('channel_sim_subscribers')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'msisdn' => $msisdn],
                ['label' => $label, 'name' => $name, 'date_of_birth' => $dob, 'kyc' => $kyc, 'money_balance' => $balance, 'pin' => '1234', 'updated_at' => now(), 'created_at' => now()],
            );
        }

        $client = $this->firstOrCreateClient(static::CLIENT_NAME, fn (ClientRepository $c) => $c->createClientCredentialsGrantClient(static::CLIENT_NAME));
        $this->restrictClientScopes($client, ['channel:ussd']);
        DB::table('channel_client_tenants')->updateOrInsert(
            ['oauth_client_id' => $client->id, 'tenant_id' => $tenant->id],
            ['updated_at' => now(), 'created_at' => now()],
        );
        $this->report($tenant, $ussd, $ussdExisted ? null : $providerSecret, $client);
    }

    /** The demo keeps the gateway's secret in a gitignored file for scripts/ussd-demo.sh, never printed. */
    protected function report(Tenant $tenant, ChannelProvider $ussd, ?string $providerSecret, \Laravel\Passport\Client $client): void
    {
        // Tests make their own client in their own database: never overwrite the dev gateway's file.
        if ($client->plainSecret !== null && ! app()->runningUnitTests()) {
            file_put_contents(storage_path('app/ussd-gateway-client.json'), json_encode([
                'client_id' => $client->id,
                'client_secret' => $client->plainSecret,
            ], JSON_PRETTY_PRINT));
            @chmod(storage_path('app/ussd-gateway-client.json'), 0600);
        }

        $this->command?->info('USSD demo seeded.');
        $this->command?->line("  Tenant:         {$tenant->name} ({$tenant->uuid}), BWP");
        $this->command?->line('  Service code:   '.$this->serviceCode().' → Kulipi Kuna');
        $this->command?->line("  USSD provider:  {$ussd->uuid}");
        $this->command?->line("  Gateway client: {$client->id} (secret in storage/app/ussd-gateway-client.json when newly created)");
    }
}
