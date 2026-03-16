<?php

namespace App\Console\Commands;

use App\Models\GameSession;
use App\Models\VoucherCode;
use Illuminate\Console\Command;

class CleanupGameSessionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'game-sessions:cleanup';

    /**
     * The console command description.
     */
    protected $description = 'Clean up stale game sessions that have timed out (no activity for 30+ minutes)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $staleSessions = GameSession::whereNull('ended_at')
            ->where('updated_at', '<', now()->subMinutes(30))
            ->get();

        if ($staleSessions->isEmpty()) {
            $this->info('No stale game sessions found.');

            return self::SUCCESS;
        }

        $this->info("Found {$staleSessions->count()} stale game session(s). Cleaning up...");

        $cleaned = 0;

        foreach ($staleSessions as $session) {
            $currentBalance = $session->source->fresh()->balance;

            $session->update([
                'ended_at' => now(),
                'end_reason' => 'timeout',
                'balance_end' => $currentBalance,
            ]);

            if ($session->source_type === VoucherCode::class) {
                $session->source->update([
                    'status' => 'active',
                    'current_terminal_id' => null,
                    'current_session_id' => null,
                ]);
            }

            $cleaned++;
        }

        $this->info("Cleaned up {$cleaned} stale game session(s).");

        return self::SUCCESS;
    }
}
