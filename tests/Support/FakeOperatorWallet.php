<?php

namespace Tests\Support;

use App\Support\OperatorSignature;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * An operator's wallet as the seamless spec describes it, behind Http::fake: it checks
 * our signature, keeps balances, and is idempotent by reference exactly as an operator
 * must be. `failNext()` makes the next calls to an endpoint time out or answer 5xx ('pass' queues a normal answer); with
 * `land: true` the debit still lands first, which is the case a lookup exists for.
 */
class FakeOperatorWallet
{
    /** @var array<string, string> */
    public array $balances = [];

    /** @var array<string, array{kind: string, amount: string, player: string, state: string, id: string}> */
    public array $ledger = [];

    /** @var list<array{endpoint: string, body: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, list<array{mode: string, land: bool}>> */
    private array $failures = [];

    /** @var array<string, string> players blocked, by id */
    public array $blocked = [];

    public bool $sessionInvalidOnce = false;

    private int $nextId = 1;

    public function __construct(public string $baseUrl, public string $operatorKey, public string $secret) {}

    public function install(): self
    {
        Http::fake([rtrim($this->baseUrl, '/').'/wallet/*' => fn (Request $r) => $this->handle($r)]);

        return $this;
    }

    public function failNext(string $endpoint, string $mode = 'timeout', bool $land = false, int $times = 1): self
    {
        for ($i = 0; $i < $times; $i++) {
            $this->failures[$endpoint][] = ['mode' => $mode, 'land' => $land];
        }

        return $this;
    }

    /** @return list<string> */
    public function endpointsCalled(): array
    {
        return array_map(fn ($c) => $c['endpoint'], $this->calls);
    }

    private function handle(Request $request): mixed
    {
        $endpoint = basename(parse_url($request->url(), PHP_URL_PATH));
        $raw = $request->body();
        $body = json_decode($raw, true) ?? [];
        $this->calls[] = ['endpoint' => $endpoint, 'body' => $body];

        $ok = OperatorSignature::verify([$this->secret], $request->header('X-Chinga-Timestamp')[0] ?? null, $request->header('X-Chinga-Signature')[0] ?? null, $raw)
            && ($request->header('X-Chinga-Operator')[0] ?? null) === $this->operatorKey;
        if (! $ok) {
            return Http::response(['status' => 'error', 'code' => 'unauthorized', 'message' => 'bad signature'], 401);
        }

        if ($endpoint === 'statement') {
            return $this->statement();
        }

        $failure = empty($this->failures[$endpoint]) ? null : array_shift($this->failures[$endpoint]);
        if ($failure !== null && $failure['mode'] === 'pass') {
            $failure = null; // a queued normal answer, so a later failure lands on the right call
        }
        if ($failure !== null && $failure['land']) {
            $this->apply($endpoint, $body);
        }
        if ($failure !== null) {
            if ($failure['mode'] === 'timeout') {
                throw new ConnectException('cURL error 28: Operation timed out', new PsrRequest('POST', $request->url()));
            }

            return Http::response('upstream down', 503);
        }

        return $this->apply($endpoint, $body);
    }

    private function apply(string $endpoint, array $body): mixed
    {
        $player = (string) ($body['player_id'] ?? '');
        $this->balances[$player] ??= '0.00';
        if (isset($this->blocked[$player]) && in_array($endpoint, ['session', 'debit'], true)) {
            return Http::response(['status' => 'error', 'code' => 'player_blocked', 'message' => 'self-excluded'], 403);
        }
        $ref = (string) ($body['reference'] ?? '');
        $amount = (string) ($body['amount'] ?? '0');

        switch ($endpoint) {
            case 'session':
            case 'balance':
                return $this->ok($player);
            case 'debit':
                if ($this->sessionInvalidOnce) {
                    $this->sessionInvalidOnce = false;

                    return Http::response(['status' => 'error', 'code' => 'session_invalid', 'message' => 'ended'], 401);
                }
                if (isset($this->ledger["debit:{$ref}"])) {
                    return $this->ok($player, $this->ledger["debit:{$ref}"]['id']);
                }
                if (bccomp($this->balances[$player], $amount, 2) < 0) {
                    return Http::response(['status' => 'error', 'code' => 'insufficient_funds', 'message' => 'short', 'balance' => $this->balances[$player]], 402);
                }
                $this->balances[$player] = bcsub($this->balances[$player], $amount, 2);
                $this->ledger["debit:{$ref}"] = ['kind' => 'debit', 'amount' => $amount, 'player' => $player, 'state' => 'settled', 'id' => 'op-'.$this->nextId++];

                return $this->ok($player, $this->ledger["debit:{$ref}"]['id']);
            case 'credit':
                if (! isset($this->ledger["credit:{$ref}"])) {
                    $this->balances[$player] = bcadd($this->balances[$player], $amount, 2);
                    $this->ledger["credit:{$ref}"] = ['kind' => 'credit', 'amount' => $amount, 'player' => $player, 'state' => 'settled', 'id' => 'op-'.$this->nextId++];
                    if (isset($body['debit_reference'], $this->ledger['debit:'.$body['debit_reference']])) {
                        $this->ledger['debit:'.$body['debit_reference']]['credited'] = true;
                    }
                }

                return $this->ok($player, $this->ledger["credit:{$ref}"]['id']);
            case 'rollback':
                $debit = $this->ledger["debit:{$ref}"] ?? null;
                if ($debit === null) {
                    return $this->ok($player); // unknown reference: ok, nothing recorded
                }
                if (! empty($debit['credited'])) {
                    return Http::response(['status' => 'error', 'code' => 'already_settled', 'message' => 'paid'], 409);
                }
                if ($debit['state'] !== 'cancelled') {
                    $this->balances[$player] = bcadd($this->balances[$player], $debit['amount'], 2);
                    $this->ledger["debit:{$ref}"]['state'] = 'cancelled';
                }

                return $this->ok($player, $debit['id']);
            case 'lookup':
                $kind = (string) ($body['kind'] ?? 'debit');
                $entry = $this->ledger["{$kind}:{$ref}"] ?? null;

                return Http::response($entry === null
                    ? ['status' => 'ok', 'found' => false]
                    : ['status' => 'ok', 'found' => true, 'kind' => $kind, 'amount' => $entry['amount'], 'state' => $entry['state'], 'transaction_id' => $entry['id']]);
        }

        return Http::response(['status' => 'error', 'code' => 'invalid_request', 'message' => 'unknown endpoint'], 400);
    }

    /** @var list<array<string, mixed>> extra statement lines, for lines we never sent */
    public array $extraStatementLines = [];

    /** @var list<string> ledger keys ("debit:ref") the statement leaves out, as if lost at the operator */
    public array $missingFromStatement = [];

    /** The day's ledger as JSON lines (the spec's GET /wallet/statement). */
    private function statement(): mixed
    {
        $lines = [];
        foreach ($this->ledger as $key => $e) {
            if (in_array($key, $this->missingFromStatement, true)) {
                continue;
            }
            $lines[] = json_encode(['reference' => substr($key, strpos($key, ':') + 1), 'kind' => $e['kind'], 'amount' => $e['amount'], 'state' => $e['state'], 'transaction_id' => $e['id'], 'settled_at' => now()->toIso8601String()]);
        }
        foreach ($this->extraStatementLines as $l) {
            $lines[] = json_encode($l);
        }

        return Http::response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'application/x-ndjson']);
    }

    private function ok(string $player, ?string $id = null): mixed
    {
        return Http::response(array_filter(['status' => 'ok', 'balance' => $this->balances[$player], 'transaction_id' => $id]));
    }
}
