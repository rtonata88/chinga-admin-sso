<?php

namespace App\Http\Controllers\Api\Channels;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Channels\SmsService;
use App\Support\Currencies;
use App\Support\OAuthClientGames;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Kulipi Kuna's own-clock notifications (USSD design U-D18): the commitment before the
 * first pick, the outcome and the revealed seed at the end. Sent by the game engine
 * (client_credentials + wallet:write, bound to the game) to players with a phone; the
 * SSO words them in the player's language and the tenant's money.
 */
class LadderNotifyController extends Controller
{
    public function __invoke(Request $request, SmsService $sms): JsonResponse
    {
        $data = $request->validate([
            'game_uuid' => ['required', 'uuid'],
            'tenant_uuid' => ['required', 'uuid'],
            'user_uuid' => ['required', 'uuid'],
            'event' => ['required', 'in:prepared,collected,no_action,cap,busted'],
            'ladder_id' => ['required', 'integer', 'min:1'],
            'commitment' => ['required', 'regex:/^[0-9a-f]{64}$/'],
            'server_seed' => ['nullable', 'regex:/^[0-9a-f]{64}$/'],
            'level' => ['nullable', 'integer'],
            'payout' => ['nullable', 'regex:/^\d+\.\d{2}$/'],
            'stake' => ['nullable', 'regex:/^\d+\.\d{2}$/'],
            'hand' => ['nullable', 'in:left,right'],
        ]);
        $game = Game::where('uuid', $data['game_uuid'])->firstOrFail();
        $client = OAuthClientGames::clientIdFromRequest($request);
        abort_unless($client !== null && OAuthClientGames::isBound($client, $game->id), 403, 'OAuth client is not authorized for this game.');
        $tenant = Tenant::where('uuid', $data['tenant_uuid'])->firstOrFail();
        $user = User::where('uuid', $data['user_uuid'])->where('tenant_id', $tenant->id)->first();
        if ($user === null || $user->phone === null || $user->user_type !== 'mobile') {
            return response()->json(['status' => 'skipped']); // web players get no SMS
        }
        $profile = Currencies::forTenant($tenant);
        $name = DB::table('tenant_games')->where('tenant_id', $tenant->id)->where('game_id', $game->id)->value('display_name') ?: $game->name;
        $base = rtrim((string) config('channels.verify_base_url'), '/');
        $params = [
            'game' => $name,
            'ladder' => $data['ladder_id'],
            'commitment' => $data['commitment'],
            'seed' => $data['server_seed'] ?? '',
            'level' => $data['level'] ?? '',
            'hand' => isset($data['hand']) ? (\App\Channels\Messages::forTenant($tenant, $user->language)["kk.hand_{$data['hand']}"] ?? $data['hand']) : '',
            'payout' => isset($data['payout']) ? Currencies::format($data['payout'], $profile) : '',
            'stake' => isset($data['stake']) ? Currencies::format($data['stake'], $profile) : '',
            'balance' => Currencies::format((string) $user->getOrCreateWallet()->fresh()->balance, $profile),
            'verify_url' => "{$base}/v/{$data['ladder_id']}?c=".substr($data['commitment'], 0, 12),
        ];
        $key = $data['event'] === 'prepared' ? 'sms.commitment' : "sms.{$data['event']}";
        $sent = $sms->sendKey($tenant, $user, $user->phone, $key, $params);

        return response()->json(['status' => $sent->status]);
    }
}
