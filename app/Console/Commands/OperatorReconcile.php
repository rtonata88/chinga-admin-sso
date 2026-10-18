<?php

namespace App\Console\Commands;

use App\Models\OperatorIntegration;
use App\Services\Operator\OperatorReconciler;
use Illuminate\Console\Command;

/** Matches yesterday's (or --date's) seamless movements against each operator's statement. */
class OperatorReconcile extends Command
{
    protected $signature = 'operators:reconcile {--date= : YYYY-MM-DD, UTC; default yesterday} {--tenant= : one tenant slug}';

    protected $description = 'Reconcile seamless operator wallets against the operators\' daily statements';

    public function handle(OperatorReconciler $reconciler): int
    {
        $date = $this->option('date') ?? now('UTC')->subDay()->toDateString();
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->error('--date must be YYYY-MM-DD.');

            return self::FAILURE;
        }
        $query = OperatorIntegration::with('tenant')->where('wallet_mode', 'seamless')->where('status', 'active');
        if ($slug = $this->option('tenant')) {
            $query->whereHas('tenant', fn ($q) => $q->where('slug', $slug));
        }
        $clean = true;
        foreach ($query->get() as $integration) {
            $r = $reconciler->reconcile($integration, $date);
            $this->line("{$integration->tenant->slug} {$date}: {$r->status} (equal {$r->equal}, only ours {$r->only_ours}, only theirs {$r->only_theirs}, different {$r->different})");
            $clean = $clean && $r->status === 'matched';
        }

        return $clean ? self::SUCCESS : self::FAILURE;
    }
}
