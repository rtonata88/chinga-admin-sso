<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Thin client for chinga-fantasy's /api/admin/* endpoints. Uses a cached
 * client_credentials token from our own Passport so the target service can
 * verify the JWT via JWKS without a shared secret.
 */
class FantasyAdminClient
{
    private const TOKEN_CACHE_KEY = 'sso_internal_access_token';
    private const TOKEN_SCOPE = 'gaming:read';

    /**
     * Probe the fantasy backend's /api/health endpoint. Does NOT require auth.
     * Returns ['status' => 'ok'|'down'|'degraded', 'message' => ?string, 'db' => ?string].
     * Cached for 15 seconds so dashboards don't hammer the backend.
     */
    public function health(): array
    {
        return Cache::remember('fantasy_health_probe', 15, function () {
            $baseUrl = rtrim((string) config('services.chinga_fantasy.api_url'), '/');
            try {
                $response = Http::acceptJson()->timeout(3)->get($baseUrl.'/api/health');
                if (!$response->successful()) {
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
                    'message' => 'Cannot reach fantasy backend at '.$baseUrl,
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

    public function listJackpotTransactions(?string $tenantUuid = null, int $limit = 50, int $offset = 0): array
    {
        return $this->get('/api/admin/jackpot-transactions', array_filter([
            'tenant_uuid' => $tenantUuid,
            'limit' => $limit,
            'offset' => $offset,
        ]));
    }

    private function get(string $path, array $query = []): array
    {
        $baseUrl = rtrim((string) config('services.chinga_fantasy.api_url'), '/');

        $response = Http::withToken($this->getAccessToken())
            ->acceptJson()
            ->timeout(10)
            ->get($baseUrl.$path, $query);

        if (!$response->successful()) {
            throw new \RuntimeException(
                "FantasyAdminClient GET {$path} failed: HTTP ".$response->status().' '.$response->body()
            );
        }

        return $response->json() ?? [];
    }

    private function getAccessToken(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);
        if ($cached) {
            return $cached;
        }

        $clientId = config('services.sso_internal.client_id');
        $clientSecret = config('services.sso_internal.client_secret');

        if (!$clientId || !$clientSecret) {
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
                'scope' => self::TOKEN_SCOPE,
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException(
                'SSO self-token request failed: HTTP '.$response->status().' '.$response->body()
            );
        }

        $payload = $response->json();
        $token = $payload['access_token'] ?? null;
        $expiresIn = (int) ($payload['expires_in'] ?? 600);

        if (!$token) {
            throw new \RuntimeException('SSO self-token response missing access_token.');
        }

        // Cache until 30s before expiry
        Cache::put(self::TOKEN_CACHE_KEY, $token, max(30, $expiresIn - 30));

        return $token;
    }
}
