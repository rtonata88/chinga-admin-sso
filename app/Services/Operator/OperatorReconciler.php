<?php

namespace App\Services\Operator;

use App\Models\OperatorIntegration;
use App\Models\OperatorMovement;
use App\Models\OperatorReconciliation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Matches one day of what we sent an operator against its statement, by reference
 * (spec, "Reconciliation and reporting"). What the spec says to do, and nothing more:
 * - a stake we hold as placed that the operator does not have: a rollback is sent
 *   (harmless if nothing landed; the cost is ours);
 * - a win or returned stake we hold as paid that the operator does not have: re-sent;
 * - a line the operator has that we never sent: raised, never corrected automatically;
 * - a line on both sides that differs: raised.
 * The day is the UTC date the movement was created.
 */
class OperatorReconciler
{
    /** Kept per list in the stored report; the counts are always complete. */
    private const DETAIL_LIMIT = 200;

    public function __construct(private readonly OperatorWalletClient $client) {}

    public function reconcile(OperatorIntegration $integration, string $date): OperatorReconciliation
    {
        $statement = $this->client->statement($integration, $date);
        if ($statement === null) {
            Log::error('operator.statement_unavailable', ['operator' => $integration->operator_key, 'date' => $date]);

            return $this->store($integration, $date, 'unavailable', [], [], [], [], 0);
        }

        $expected = $this->expected($integration, $date);
        $theirs = [];
        foreach ($statement['lines'] as $line) {
            $kind = (string) ($line['kind'] ?? '');
            $theirs["{$kind}:{$line['reference']}"] = $line;
        }

        $equal = $onlyOurs = $onlyTheirs = $different = [];
        foreach ($expected as $key => $ours) {
            $line = $theirs[$key] ?? null;
            if ($line === null) {
                $onlyOurs[] = $ours;
                $this->repair($integration, $ours);

                continue;
            }
            $sameAmount = is_string($line['amount'] ?? null) && preg_match('/^\d+(\.\d{1,2})?$/', $line['amount']) && bccomp($line['amount'], $ours['amount'], 2) === 0;
            $sameState = ($line['state'] ?? null) === $ours['state'];
            if ($sameAmount && $sameState) {
                $equal[] = $key;
            } else {
                $different[] = $ours + ['theirs' => ['amount' => $line['amount'] ?? null, 'state' => $line['state'] ?? null]];
            }
        }
        foreach (array_diff_key($theirs, $expected) as $key => $line) {
            $onlyTheirs[] = ['key' => $key, 'amount' => $line['amount'] ?? null, 'state' => $line['state'] ?? null, 'transaction_id' => $line['transaction_id'] ?? null];
        }

        $status = $onlyOurs === [] && $onlyTheirs === [] && $different === [] ? 'matched' : 'differences';
        if ($status === 'differences') {
            Log::warning('operator.reconciliation_differences', ['operator' => $integration->operator_key, 'date' => $date,
                'only_ours' => count($onlyOurs), 'only_theirs' => count($onlyTheirs), 'different' => count($different)]);
        }

        return $this->store($integration, $date, $status, $equal, $onlyOurs, $onlyTheirs, $different, $statement['unreadable']);
    }

    /**
     * What the operator's statement should hold for the day, keyed "<kind>:<reference>":
     * every stake we hold as placed (cancelled when we returned it) and every win we hold as paid.
     *
     * @return array<string, array{key: string, kind: string, reference: string, amount: string, state: string, movement_id: int}>
     */
    private function expected(OperatorIntegration $integration, string $date): array
    {
        $from = Carbon::parse($date, 'UTC')->startOfDay();
        $movements = OperatorMovement::where('operator_integration_id', $integration->id)
            ->whereBetween('created_at', [$from, $from->copy()->endOfDay()])
            ->where('status', 'ok')
            ->get();
        $returned = OperatorMovement::where('operator_integration_id', $integration->id)->where('kind', 'rollback')->where('status', 'ok')
            ->whereIn('reference', $movements->where('kind', 'debit')->pluck('reference'))->pluck('reference')->flip();

        $expected = [];
        foreach ($movements as $m) {
            if ($m->kind === 'rollback') {
                continue; // shown on the operator's side as its debit, cancelled
            }
            $state = $m->kind === 'debit' && isset($returned[$m->reference]) ? 'cancelled' : 'settled';
            $key = "{$m->kind}:{$m->reference}";
            $expected[$key] = ['key' => $key, 'kind' => $m->kind, 'reference' => $m->reference, 'amount' => bcadd((string) $m->amount, '0', 2), 'state' => $state, 'movement_id' => $m->id];
        }

        return $expected;
    }

    /** @param array{kind: string, reference: string, movement_id: int, state: string} $ours */
    private function repair(OperatorIntegration $integration, array $ours): void
    {
        $movement = OperatorMovement::find($ours['movement_id']);
        if ($movement === null) {
            return;
        }
        if ($ours['kind'] === 'credit') {
            $movement->update(['status' => 'retrying', 'error_code' => 'missing_at_operator', 'next_attempt_at' => now()]);

            return;
        }
        if ($ours['state'] === 'cancelled') {
            return; // we returned it and they never took it: both sides agree there is no stake
        }
        try {
            OperatorMovement::create([
                'operator_integration_id' => $integration->id, 'user_id' => $movement->user_id, 'game_session_id' => $movement->game_session_id,
                'kind' => 'rollback', 'reference' => $movement->reference, 'debit_reference' => $movement->reference,
                'amount' => $movement->amount, 'currency' => $movement->currency, 'status' => 'retrying', 'next_attempt_at' => now(),
                'error_code' => 'missing_at_operator',
            ]);
        } catch (UniqueConstraintViolationException) {
            // A rollback for it already exists.
        }
    }

    private function store(OperatorIntegration $integration, string $date, string $status, array $equal, array $onlyOurs, array $onlyTheirs, array $different, int $unreadable): OperatorReconciliation
    {
        return OperatorReconciliation::updateOrCreate(
            ['operator_integration_id' => $integration->id, 'date' => $date],
            [
                'status' => $status,
                'equal' => count($equal),
                'only_ours' => count($onlyOurs),
                'only_theirs' => count($onlyTheirs),
                'different' => count($different),
                'unreadable_lines' => $unreadable,
                'details' => [
                    'only_ours' => array_slice($onlyOurs, 0, self::DETAIL_LIMIT),
                    'only_theirs' => array_slice($onlyTheirs, 0, self::DETAIL_LIMIT),
                    'different' => array_slice($different, 0, self::DETAIL_LIMIT),
                ],
            ],
        );
    }
}
