<?php

namespace App\Console\Commands;

use App\Channels\ChannelRegistry;
use App\Channels\Msisdn;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Registers a test phone number with a sandbox tenant's simulated Orange Money and KYC, so a
 * provider's own test SIM can dial the sandbox: an adult with verified KYC, simulated
 * Orange Money (PIN 1234). Runs only for a tenant the simulator may run for
 * (CHANNEL_SIMULATOR_TENANTS), so it can never touch a real operator.
 */
class ChannelSandboxSubscriber extends Command
{
    protected $signature = 'channels:sandbox-subscriber {msisdn} {--name=Sandbox tester} {--dob=1990-01-01} {--money=500.00} {--tenant=orange-bw-sandbox}';

    protected $description = 'Add or update a test phone number on a USSD sandbox tenant (simulated money and KYC)';

    public function handle(): int
    {
        $tenant = Tenant::where('slug', $this->option('tenant'))->first();
        if (! ChannelRegistry::simulatorAllowed($tenant)) {
            $this->error('That tenant is not a simulator sandbox: it must exist and be listed in CHANNEL_SIMULATOR_TENANTS, with CHANNEL_SIMULATOR=true.');

            return self::FAILURE;
        }
        $msisdn = Msisdn::normalize((string) $this->argument('msisdn'), Msisdn::dialCode($tenant->country_code));
        $money = (string) $this->option('money');
        $dob = (string) $this->option('dob');
        if ($msisdn === null || ! preg_match('/^\d+(\.\d{1,2})?$/', $money) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dob)) {
            $this->error('Give a phone number, --money as a decimal (500.00) and --dob as YYYY-MM-DD.');

            return self::FAILURE;
        }
        DB::table('channel_sim_subscribers')->updateOrInsert(
            ['tenant_id' => $tenant->id, 'msisdn' => $msisdn],
            ['label' => 'Provider test SIM', 'name' => (string) $this->option('name'), 'date_of_birth' => $dob, 'kyc' => 'verified', 'money_balance' => $money, 'pin' => '1234', 'updated_at' => now(), 'created_at' => now()],
        );
        $this->info("{$msisdn} is on {$tenant->name}: verified KYC, born {$dob}, simulated Orange Money {$money} (PIN 1234).");

        return self::SUCCESS;
    }
}
