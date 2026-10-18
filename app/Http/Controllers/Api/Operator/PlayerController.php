<?php

namespace App\Http\Controllers\Api\Operator;

use App\Models\GameSession;
use App\Models\Wallet;
use App\Services\Auth\SecurityAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * The operator's view of one of its players on our side: the balance we hold
 * (hosted mode), and blocking when the operator self-excludes or suspends the player.
 * A block ends the player's open game sessions at once, so no new stake lands; a win
 * already in flight is still paid (the engines' settlement credit does not need a
 * session), because a block never takes money the player already won.
 */
class PlayerController extends OperatorController
{
    public function balance(Request $request, string $playerId): JsonResponse
    {
        $user = $this->player($request, $playerId);
        if ($user === null) {
            return $this->error('player_unknown', 'No player with that id has launched a game yet.', 404);
        }
        if ($this->integration($request)->isSeamless()) {
            return $this->error('not_supported', 'With a seamless wallet the balance is yours.', 409);
        }
        $wallet = $user->getOrCreateWallet()->fresh();

        return response()->json(['status' => 'ok', 'player_id' => $playerId, 'balance' => (string) $wallet->balance, 'currency' => $wallet->currency]);
    }

    public function block(Request $request, string $playerId, SecurityAuditService $audit): JsonResponse
    {
        return $this->setBlocked($request, $playerId, true, $audit);
    }

    public function unblock(Request $request, string $playerId, SecurityAuditService $audit): JsonResponse
    {
        return $this->setBlocked($request, $playerId, false, $audit);
    }

    private function setBlocked(Request $request, string $playerId, bool $blocked, SecurityAuditService $audit): JsonResponse
    {
        $v = Validator::make($request->all(), ['reason' => ['nullable', 'string', 'max:255']]);
        if ($v->fails()) {
            return $this->error('invalid_request', $v->errors()->first(), 400);
        }
        $user = $this->player($request, $playerId);
        if ($user === null) {
            return $this->error('player_unknown', 'No player with that id has launched a game yet.', 404);
        }
        $user->forceFill(['status' => $blocked ? 'suspended' : 'active'])->save();
        $ended = 0;
        if ($blocked) {
            $wallet = Wallet::where('user_id', $user->id)->first();
            if ($wallet !== null) {
                GameSession::where('source_type', Wallet::class)->where('source_id', $wallet->id)->whereNull('ended_at')
                    ->get()->each(function (GameSession $s) use (&$ended) {
                        $s->end('forced');
                        $ended++;
                    });
            }
        }
        $audit->log($blocked ? 'operator.player.blocked' : 'operator.player.unblocked', $user, null, [
            'new_values' => ['player_id' => $playerId, 'reason' => $v->validated()['reason'] ?? null, 'sessions_ended' => $ended],
        ]);

        return response()->json(['status' => 'ok', 'player_id' => $playerId, 'blocked' => $blocked, 'sessions_ended' => $ended]);
    }
}
