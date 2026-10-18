<?php

namespace App\Services\Operator;

use App\Models\OperatorCall;
use App\Models\OperatorIntegration;
use App\Support\OperatorSignature;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Calls an operator's wallet API (docs/platform/seamless-wallet-spec.md): signed, JSON,
 * answered within 3 seconds, never following a redirect. Every call is logged without
 * its body. Answers are classified, never interpreted here: SeamlessWallet decides what
 * an answer means for the stake or win in hand.
 */
class OperatorWalletClient
{
    public const TIMEOUT_SECONDS = 3;

    /** @param array<string, mixed> $fields */
    public function call(OperatorIntegration $integration, string $endpoint, array $fields): OperatorAnswer
    {
        $body = json_encode($fields + [
            'operator_id' => $integration->operator_key,
            'currency' => $integration->tenant->currency,
            'request_id' => (string) Str::uuid(),
            'sent_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $url = rtrim((string) $integration->wallet_base_url, '/').'/wallet/'.$endpoint;
        $started = microtime(true);

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->connectTimeout(self::TIMEOUT_SECONDS)
                ->withoutRedirecting()
                ->withHeaders(OperatorSignature::headers($integration->operator_key, $integration->secret, $body) + ['Accept' => 'application/json'])
                ->withBody($body, 'application/json')
                ->post($url);
        } catch (\Throwable $e) {
            return $this->logged($integration, $endpoint, $fields, $body, null, OperatorAnswer::unknown('unreachable: '.mb_substr($e->getMessage(), 0, 150)), $started);
        }

        $json = $response->json();
        $status = $response->status();
        $answer = match (true) {
            $status >= 500 || $status < 200 || ($status >= 300 && $status < 400) => OperatorAnswer::unknown("HTTP {$status}"),
            ! is_array($json) => OperatorAnswer::unknown("HTTP {$status}: not JSON"),
            $response->successful() && ($json['status'] ?? null) === 'ok' => OperatorAnswer::ok($json),
            is_string($json['code'] ?? null) => OperatorAnswer::error($json['code'], $json),
            default => OperatorAnswer::unknown("HTTP {$status}: no status"),
        };

        return $this->logged($integration, $endpoint, $fields, $body, $status, $answer, $started);
    }

    /** @param array<string, mixed> $fields */
    private function logged(OperatorIntegration $integration, string $endpoint, array $fields, string $body, ?int $status, OperatorAnswer $answer, float $started): OperatorAnswer
    {
        OperatorCall::create([
            'operator_integration_id' => $integration->id,
            'direction' => 'out',
            'endpoint' => $endpoint,
            'reference' => isset($fields['reference']) ? mb_substr((string) $fields['reference'], 0, 100) : null,
            'http_status' => $status,
            'outcome' => $answer->kind === 'error' ? (string) $answer->code : $answer->kind,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'body_sha256' => hash('sha256', $body),
            'error' => $answer->isUnknown() ? mb_substr((string) $answer->message, 0, 255) : null,
        ]);

        return $answer;
    }
}
