<?php

namespace App\Services\Concerns;

use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The race-loser path for idempotent game transactions (Kulipi Kuna hardening H1).
 *
 * The lookup under the row lock answers every ordinary repeat. If a request still reaches
 * the insert after another has recorded the same transaction, the unique index refuses it,
 * the surrounding DB::transaction rolls back (so its balance change never lands), and the
 * winner's row is the answer.
 */
trait ReturnsExistingOnDuplicate
{
    /**
     * @template T
     *
     * @param  callable(): T  $insert  the whole locked transaction: lock, look, update, record
     * @param  callable(): (T|null)  $find  the winner's row, read after the rollback
     * @return T
     */
    protected function insertOrExisting(callable $insert, callable $find): mixed
    {
        try {
            return $insert();
        } catch (UniqueConstraintViolationException $e) {
            // No winner to return means the violation was something else: never swallow it.
            return $find() ?? throw $e;
        }
    }
}
