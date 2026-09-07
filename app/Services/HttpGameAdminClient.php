<?php

namespace App\Services;

use App\Contracts\GameAdminClient;
use App\Exceptions\MissingBackendUrlException;
use App\Models\Game;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * GameAdminClient over HTTP for any game with a games.backend_url. Calls
 * carry a cached client_credentials token from our own Passport so the
 * target service verifies the JWT via JWKS without a shared secret.
 */
class HttpGameAdminClient implements GameAdminClient
{
    protected const TOKEN_CACHE_KEY = 'sso_internal_access_token';

    protected const TOKEN_SCOPE = 'gaming:read';

    protected const HEALTH_CACHE_SECONDS = 15;

    public function __construct(protected ?Game $game = null) {}

    public function game(): Game
    {
        if ($this->game === null) {
            throw new \LogicException(static::class.' needs a Game.');
        }

        return $this->game;
    }

    /** Base URL without a trailing slash. */
    protected function baseUrl(): string
    {
        $game = $this->game();
        if (! $game->hasBackend()) {
            throw MissingBackendUrlException::forGame($game);
        }

        return rtrim((string) $game->backend_url, '/');
    }

    protected function healthCacheKey(): string
    {
        return 'game_health_probe:'.$this->game()->uuid;
    }

    protected function label(): string
    {
        return $this->game()->name;
    }

    /**
     * Probe the backend's /api/health endpoint. Does NOT require auth.
     * Returns ['status' => 'ok'|'down'|'degraded', 'message' => ?string, 'db' => ?string].
     * Cached briefly so dashboards don't hammer the backend.
     */
    public function health(): array
    {
        return Cache::remember($this->healthCacheKey(), static::HEALTH_CACHE_SECONDS, function () {
            try {
                $baseUrl = $this->baseUrl();
            } catch (MissingBackendUrlException $e) {
                return ['status' => 'down', 'message' => $e->getMessage()];
            }
            try {
                $response = Http::acceptJson()->timeout(3)->get($baseUrl.'/api/health');
                if (! $response->successful()) {
                    $body = $response->json() ?? [];

                    return [
                        'status' => $body['status'] ?? 'down',
                        'db' => $body['db'] ?? null,
                        'message' => 'Backend reported HTTP '.$response->status(),
                    ];
                }

                return $response->json() ?? ['status' => 'ok'];
            } catch (\Throwable $e) {
                return [
                    'status' => 'down',
                    'message' => "Cannot reach {$this->label()} backend at {$baseUrl}",
                ];
            }
        });
    }

    public function statsSummary(?string $tenantUuid = null, ?string $from = null, ?string $to = null): array
    {
        return $this->get('/api/admin/stats/summary', array_filter([
            'tenant_uuid' => $tenantUuid,
            'from' => $from,
            'to' => $to,
        ]));
    }

    public function statsByDay(?string $tenantUuid = null, ?string $from = null, ?string $to = null): array
    {
        return $this->get('/api/admin/stats/by-day', array_filter([
            'tenant_uuid' => $tenantUuid,
            'from' => $from,
            'to' => $to,
        ]));
    }

    public function statsByTenant(?string $from = null, ?string $to = null): array
    {
        return $this->get('/api/admin/stats/by-tenant', array_filter([
            'from' => $from,
            'to' => $to,
        ]));
    }

    public function listRounds(?string $tenantUuid = null, int $limit = 50, int $offset = 0): array
    {
        return $this->get('/api/admin/rounds', array_filter([
            'tenant_uuid' => $tenantUuid,
            'limit' => $limit,
            'offset' => $offset,
        ]));
    }

    public function getRound(int $id): array
    {
        return $this->get("/api/admin/rounds/{$id}");
    }

    public function listRoundBets(int $id, int $limit = 200, int $offset = 0): array
    {
        return $this->get("/api/admin/rounds/{$id}/bets", compact('limit', 'offset'));
    }

    public function listUserBets(string $uuid, ?string $tenantUuid = null, int $limit = 50, int $offset = 0): array
    {
        return $this->get("/api/admin/users/{$uuid}/bets", array_filter([
            'tenant_uuid' => $tenantUuid,
            'limit' => $limit,
            'offset' => $offset,
        ]));
    }

    /**
     * Cross-round recent-bet preview used by the admin dashboard's Live
     * Activity table. Each row already has picks, odds and potential payout
     * pre-joined, so no N+1.
     */
    public function recentBets(?string $tenantUuid = null, int $limit = 10): array
    {
        return $this->get('/api/admin/bets/recent', array_filter([
            'tenant_uuid' => $tenantUuid,
            'limit' => $limit,
        ]));
    }

    protected function get(string $path, array $query = []): array
    {
        $baseUrl = $this->baseUrl();

        $response = Http::withToken($this->getAccessToken())
            ->acceptJson()
            ->timeout(10)
            ->get($baseUrl.$path, $query);

        if (! $response->successful()) {
            throw new \RuntimeException(
                "{$this->label()} admin GET {$path} failed: HTTP ".$response->status().' '.$response->body()
            );
        }

        return $response->json() ?? [];
    }

    /** client_credentials token against our own /oauth/token, shared by every game client. */
    protected function getAccessToken(): string
    {
        $cached = Cache::get(static::TOKEN_CACHE_KEY);
        if ($cached) {
            return $cached;
        }

        $clientId = config('services.sso_internal.client_id');
        $clientSecret = config('services.sso_internal.client_secret');

        if (! $clientId || ! $clientSecret) {
            throw new \RuntimeException(
                'SSO_INTERNAL_CLIENT_ID / SSO_INTERNAL_CLIENT_SECRET must be set.'
            );
        }

        $baseUrl = rtrim((string) config('app.url'), '/');

        $response = Http::asForm()
            ->acceptJson()
            ->timeout(5)
            ->post($baseUrl.'/oauth/token', [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'scope' => static::TOKEN_SCOPE,
            ]);

        if (! $response->successful()) {
            throw new \RuntimeException(
                'SSO self-token request failed: HTTP '.$response->status().' '.$response->body()
            );
        }

        $payload = $response->json();
        $token = $payload['access_token'] ?? null;
        $expiresIn = (int) ($payload['expires_in'] ?? 600);

        if (! $token) {
            throw new \RuntimeException('SSO self-token response missing access_token.');
        }

        // Cache until 30s before expiry
        Cache::put(static::TOKEN_CACHE_KEY, $token, max(30, $expiresIn - 30));

        return $token;
    }
}
