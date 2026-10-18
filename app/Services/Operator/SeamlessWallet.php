<?php

namespace App\Services\Operator;

use App\Models\Game;
use App\Models\GameSession;
use App\Models\OperatorIntegration;
use App\Models\OperatorMovement;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Seamless wallet mode (docs/platform/seamless-wallet-spec.md): the operator keeps the
 * money and every stake, win and returned stake is a signed call to its wallet API.
 * The engines never know: they call the SSO's game wallet API exactly as for a hosted
 * wallet, and GameSessionService hands seamless sessions to this class.
 *
 * Rules:
 * - Every movement is written (operator_movements) BEFORE it is sent and settled after.
 *   Our reference is the operator's idempotency key; a repeat never moves money twice.
 * - The engine always gets a definite answer. A stake with no clear answer is looked up,
 *   retried once with the same reference, and otherwise refused and rolled back (harmless
 *   if nothing landed). A win or a returned stake is the player's money: once the engine
 *   is told it is paid, it is re-sent until the operator confirms (24 h, then raised).
 * - The player's wallet here is a mirror (wallets.mode = seamless): its balance is the
 *   last one the operator reported, and its transactions record what we sent, for the
 *   admin pages, reports and invoicing. It is never money we hold.
 * - A refund is a rollback, never a credit. References follow the engines' contract
 *   (CLAUDE.md): <game>_bet_<id>, <game>_win_<id>, <game>_bonus_<id>, <game>_refund_<id>;
 *   a refund rolls back <game>_bet_<id>, and a win names its debit the same way.
 */
class SeamlessWallet
{
    /** Retries for a win or returned stake stop after this long; then it is raised for the teams. */
    public const CREDIT_RETRY_HOURS = 24;

    public function __construct(private readonly OperatorWalletClient $client) {}

    /** The operator integration when this session draws on an operator's wallet; null when the wallet is ours. */
    public function integrationFor(GameSession $session): ?OperatorIntegration
    {
        if ($session->source_type !== Wallet::class) {
            return null;
        }
        $wallet = $session->source;
        if (! $wallet instanceof Wallet || $wallet->mode !== OperatorIntegration::MODE_SEAMLESS) {
            return null;
        }
        $integration = OperatorIntegration::with('tenant')->where('tenant_id', $session->tenant_id)->first();
        if ($integration === null || ! $integration->isSeamless() || ! $integration->wallet_base_url) {
            // Never fall back to a balance we do not hold.
            throw new \RuntimeException('The operator wallet is not configured.');
        }

        return $integration;
    }

    /** Opens the session at the operator (once per launch) and takes the opening balance. */
    public function openSession(OperatorIntegration $integration, User $user, Game $game, GameSession $session): void
    {
        $answer = $this->client->call($integration, 'session', $this->common($user, $session) + ['game_id' => $game->slug, 'game_name' => $game->name]);
        if (! $answer->isOk()) {
            throw new \RuntimeException($this->refusal($answer, 'The game could not open: '));
        }
        $this->mirrorBalance($session, $answer->balance);
    }

    public function balance(OperatorIntegration $integration, GameSession $session): string
    {
        $answer = $this->client->call($integration, 'balance', $this->common($this->owner($session), $session));
        if ($answer->isOk()) {
            $this->mirrorBalance($session, $answer->balance);
        }

        return (string) $session->source->fresh()->balance;
    }

