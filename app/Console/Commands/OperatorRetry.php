<?php

namespace App\Console\Commands;

use App\Services\Operator\SeamlessWallet;
use Illuminate\Console\Command;

/** Re-sends wins and returned stakes an operator has not confirmed yet, and rollbacks of refused stakes. Every minute. */
class OperatorRetry extends Command
{
    protected $signature = 'operators:retry {--limit=200}';

    protected $description = 'Retry operator wallet credits and rollbacks that have no clear answer yet';

    public function handle(SeamlessWallet $seamless): int
    {
        $counts = $seamless->retryDue((int) $this->option('limit'));
        $this->line("settled {$counts['settled']}, retrying {$counts['retrying']}, abandoned {$counts['abandoned']}");

        return self::SUCCESS;
    }
}
