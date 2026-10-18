<?php

namespace App\Http\Middleware;

use App\Models\OperatorCall;
use App\Models\OperatorIntegration;
use App\Support\OperatorSignature;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * An operator's server calling us (docs/platform/operator-integration.md). The
 * signature is checked over the raw body, the timestamp within five minutes, and the
 * caller's address against the integration's allow-list when it has one. The request
 * is then bound to the integration's tenant: an operator only ever acts on its own
 * players. Every call, refused or not, is logged without its body.
 */
class AuthenticateOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        $started = microtime(true);
        $raw = $request->getContent();
        $key = (string) $request->header('X-Chinga-Operator', '');
        $integration = $key === '' ? null : OperatorIntegration::with('tenant')->where('operator_key', $key)->first();

        $refusal = match (true) {
            $integration === null => 'unknown_operator',
            ! OperatorSignature::verify($integration->verifyingSecrets(), $request->header('X-Chinga-Timestamp'), $request->header('X-Chinga-Signature'), $raw) => 'bad_signature',
            $integration->status !== 'active' || $integration->tenant?->status !== 'active' => 'operator_inactive',
            ! empty($integration->allowed_ips) && ! IpUtils::checkIp((string) $request->ip(), $integration->allowed_ips) => 'ip_not_allowed',
            default => null,
        };
        if ($refusal !== null) {
            Log::warning('operator.refused', ['reason' => $refusal, 'operator' => $key, 'ip' => $request->ip(), 'path' => $request->path()]);
            $this->log($integration, $request, $raw, 401, $refusal, $started);

            return response()->json(['status' => 'error', 'code' => 'unauthorized', 'message' => 'The request could not be authenticated.'], 401);
        }

        app()->instance('current_tenant', $integration->tenant);
        $request->attributes->set('operator_integration', $integration);

        $response = $next($request);
        $this->log($integration, $request, $raw, $response->getStatusCode(), $response->isSuccessful() ? 'ok' : 'error', $started);

        return $response;
    }

    private function log(?OperatorIntegration $integration, Request $request, string $raw, int $status, string $outcome, float $started): void
    {
        OperatorCall::create([
            'operator_integration_id' => $integration?->id,
            'direction' => 'in',
            'endpoint' => mb_substr($request->method().' '.$request->path(), 0, 100),
            'reference' => is_string($request->input('reference')) ? mb_substr($request->input('reference'), 0, 100) : null,
            'http_status' => $status,
            'outcome' => $outcome,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'body_sha256' => $raw === '' ? null : hash('sha256', $raw),
        ]);
    }
}