    public function debit(OperatorIntegration $integration, GameSession $session, string $amount, ?string $reference): WalletTransaction
    {
        if ($reference === null || $reference === '') {
            throw new \RuntimeException('A stake on an operator wallet needs a reference.');
        }
        [$movement, $created] = $this->movement($integration, $session, 'debit', $reference, $amount);
        if (! $created) {
            return $this->repeatOfDebit($movement);
        }

        $user = $this->owner($session);
        $fields = $this->common($user, $session) + ['reference' => $reference, 'amount' => $amount, 'game_id' => $session->game?->slug];
        $retried = false;
        $reopened = false;
        while (true) {
            $answer = $this->client->call($integration, 'debit', $fields);
            if ($answer->isUnknown()) {
                $found = $this->lookup($integration, $user, $session, $reference, 'debit');
                if ($found === 'settled') {
                    $answer = null; // it landed; take the balance from the next balance call

                    break;
                }
                if ($found === 'absent' && ! $retried) {
                    $retried = true;

                    continue;
                }
                // Twice unclear, or the lookup itself unclear: refuse the stake and send a rollback,
                // harmless if nothing landed (spec, "Idempotency, timeouts and failure handling").
                $movement->update(['status' => 'failed', 'error_code' => 'unavailable', 'attempts' => $movement->attempts + 1]);
                $this->scheduleRollback($integration, $session, $movement, mirror: false);
                throw new \RuntimeException('The operator wallet is not answering; the stake was not placed.');
            }
            if (! $answer->isOk() && $answer->code === 'session_invalid' && ! $reopened) {
                // The operator ended the session: open a fresh one once, then try again.
                $reopened = true;
                $this->openSession($integration, $user, $session->game, $session);

                continue;
            }
            break;
        }

        if ($answer !== null && ! $answer->isOk()) {
            $movement->update(['status' => 'failed', 'error_code' => $answer->code, 'attempts' => $movement->attempts + 1]);
            if ($answer->balance !== null) {
                $this->mirrorBalance($session, $answer->balance);
            }
            throw new \RuntimeException($answer->code === 'insufficient_funds' ? 'Insufficient balance for bet.' : $this->refusal($answer, 'The operator refused the stake: '));
        }

        return $this->settle($movement, $session, 'bet', $amount, $reference, 'Game bet', $answer?->balance, $answer?->transactionId);
    }

    /** A win, a bonus share, or (by its reference) a refund, which is a rollback. Accepted after the session ended. */
    public function credit(OperatorIntegration $integration, GameSession $session, string $amount, ?string $reference): WalletTransaction
    {
        if ($reference === null || $reference === '') {
            throw new \RuntimeException('A credit to an operator wallet needs a reference.');
        }
        $parts = self::parseReference($reference);
        if ($parts !== null && $parts['kind'] === 'refund') {
            return $this->rollback($integration, $session, $amount, $parts['debit'], $reference);
        }

        [$movement, $created] = $this->movement($integration, $session, 'credit', $reference, $amount, $parts['debit'] ?? null);
        if (! $created) {
            return $this->repeatOfCredit($movement);
        }
        $answer = $this->client->call($integration, 'credit', $this->creditFields($movement, $session));

        return $this->settleCredit($movement, $session, $answer, 'win', 'Game winnings');
    }

    /** Returns a stake the game refused or cancelled. The operator marks the debit cancelled. */
    private function rollback(OperatorIntegration $integration, GameSession $session, string $amount, string $debitReference, string $refundReference): WalletTransaction
    {
        $debit = OperatorMovement::where('operator_integration_id', $integration->id)->where('kind', 'debit')->where('reference', $debitReference)->first();
        if ($debit === null || $debit->status !== 'ok') {
            throw new \RuntimeException('No placed stake to return for '.$debitReference.'.');
        }
        if (bccomp((string) $debit->amount, $amount, 2) !== 0) {
            throw new \RuntimeException('A returned stake must be the whole stake.');
        }
        [$movement, $created] = $this->movement($integration, $session, 'rollback', $debitReference, $amount, $debitReference);
        if (! $created) {
            return $this->repeatOfCredit($movement);
        }
        $answer = $this->client->call($integration, 'rollback', $this->common($this->owner($session), $session) + ['reference' => $debitReference]);
        if (! $answer->isOk() && $answer->code === 'already_settled') {
            // The operator paid a win on this stake; returning it as well would pay twice.
            $movement->update(['status' => 'failed', 'error_code' => 'already_settled', 'attempts' => 1]);
            Log::critical('operator.rollback_after_settle', ['operator' => $integration->operator_key, 'reference' => $debitReference]);
            throw new \RuntimeException('That stake is already settled at the operator.');
        }

        return $this->settleCredit($movement, $session, $answer, 'win', 'Stake returned', $refundReference);
    }

