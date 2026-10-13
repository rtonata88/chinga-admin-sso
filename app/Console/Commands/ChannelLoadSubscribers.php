<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Channels\PlayerResolver;
use App\Services\WalletService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Load-test subscribers for the USSD demo tenant (development only): N simulator subscribers
 * (+267 72 000 0001 …), adults with verified KYC, admitted with the terms accepted, each SSO
 * wallet topped up to --fund. Idempotent; refuses to run outside local and testing, and
 * touches nothing outside the demo tenant.
 */
class ChannelLoadSubscribers extends Command
{
    protected $signature = 'channels:load-subscribers {count=100} {--fund=200.00} {--tenant=orange-bw-demo}';

    protected $description = 'Create funded simulator subscribers for USSD load tests (local only)';

    public function handle(PlayerResolver $resolver, WalletService $wallets): int
    {
        if (! app()->environment(['local', 'testing']) || ! config('channels.simulator')) {
            $this->error('channels:load-subscribers only runs in local or testing with CHANNEL_SIMULATOR=true.');

            return self::FAILURE;
        }
        $tenant = Tenant::where('slug', $this->option('tenant'))->firstOrFail();
        $count = max(1, min(2000, (int) $this->argument('count')));
        $fund = (string) $this->option('fund');
        for ($i = 1; $i <= $count; $i++) {
            $msisdn = sprintf('+2677200%04d', $i);
            DB::table('channel_sim_subscribers')->updateOrInsert(
                ['tenant_id' => $tenant->id, 'msisdn' => $msisdn],
                ['label' => "Load test {$i}", 'name' => "Load Tester {$i}", 'date_of_birth' => '1990-01-01', 'kyc' => 'verified', 'money_balance' => '1000.00', 'pin' => '1234', 'updated_at' => now(), 'created_at' => now()],
            );
            $resolved = $resolver->resolve($tenant, $msisdn);
            $user = User::where('tenant_id', $tenant->id)->where('phone', $msisdn)->first();
            if ($user === null) {
                $this->error("subscriber {$msisdn} was not admitted: ".($resolved['status'] ?? '?'));

                return self::FAILURE;
            }
            if (($resolved['status'] ?? '') === 'needs_terms') {
                $resolver->acceptTerms($user);
            }
            $wallet = $user->getOrCreateWallet();
            $short = bcsub($fund, (string) $wallet->fresh()->balance, 2);
            if (bccomp($short, '0', 2) > 0) {
                $wallets->deposit($wallet, $short, null, "loadtest_topup_{$user->id}_".now()->format('YmdHis'));
            }
        }
        $this->info("{$count} load-test subscribers ready (+26772000001 …), each with {$fund} in the SSO wallet.");

        return self::SUCCESS;
    }
}
