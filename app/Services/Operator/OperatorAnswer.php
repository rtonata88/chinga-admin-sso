<?php

namespace App\Services\Operator;

/**
 * What an operator's wallet said. `ok` and `error` are final (the spec: "a clear answer
 * is final"); `unknown` is a timeout, a dropped connection, a 5xx or a body we cannot
 * read, after which money may or may not have moved.
 */
final class OperatorAnswer
{
    /** @param array<string, mixed> $body */
    private function __construct(
        public readonly string $kind,
        public readonly ?string $code,
        public readonly ?string $balance,
        public readonly ?string $transactionId,
        public readonly ?string $message,
        public readonly array $body,
    ) {}

    /** @param array<string, mixed> $body */
    public static function ok(array $body): self
    {
        return new self('ok', null, self::amount($body['balance'] ?? null), isset($body['transaction_id']) ? (string) $body['transaction_id'] : null, null, $body);
    }

    /** @param array<string, mixed> $body */
    public static function error(string $code, array $body): self
    {
        return new self('error', $code, self::amount($body['balance'] ?? null), null, is_string($body['message'] ?? null) ? mb_substr($body['message'], 0, 200) : null, $body);
    }

    public static function unknown(string $why): self
    {
        return new self('unknown', null, null, null, $why, []);
    }

    public function isOk(): bool
    {
        return $this->kind === 'ok';
    }

    public function isUnknown(): bool
    {
        return $this->kind === 'unknown';
    }

    /** A balance as a 2dp decimal string, or null when it is missing or not a plain decimal. */
    private static function amount(mixed $v): ?string
    {
        if (is_int($v)) {
            $v = (string) $v;
        }
        if (! is_string($v) || ! preg_match('/^-?\d{1,13}(\.\d{1,2})?$/', $v)) {
            return null;
        }

        return bcadd($v, '0', 2);
    }
}