    /**
     * Re-sends wins and returned stakes that have no answer yet, and rollbacks of stakes
     * that were refused for want of one. Run every minute by operators:retry.
     *
     * @return array{settled: int, retrying: int, abandoned: int}
     */
    public function retryDue(int $limit = 200): array
    {
        $counts = ['settled' => 0, 'retrying' => 0, 'abandoned' => 0];
        $due = OperatorMovement::where('status', 'retrying')->where('next_attempt_at', '<=', now())->orderBy('next_attempt_at')->limit($limit)->get();
        foreach ($due as $movement) {
            $integration = OperatorIntegration::with('tenant')->find($movement->operator_integration_id);
            $session = $movement->game_session_id ? GameSession::find($movement->game_session_id) : null;
            if ($integration === null || $session === null) {
                continue;
            }
            $user = $this->owner($session);
            $found = $this->lookup($integration, $user, $session, $movement->reference, $movement->kind === 'credit' ? 'credit' : 'debit');
            if ($movement->kind === 'rollback' && $found === 'cancelled') {
                $answer = OperatorAnswer::ok([]);
            } elseif ($movement->kind === 'credit' && $found === 'settled') {
                $answer = OperatorAnswer::ok([]);
            } elseif ($movement->kind === 'rollback') {
                $answer = $this->client->call($integration, 'rollback', $this->common($user, $session) + ['reference' => $movement->reference]);
            } else {
                $answer = $this->client->call($integration, 'credit', $this->creditFields($movement, $session));
            }

            if ($answer->isOk() || ($movement->kind === 'rollback' && $answer->code === 'already_settled')) {
                $movement->update(['status' => $answer->isOk() ? 'ok' : 'failed', 'error_code' => $answer->code, 'operator_transaction_id' => $answer->transactionId ?? $movement->operator_transaction_id, 'attempts' => $movement->attempts + 1, 'next_attempt_at' => null]);
                $this->mirrorBalance($session, $answer->balance);
                $counts['settled']++;

                continue;
            }
            if ($movement->created_at->lt(now()->subHours(self::CREDIT_RETRY_HOURS))) {
                $movement->update(['status' => 'abandoned', 'error_code' => $answer->code ?? 'unavailable', 'next_attempt_at' => null]);
                Log::critical('operator.movement_abandoned', ['operator' => $integration->operator_key, 'kind' => $movement->kind, 'reference' => $movement->reference, 'amount' => (string) $movement->amount]);
                $counts['abandoned']++;

                continue;
            }
            $this->backOff($movement, $answer->code);
            $counts['retrying']++;
        }

        return $counts;
    }

    /**
     * Splits an engine reference: <game>_<kind>_<id>. Returns the debit it belongs to
     * (<game>_bet_<id>) for wins, bonus shares and refunds; null for anything else.
     *
     * @return array{kind: string, debit: string}|null
     */
    public static function parseReference(string $reference): ?array
    {
        if (! preg_match('/^([a-z]+)_(win|bonus|refund)_([A-Za-z0-9-]+)$/', $reference, $m)) {
            return null;
        }

        return ['kind' => $m[2], 'debit' => "{$m[1]}_bet_{$m[3]}"];
    }

    // ---- internals ------------------------------------------------------------------

