<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Live Wagers Monitor — operator console screen v1.
 *
 * Currently returns the placeholder dataset shown in the design
 * reference so the visual lands first. The real-data pass should pull
 * recent bets from chinga-fantasy via App\Services\FantasyAdminClient
 * (listRoundBets / a future cross-round listWagers endpoint) and shape
 * the response into the `wagers` array below.
 */
class LiveWagersController extends Controller
{
    public function index(Request $request): Response
    {
        $status = (string) $request->query('status', 'all');

        return Inertia::render('operator/live-wagers', [
            'lastUpdated' => now()->setTimezone('Africa/Windhoek')->format('H:i:s') . ' CAT',
            'kpis' => $this->kpis(),
            'filters' => [
                'active' => in_array($status, ['all', 'live', 'pending', 'flagged', 'settled'], true) ? $status : 'all',
                'sport' => 'All',
                'stakeRange' => 'Stake ≥ NAD 500',
                'timeRange' => 'Last 24h',
                'sort' => 'Stake desc',
                'counts' => [
                    ['label' => 'All',     'value' => 'all',     'count' => 1284],
                    ['label' => 'Live',    'value' => 'live',    'count' => 812],
                    ['label' => 'Pending', 'value' => 'pending', 'count' => 340],
                    ['label' => 'Flagged', 'value' => 'flagged', 'count' => 14, 'danger' => true],
                    ['label' => 'Settled', 'value' => 'settled', 'count' => 118],
                ],
            ],
            'wagers' => $this->mockWagers(),
            'pagination' => [
                'from' => 1,
                'to' => 10,
                'total' => 1284,
                'currentPage' => 1,
                'lastPage' => 129,
            ],
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function kpis(): array
    {
        return [
            [
                'label' => 'Live wagers',
                'value' => '1,284',
                'delta' => ['sign' => 'pos', 'text' => '+12.4%'],
                'meta' => 'vs. yesterday',
            ],
            [
                'label' => 'Handle · 24h',
                'value' => '487.2K',
                'brass' => true,
                'meta' => 'NAD · gross',
                'spark' => [30, 55, 42, 68, 50, 80, 62, 90],
            ],
            [
                'label' => 'Pending payouts',
                'value' => '14',
                'delta' => ['sign' => 'neg', 'text' => '3 over SLA'],
                'meta' => 'NAD 38.6K total',
            ],
            [
                'label' => 'Active players · now',
                'value' => '2,917',
                'delta' => ['sign' => 'pos', 'text' => '+6.1%'],
                'meta' => 'peak today: 3,402',
            ],
        ];
    }

    /**
     * Placeholder rows mirrored from the reference HTML. Replace with
     * real data sourced from FantasyAdminClient (or a successor service)
     * in the next pass.
     *
     * @return array<int, array<string, mixed>>
     */
    private function mockWagers(): array
    {
        return [
            [
                'id' => 'w-1',
                'placedAt' => '21:14:02', 'placedDate' => '08 May',
                'player' => ['name' => 'Johannes Nakaambo', 'id' => 'PL-04719', 'isVip' => false, 'initials' => 'JN'],
                'event' => ['name' => 'Brave Warriors vs Bafana Bafana', 'market' => 'AFCON Q · Match winner · Full time'],
                'selection' => ['pick' => 'Brave Warriors'],
                'stake' => ['amount' => '2,500', 'currency' => 'NAD'],
                'odds' => '2.40', 'potential' => '6,000',
                'status' => 'live',
            ],
            [
                'id' => 'w-2',
                'placedAt' => '21:13:48', 'placedDate' => '08 May',
                'player' => ['name' => 'Selma Mwetupunga', 'id' => 'PL-12044', 'isVip' => true, 'initials' => 'SM'],
                'event' => ['name' => 'Man City vs Arsenal', 'market' => 'EPL · Both teams to score · Yes'],
                'selection' => ['pick' => 'Yes', 'suffix' => 'BTTS'],
                'stake' => ['amount' => '15,000', 'currency' => 'NAD'],
                'odds' => '1.72', 'potential' => '25,800',
                'status' => 'flagged-aml',
            ],
            [
                'id' => 'w-3',
                'placedAt' => '21:12:31', 'placedDate' => '08 May',
                'player' => ['name' => 'Petrus Hangula', 'id' => 'PL-08812', 'isVip' => false, 'initials' => 'PH'],
                'event' => ['name' => 'Lakers vs Celtics', 'market' => 'NBA · Total points · Over 224.5'],
                'selection' => ['pick' => 'Over 224.5'],
                'stake' => ['amount' => '800', 'currency' => 'NAD'],
                'odds' => '1.91', 'potential' => '1,528',
                'status' => 'live',
            ],
            [
                'id' => 'w-4',
                'placedAt' => '21:11:09', 'placedDate' => '08 May',
                'player' => ['name' => 'Anna Nashilongo', 'id' => 'PL-19031', 'isVip' => false, 'initials' => 'AN'],
                'event' => ['name' => 'Real Madrid vs Barcelona', 'market' => 'La Liga · 1X2 · Draw'],
                'selection' => ['pick' => 'Draw'],
                'stake' => ['amount' => '450', 'currency' => 'NAD'],
                'odds' => '3.60', 'potential' => '1,620',
                'status' => 'pending',
            ],
            [
                'id' => 'w-5',
                'placedAt' => '21:10:54', 'placedDate' => '08 May',
                'player' => ['name' => 'Tangeni Kaunapawa', 'id' => 'PL-22580', 'isVip' => true, 'initials' => 'TK'],
                'event' => ['name' => 'Acc · 4-leg · Sportsbook', 'market' => 'Liverpool win · BVB win · Inter win · PSG win'],
                'selection' => ['pick' => 'Accumulator', 'suffix' => '4 legs'],
                'stake' => ['amount' => '5,000', 'currency' => 'NAD'],
                'odds' => '11.45', 'potential' => '57,250',
                'status' => 'live',
                'legs' => ['resolved' => 2, 'total' => 4],
            ],
            [
                'id' => 'w-6',
                'placedAt' => '21:09:22', 'placedDate' => '08 May',
                'player' => ['name' => 'Kambonde Shikongo', 'id' => 'PL-30217', 'isVip' => false, 'initials' => 'KS'],
                'event' => ['name' => 'Aviator · Round #884,201', 'market' => 'Casino · Crash · Cash-out 14.2x'],
                'selection' => ['pick' => 'Cash-out 14.2x'],
                'stake' => ['amount' => '3,200', 'currency' => 'NAD'],
                'odds' => '14.20', 'potential' => '45,440',
                'status' => 'flagged-velocity',
            ],
            [
                'id' => 'w-7',
                'placedAt' => '21:08:46', 'placedDate' => '08 May',
                'player' => ['name' => 'Esther Muharukua', 'id' => 'PL-15904', 'isVip' => false, 'initials' => 'EM'],
                'event' => ['name' => 'Chelsea vs Spurs', 'market' => 'EPL · Player to score · Palmer'],
                'selection' => ['pick' => 'Anytime', 'suffix' => 'Cole Palmer'],
                'stake' => ['amount' => '250', 'currency' => 'NAD'],
                'odds' => '2.10', 'potential' => '525',
                'status' => 'live',
            ],
            [
                'id' => 'w-8',
                'placedAt' => '21:07:11', 'placedDate' => '08 May',
                'player' => ['name' => 'Frans Nehemia', 'id' => 'PL-04412', 'isVip' => false, 'initials' => 'FN'],
                'event' => ['name' => 'Roulette · Table 12', 'market' => 'Casino · Live · Straight 17'],
                'selection' => ['pick' => 'Straight 17'],
                'stake' => ['amount' => '120', 'currency' => 'NAD'],
                'odds' => '35.00', 'potential' => '4,200',
                'status' => 'settled-loss',
            ],
            [
                'id' => 'w-9',
                'placedAt' => '21:06:33', 'placedDate' => '08 May',
                'player' => ['name' => 'Lahja Ngula', 'id' => 'PL-27719', 'isVip' => false, 'initials' => 'LN'],
                'event' => ['name' => 'F1 · Miami GP', 'market' => 'Motorsport · Race winner'],
                'selection' => ['pick' => 'Verstappen'],
                'stake' => ['amount' => '1,000', 'currency' => 'NAD'],
                'odds' => '1.55', 'potential' => '1,550',
                'status' => 'pending',
            ],
            [
                'id' => 'w-10',
                'placedAt' => '21:05:18', 'placedDate' => '08 May',
                'player' => ['name' => 'David Mungunda', 'id' => 'PL-11203', 'isVip' => false, 'initials' => 'DM'],
                'event' => ['name' => 'Virtual football · League C', 'market' => 'Virtuals · Correct score · 2-1'],
                'selection' => ['pick' => '2 — 1'],
                'stake' => ['amount' => '50', 'currency' => 'NAD'],
                'odds' => '8.50', 'potential' => '425',
                'status' => 'void',
            ],
        ];
    }
}
