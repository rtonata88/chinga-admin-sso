<?php

namespace App\Http\Controllers\Api\Channels;

use App\Channels\Messages;
use App\Channels\Msisdn;
use App\Http\Controllers\Controller;
use App\Models\ChannelProvider;
use App\Models\Game;
use App\Models\Tenant;
use App\Services\Channels\PlayerResolver;
use App\Support\Currencies;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The USSD gateway's view of tenants and players (USSD design §5). client_credentials + channel:ussd. */
class ChannelPlayersController extends Controller
{
    use ChannelAuth;

    /** What the gateway needs to route and word every session: providers, codes, tenants, strings. */
    public function routing(Request $request): JsonResponse
    {
        $tenantIds = $this->boundTenantIds($request);
        $tenants = Tenant::whereIn('id', $tenantIds)->where('status', 'active')->get();
        $providers = ChannelProvider::whereIn('tenant_id', $tenantIds)->where('kind', 'ussd')->where('status', 'active')->get();
        $codes = DB::table('ussd_service_codes as c')
            ->join('games as g', 'g.id', '=', 'c.game_id')
            ->join('tenants as t', 't.id', '=', 'c.tenant_id')
            ->leftJoin('tenant_games as tg', fn ($j) => $j->on('tg.tenant_id', '=', 'c.tenant_id')->on('tg.game_id', '=', 'c.game_id'))
            ->whereIn('c.tenant_id', $tenantIds)->where('c.status', 'active')
            ->get(['c.code', 'c.channel_provider_id', 't.uuid as tenant_uuid', 'g.uuid as game_uuid', 'g.slug as game_slug', 'g.name as game_catalogue_name', 'tg.display_name', 'tg.enabled']);

        return response()->json([
            'providers' => $providers->map(fn (ChannelProvider $p) => [
                'key' => $p->uuid,
                'tenant_uuid' => $tenants->firstWhere('id', $p->tenant_id)?->uuid,
                'driver' => $p->driver,
                'name' => $p->name,
                // The inbound side only: protocol settings, the shared secret and allowed addresses.
                'inbound' => $p->config['inbound'] ?? [],
            ])->values(),
            'service_codes' => $codes->map(fn ($c) => [
                'code' => $c->code,
                'provider_key' => $providers->firstWhere('id', $c->channel_provider_id)?->uuid,
                'tenant_uuid' => $c->tenant_uuid,
                'game_uuid' => $c->game_uuid,
                'game_slug' => $c->game_slug,
                'game_name' => $c->display_name ?: $c->game_catalogue_name,
                'enabled' => (bool) $c->enabled,
            ])->values(),
            'tenants' => $tenants->map(function (Tenant $t) {
                $languages = Messages::languages($t);
                $money = ChannelProvider::where('tenant_id', $t->id)->where('kind', 'mobile_money')->where('status', 'active')->first();

                return [
                    'uuid' => $t->uuid,
                    'slug' => $t->slug,
                    'name' => $t->name,
                    'country_code' => $t->country_code,
                    'dial_code' => Msisdn::dialCode($t->country_code),
                    'currency' => Currencies::forTenant($t),
                    'languages' => $languages,
                    'money_provider' => $money?->name,
                    'support' => $t->settings['support_contact'] ?? $t->contact_phone,
                    'terms_url' => $t->settings['terms_url'] ?? null,
                    'mobile_money' => app(\App\Services\Channels\MobileMoneyService::class)->limits($t),
                    'retry_minutes' => (int) ($t->settings['kyc_retry_minutes'] ?? 30),
                    'messages' => collect($languages)->mapWithKeys(fn ($l) => [$l => Messages::forTenant($t, $l)]),
                ];
            })->values(),
        ]);
    }

    public function resolve(Request $request, PlayerResolver $resolver): JsonResponse
    {
        $tenant = $this->tenantFor($request);
        $msisdn = Msisdn::normalize((string) $request->input('msisdn', ''), Msisdn::dialCode($tenant->country_code));
        abort_if($msisdn === null, 422, 'Not a phone number.');

        return response()->json($resolver->resolve($tenant, $msisdn) + ['msisdn' => $msisdn]);
    }

    public function acceptTerms(Request $request, PlayerResolver $resolver): JsonResponse
    {
        $tenant = $this->tenantFor($request);
        $resolver->acceptTerms($this->playerFor($request, $tenant));

        return response()->json(['status' => 'admitted']);
    }

    public function language(Request $request): JsonResponse
    {
        $tenant = $this->tenantFor($request);
        $user = $this->playerFor($request, $tenant);
        $language = (string) $request->input('language');
        abort_unless(in_array($language, Messages::languages($tenant), true), 422, 'Not a language of this tenant.');
        $user->forceFill(['language' => $language])->save();

        return response()->json(['language' => $language]);
    }

    /**
     * A short-lived player access token (U-D11), so the gateway calls a game engine as
     * the player through the engine's own auth and wallet sessions. Refused for a player
     * who has not accepted the terms or whose tenant does not have the game.
     */
    public function token(Request $request): JsonResponse
    {
        $tenant = $this->tenantFor($request);
        $user = $this->playerFor($request, $tenant);
        abort_if($user->status !== 'active', 403, 'This account cannot play.');
        abort_if($user->terms_accepted_at === null || $user->terms_version !== config('channels.terms_version'), 403, 'Terms not accepted.');
        $game = Game::where('uuid', (string) $request->input('game_uuid'))->first();
        $enabled = $game !== null && DB::table('tenant_games')->where('tenant_id', $tenant->id)->where('game_id', $game->id)->where('enabled', true)->exists();
        abort_unless($enabled, 403, 'This operator does not offer that game.');

        $minutes = (int) config('channels.player_token_minutes', 5);
        $token = $user->createToken('channel-ussd', ['openid', 'profile', 'wallet', 'gaming:history']);
        $token->token->forceFill(['expires_at' => now()->addMinutes($minutes)])->save();

        return response()->json(['access_token' => $token->accessToken, 'expires_in' => $minutes * 60]);
    }

    /** Balance (the SSO wallet, the only balance a player is shown) and what is withdrawable. */
    public function summary(Request $request, string $uuid): JsonResponse
    {
        $tenant = $this->tenantFor($request);
        $user = $this->playerFor($request, $tenant, $uuid);
        $wallet = $user->getOrCreateWallet()->fresh();
        $today = DB::table('wallet_transactions')->where('wallet_id', $wallet->id)->where('created_at', '>=', now()->startOfDay());
        $stakes = (string) (clone $today)->where('type', 'bet')->sum('amount');
        $wins = (string) (clone $today)->where('type', 'win')->sum('amount');
        $first = (clone $today)->whereIn('type', ['bet', 'win'])->min('created_at');

        return response()->json([
            'balance' => (string) $wallet->balance,
            'withdrawable' => (string) ($wallet->winnings_balance ?? '0.00'),
            'currency' => $wallet->currency,
            'today' => [
                'net' => bcsub($wins, $stakes, 2),
                'minutes' => $first === null ? 0 : (int) floor(now()->diffInMinutes($first, true)),
            ],
            'language' => $user->language,
        ]);
    }
}