    /** @return array{0: OperatorMovement, 1: bool} the movement, and whether this call created it */
    private function movement(OperatorIntegration $integration, GameSession $session, string $kind, string $reference, string $amount, ?string $debitReference = null): array
    {
        $existing = OperatorMovement::where('operator_integration_id', $integration->id)->where('kind', $kind)->where('reference', $reference)->first();
        if ($existing !== null) {
            $this->sameMovement($existing, $amount, $session);

            return [$existing, false];
        }
        try {
            return [OperatorMovement::create([
                'operator_integration_id' => $integration->id,
                'user_id' => $this->owner($session)->id,
                'game_session_id' => $session->id,
                'kind' => $kind,
                'reference' => $reference,
                'debit_reference' => $debitReference,
                'amount' => $amount,
                'currency' => $session->source->currency,
                'status' => 'pending',
            ]), true];
        } catch (UniqueConstraintViolationException) {
            $winner = OperatorMovement::where('operator_integration_id', $integration->id)->where('kind', $kind)->where('reference', $reference)->firstOrFail();
            $this->sameMovement($winner, $amount, $session);

            return [$winner, false];
        }
    }

    private function sameMovement(OperatorMovement $movement, string $amount, GameSession $session): void
    {
        if (bccomp((string) $movement->amount, $amount, 2) !== 0 || $movement->user_id !== $this->owner($session)->id) {
            throw new \RuntimeException("Reference {$movement->reference} was already used for a different amount or player.");
        }
    }

    private function repeatOfDebit(OperatorMovement $movement): WalletTransaction
    {
        return match ($movement->status) {
            'ok' => WalletTransaction::findOrFail($movement->wallet_transaction_id),
            'failed' => throw new \RuntimeException($movement->error_code === 'insufficient_funds' ? 'Insufficient balance for bet.' : 'The stake was not placed ('.$movement->error_code.').'),
            default => throw new \RuntimeException('That stake is still being placed.'),
        };
    }

    private function repeatOfCredit(OperatorMovement $movement): WalletTransaction
    {
        if ($movement->wallet_transaction_id !== null) {
            return WalletTransaction::findOrFail($movement->wallet_transaction_id);
        }
        throw new \RuntimeException($movement->status === 'failed' ? 'That credit was refused ('.$movement->error_code.').' : 'That credit is still being sent.');
    }

    /**
     * A win or returned stake: recorded as paid for the engine whatever the operator said,
     * because it is the player's money. Without a clear "ok" it is re-sent until it is.
     */
    private function settleCredit(OperatorMovement $movement, GameSession $session, OperatorAnswer $answer, string $type, string $description, ?string $mirrorReference = null): WalletTransaction
    {
        $tx = $this->settle($movement, $session, $type, (string) $movement->amount, $mirrorReference ?? $movement->reference, $description, $answer->isOk() ? $answer->balance : null, $answer->transactionId, credit: true);
        if (! $answer->isOk()) {
            if (! $answer->isUnknown()) {
                Log::error('operator.credit_refused', ['reference' => $movement->reference, 'code' => $answer->code]);
            }
            $this->backOff($movement->fresh(), $answer->code);
        }

        return $tx;
    }

