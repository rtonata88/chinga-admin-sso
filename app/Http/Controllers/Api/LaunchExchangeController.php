<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OperatorLaunch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * POST /api/v1/launch/exchange: the game page, opened in the operator's iframe,
 * trades its one-time launch code for a player token. The code works once and for
 * 60 seconds. The token has no refresh: the refresh cookie is never sent from inside
 * another site's iframe, so when the token runs out the player reopens the game from
 * the operator's site, which mints a fresh launch.
 */
class LaunchExchangeController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $code = $request->input('code');
        if (! is_string($code) || $code === '' || strlen($code) > 100) {
            return $this->refused();
        }

        $launch = DB::transaction(function () use ($code) {
            $launch = OperatorLaunch::with(['user', 'tenant', 'game'])->where('code_hash', OperatorLaunch::hash($code))->lockForUpdate()->first();
            if ($launch === null || $launch->used_at !== null || $launch->expires_at->isPast()) {
                return null;
            }
            $launch->update(['used_at' => now()]);

            return $launch;
        });
        if ($launch === null) {
            return $this->refused();
        }
        $user = $launch->user;
        if ($user->status !== 'active' || $launch->tenant->status !== 'active') {
            return response()->json(['code' => 'player_blocked', 'message' => 'This account cannot play. Please contact '.$launch->tenant->name.'.'], 403);
        }

        $minutes = (int) config('operators.player_token_minutes', 240);
        $token = $user->createToken('operator-launch', ['openid', 'profile', 'wallet', 'gaming:history']);
        $token->token->forceFill(['expires_at' => now()->addMinutes($minutes)])->save();

        return response()->json([
            'access_token' => $token->accessToken,
            'expires_in' => $minutes * 60,
            'user' => ['uuid' => $user->uuid, 'name' => $user->name],
            'tenant' => ['uuid' => $launch->tenant->uuid, 'slug' => $launch->tenant->slug, 'name' => $launch->tenant->name],
            'game' => ['uuid' => $launch->game->uuid, 'slug' => $launch->game->slug],
            'lang' => $launch->lang,
            'return_url' => $launch->return_url,
        ]);
    }

    private function refused(): JsonResponse
    {
        return response()->json(['code' => 'launch_invalid', 'message' => 'This game link has expired or was already used. Open the game again from the operator\'s site.'], 410);
    }
}
