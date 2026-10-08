<?php

namespace App\Console\Commands;

use App\Contracts\ProvablyFairAdminClient;
use App\Models\Game;
use App\Services\GameAdminClientFactory;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Nightly realised-vs-theoretical RTP check (Fantasy v2 PRD §11): every
 * game whose engine publishes an RTP endpoint is asked for the last N
 * days; a deviation past the threshold on a sample big enough to mean
 * something is logged as a warning (and shown on the console). This is
 * how the next pricing bug is caught before a player finds it.
 *
 * An engine that sends the realised RTP's standard error (Dorex: its return per stake is
 * heavy-tailed) is judged at 3.5 standard errors instead of the flat threshold and sample
 * size, so a long hold-to-end run neither hides a real drift nor raises a false one.
 */
class RtpDriftCommand extends Command
{
    protected $signature = 'games:rtp-drift {--days=7} {--threshold=0.03} {--min-bets=1000}';

    protected $description = 'Compare realised RTP with the theoretical figure per game and warn on drift';

    public function handle(GameAdminClientFactory $factory): int
    {
        $days = max(1, (int) $this->option('days'));
        $threshold = (float) $this->option('threshold');
        $minBets = (int) $this->option('min-bets');
        $to = CarbonImmutable::now();
        $from = $to->subDays($days);
        $drifted = 0;

        foreach (Game::query()->whereNotNull('backend_url')->orderBy('name')->get() as $game) {
            $client = $factory->forGame($game);
            if (! $client instanceof ProvablyFairAdminClient) {
                continue;
            }
            try {
                $r = $client->rtp(null, $from->toIso8601String(), $to->toIso8601String());
            } catch (\Throwable $e) {
                $this->warn("{$game->name}: unreachable ({$e->getMessage()})");
                Log::warning('rtp_drift.unreachable', ['game' => $game->slug, 'error' => $e->getMessage()]);

                continue;
            }
            $realised = isset($r['realised_rtp']) ? (float) $r['realised_rtp'] : null;
            $theoretical = isset($r['theoretical_rtp']) ? (float) $r['theoretical_rtp'] : null;
            $bets = (int) ($r['bets_placed'] ?? 0);
            if ($realised === null || $theoretical === null) {
                $this->line("{$game->name}: no data in the last {$days} days");

                continue;
            }
            $delta = $realised - $theoretical;
            $se = isset($r['standard_error']) && is_numeric($r['standard_error']) ? (float) $r['standard_error'] : null;
            $line = sprintf('%s: realised %.2f%% vs theoretical %.2f%% (%+.2f pts, %d bets)', $game->name, $realised * 100, $theoretical * 100, $delta * 100, $bets);
            if ($se !== null && $se > 0) {
                $band = 3.5 * $se;
                $line .= sprintf(' — band ±%.2f pts (3.5σ)', $band * 100);
                $drift = abs($delta) > $band;
            } else {
                $drift = $bets >= $minBets && abs($delta) >= $threshold;
            }
            if ($drift) {
                $drifted++;
                $this->error("DRIFT {$line}");
                Log::warning('rtp_drift.detected', ['game' => $game->slug, 'realised' => $realised, 'theoretical' => $theoretical, 'delta' => $delta, 'bets' => $bets, 'days' => $days, 'standard_error' => $se]);
            } else {
                $this->info($line.($se === null && $bets < $minBets ? ' — sample too small to judge' : ''));
            }
        }

        return $drifted > 0 ? self::FAILURE : self::SUCCESS;
    }
}
