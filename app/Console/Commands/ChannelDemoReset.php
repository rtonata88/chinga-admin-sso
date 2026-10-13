<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\ChannelDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Resets the USSD demo so it starts from a first dial (development only): the demo
 * tenant's mobile accounts are retired (soft-deleted, their numbers released), the
 * simulator subscribers get their seeded KYC and balances back, and pending prompts
 * and the SMS inboxes are cleared. Refuses to run outside local and testing, and
 * touches nothing outside the demo tenant.
 */
class ChannelDemoReset extends Command
{
    protected $signature = 'channels:demo-reset {--force : Skip the confirmation}';

    protected $description = 'Reset the USSD demo tenant to a first dial (local only)';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('channels:demo-reset only runs in local or testing.');

            return self::FAILURE;
        }
        $tenant = Tenant::where('slug', ChannelDemoSeeder::TENANT_SLUG)->first();
        if ($tenant === null) {
            $this->error('No demo tenant: run php artisan db:seed --class=ChannelDemoSeeder first.');

            return self::FAILURE;
        }
        if (! $this->option('force') && ! $this->confirm("Retire every mobile account on {$tenant->name} and clear its demo state?")) {
            return self::SUCCESS;
        }
        $n = 0;
        DB::transaction(function () use ($tenant, &$n) {
            User::where('tenant_id', $tenant->id)->where('user_type', 'mobile')->get()->each(function (User $u) use (&$n) {
                $u->forceFill(['phone' => 'retired-'.$u->id, 'email' => 'retired-'.$u->id.'@retired.invalid', 'status' => 'suspended'])->save();
                $u->delete();
                $n++;
            });
            DB::table('mobile_money_transactions')->where('tenant_id', $tenant->id)->whereIn('status', ['initiated', 'pending', 'unknown'])->update(['status' => 'failed', 'last_error' => 'demo reset', 'updated_at' => now()]);
            DB::table('sms_messages')->where('tenant_id', $tenant->id)->delete();
            // Load-test subscribers and any others: the seeder below puts back the demo's six.
            DB::table('channel_sim_subscribers')->where('tenant_id', $tenant->id)->delete();
        });
        (new ChannelDemoSeeder)->run();
        $this->info("Retired {$n} mobile account(s); subscribers, prompts and inboxes reset.");

        return self::SUCCESS;
    }
}
