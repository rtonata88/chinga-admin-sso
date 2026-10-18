<?php

namespace App\Http\Controllers\Api\Operator;

use App\Models\Game;
use App\Models\OperatorLaunch;
use App\Services\Operator\OperatorPlayerResolver;
use App\Services\Operator\WalletModeConflict;
use App\Support\Currencies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * POST /api/v1/operator/launch: the operator's server asks for a game link for one of
 * its players. The player is found or created on the operator's tenant, and the answer
 * is a one-time URL for the iframe, valid for 60 seconds and exchanged once. Who the
 * player is comes only from this signed call, never from the browser.
 */
class LaunchController extends OperatorController
{
    public function __invoke(Request $request, OperatorPlayerResolver $players): JsonResponse
    {
        $v = Validator::make($request->all(), [
            'player_id' => self::PLAYER_ID,
            'game' => ['required', 'string', 'max:100'],
            'currency' => ['required', 'string', 'size:3'],
            'display_name' => ['nullable', 'string', 'max:100'],
            'lang' => ['nullable', 'string', 'max:10', 'regex:/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/'],
            'return_url' => ['nullable', 'url:https', 'max:500'],
        ]);
        if ($v->fails()) {
            return $this->error('invalid_request', $v->errors()->first(), 400);
        }
        $data = $v->validated();
        $tenant = $this->integration($request)->tenant;

        $currency = Currencies::forTenant($tenant)['code'];
        if (strtoupper($data['currency']) !== $currency) {
            return $this->error('currency_mismatch', "This operator plays in {$currency}.", 400);
        }

        $game = Game::where('slug', $data['game'])->orWhere('uuid', $data['game'])->first();
        $enabled = $game !== null && $game->isActive() && $game->launch_url
            && DB::table('tenant_games')->where('tenant_id', $tenant->id)->where('game_id', $game->id)->where('enabled', true)->exists();
        if (! $enabled) {
            return $this->error('game_unavailable', 'That game is not available for this operator.', 404);
        }

        try {
            ['user' => $user, 'created' => $created] = $players->resolve($tenant, $data['player_id'], $data['display_name'] ?? null, $data['lang'] ?? null, $this->integration($request)->isSeamless());
        } catch (WalletModeConflict) {
            return $this->error('wallet_mode_conflict', 'This player still has a balance held under the previous wallet mode. Contact Chinga.', 409);
        }
        if ($user->status !== 'active') {
            return $this->error('player_blocked', 'This player cannot play.', 403);
        }

        $code = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $launch = OperatorLaunch::create([
            'code_hash' => OperatorLaunch::hash($code),
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'game_id' => $game->id,
            'lang' => $data['lang'] ?? null,
            'return_url' => $data['return_url'] ?? null,
            'expires_at' => now()->addSeconds(OperatorLaunch::TTL_SECONDS),
        ]);

        return response()->json([
            'status' => 'ok',
            'launch_url' => rtrim($game->launch_url, '/').'/launch?'.http_build_query(['code' => $code, 'tenant' => $tenant->slug]),
            'expires_at' => $launch->expires_at->toIso8601String(),
            'player' => ['player_id' => $data['player_id'], 'created' => $created],
        ]);
    }
}
