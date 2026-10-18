<?php

namespace App\Support;

/**
 * The operator signature (docs/platform/seamless-wallet-spec.md, "Authentication and
 * signing"), the same both ways: X-Chinga-Operator, X-Chinga-Timestamp (Unix seconds)
 * and X-Chinga-Signature = hex(HMAC-SHA256(secret, timestamp + "." + raw_body)).
 * A timestamp more than five minutes off is refused, so a captured call cannot be
 * replayed later.
 */
final class OperatorSignature
{
    public const WINDOW_SECONDS = 300;

    public static function sign(string $secret, int $timestamp, string $rawBody): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }

    /** @return array<string, string> */
    public static function headers(string $operatorKey, string $secret, string $rawBody, ?int $now = null): array
    {
        $ts = $now ?? time();

        return [
            'X-Chinga-Operator' => $operatorKey,
            'X-Chinga-Timestamp' => (string) $ts,
            'X-Chinga-Signature' => self::sign($secret, $ts, $rawBody),
        ];
    }

    /** @param list<string> $secrets */
    public static function verify(array $secrets, ?string $timestamp, ?string $signature, string $rawBody, ?int $now = null): bool
    {
        if ($timestamp === null || $signature === null || ! ctype_digit($timestamp)) {
            return false;
        }
        if (abs(($now ?? time()) - (int) $timestamp) > self::WINDOW_SECONDS) {
            return false;
        }
        foreach ($secrets as $secret) {
            if (hash_equals(self::sign($secret, (int) $timestamp, $rawBody), strtolower($signature))) {
                return true;
            }
        }

        return false;
    }
}