    /** Writes the mirror transaction and marks the movement sent. Balance: the operator's, else the mirror moved by the amount. */
    private function settle(OperatorMovement $movement, GameSession $session, string $type, string $amount, string $reference, string $description, ?string $operatorBalance, ?string $operatorTx, bool $credit = false): WalletTransaction
    {
        return DB::transaction(function () use ($movement, $session, $type, $amount, $reference, $description, $operatorBalance, $operatorTx, $credit) {
            $wallet = Wallet::lockForUpdate()->findOrFail($session->source_id);
            $before = (string) $wallet->balance;
            $after = $operatorBalance ?? ($credit ? bcadd($before, $amount, 2) : bcsub($before, $amount, 2));
            $wallet->update([
                'balance' => $after,
                'deposit_balance' => $after,
                'winnings_balance' => '0.00',
                $credit ? 'total_won' : 'total_lost' => bcadd((string) ($credit ? $wallet->total_won : $wallet->total_lost), $amount, 2),
            ]);
            $tx = WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'game_session_id' => $session->id,
                'type' => $type,
                'amount' => $amount,
                'amount_from_deposit' => $credit ? '0' : $amount,
                'amount_from_winnings' => '0',
                'balance_before' => $before,
                'balance_after' => $after,
                'reference' => $reference,
                'description' => $description.' (operator wallet)',
            ]);
            $movement->update([
                'status' => 'ok',
                'operator_transaction_id' => $operatorTx,
                'balance_after' => $operatorBalance,
                'attempts' => $movement->attempts + 1,
                'wallet_transaction_id' => $tx->id,
            ]);

            return $tx;
        });
    }

    private function scheduleRollback(OperatorIntegration $integration, GameSession $session, OperatorMovement $debit, bool $mirror): void
    {
        try {
            OperatorMovement::create([
                'operator_integration_id' => $integration->id,
                'user_id' => $debit->user_id,
                'game_session_id' => $session->id,
                'kind' => 'rollback',
                'reference' => $debit->reference,
                'debit_reference' => $debit->reference,
                'amount' => $debit->amount,
                'currency' => $debit->currency,
                'status' => 'retrying',
                'next_attempt_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Already scheduled.
        }
    }

    private function backOff(OperatorMovement $movement, ?string $code): void
    {
        $attempts = $movement->attempts + 1;
        $movement->update([
            'status' => 'retrying',
            'error_code' => $code ?? 'unavailable',
            'attempts' => $attempts,
            'next_attempt_at' => now()->addSeconds(min(3600, 30 * (2 ** min(7, $attempts - 1)))),
        ]);
    }

    /** @return 'settled'|'cancelled'|'absent'|'unknown' */
    private function lookup(OperatorIntegration $integration, User $user, GameSession $session, string $reference, string $kind): string
    {
        $answer = $this->client->call($integration, 'lookup', $this->common($user, $session) + ['reference' => $reference, 'kind' => $kind]);
        if (! $answer->isOk()) {
            return 'unknown';
        }
        if (($answer->body['found'] ?? null) === false) {
            return 'absent';
        }

        return ($answer->body['state'] ?? null) === 'cancelled' ? 'cancelled' : 'settled';
    }

    /** @return array<string, mixed> */
    private function creditFields(OperatorMovement $movement, GameSession $session): array
    {
        return $this->common($this->owner($session), $session) + array_filter([
            'reference' => $movement->reference,
            'debit_reference' => $movement->debit_reference,
            'amount' => bcadd((string) $movement->amount, '0', 2),
            'game_id' => $session->game?->slug,
        ], fn ($v) => $v !== null);
    }

    /** @return array{player_id: string, session_id: string} */
    private function common(User $user, GameSession $session): array
    {
        // The session's uuid, never its token: the token is a bearer secret.
        return ['player_id' => (string) $user->external_player_id, 'session_id' => $session->uuid];
    }

    private function owner(GameSession $session): User
    {
        return User::withoutGlobalScopes()->findOrFail($session->source->user_id);
    }

    private function mirrorBalance(GameSession $session, ?string $balance): void
    {
        if ($balance === null) {
            return;
        }
        Wallet::where('id', $session->source_id)->update(['balance' => $balance, 'deposit_balance' => $balance, 'winnings_balance' => '0.00']);
    }

    private function refusal(OperatorAnswer $answer, string $prefix): string
    {
        return match ($answer->code) {
            'player_blocked' => 'This player cannot play. Please contact the operator.',
            'player_unknown' => 'The operator does not know this player. Open the game again from the operator\'s site.',
            'limit_exceeded' => $answer->message ?? 'An operator limit was reached.',
            'currency_mismatch' => 'The operator wallet is in another currency.',
            null => $prefix.'the operator wallet is not answering.',
            default => $prefix.$answer->code.'.',
        };
    }
}
