<?php

namespace App\Http\Controllers\Api\Operator;

use App\Models\OperatorTransfer;
use App\Services\WalletService;
use App\Support\Currencies;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * POST /api/v1/operator/transfers (hosted wallet mode only): the operator moves its
 * player's money into our wallet (in) or back out (out). The operator's reference is
 * the idempotency key: the same reference again answers with the original transfer
 * and moves nothing; the same reference with different details is refused.
 */
class TransferController extends OperatorController
{
    public function __invoke(Request $request, WalletService $wallets): JsonResponse
    {
        $integration = $this->integration($request);
        if ($integration->isSeamless()) {
            return $this->error('not_supported', 'Transfers are not used with a seamless wallet.', 409);
        }
        $v = Validator::make($request->all(), [
            'player_id' => self::PLAYER_ID,
            'direction' => ['required', 'in:in,out'],
            'amount' => self::AMOUNT,
            'currency' => ['required', 'string', 'size:3'],
            'reference' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ]);
        if ($v->fails()) {
            return $this->error('invalid_request', $v->errors()->first(), 400);
        }
        $data = $v->validated();
        if (bccomp($data['amount'], '0', 2) <= 0) {
            return $this->error('invalid_request', 'The amount must be more than zero.', 400);
        }
        $profile = Currencies::forTenant($integration->tenant);
        if (strtoupper($data['currency']) !== $profile['code']) {
            return $this->error('currency_mismatch', "This operator plays in {$profile['code']}.", 400);
        }
        if ($profile['decimals'] === 0 && ! preg_match('/^\d+(\.0{1,2})?$/', $data['amount'])) {
            return $this->error('invalid_request', "{$profile['code']} has no cents.", 400);
        }
        $user = $this->player($request, $data['player_id']);
        if ($user === null) {
            return $this->error('player_unknown', 'No player with that id has launched a game yet.', 404);
        }
        $wallet = $user->getOrCreateWallet();

        $existing = OperatorTransfer::where('operator_integration_id', $integration->id)->where('reference', $data['reference'])->first();
        if ($existing !== null) {
            return $this->repeat($existing, $data, $user->id, $wallet->fresh()->balance);
        }

        try {
            $transfer = DB::transaction(function () use ($integration, $user, $wallet, $data, $profile, $wallets) {
                $transfer = OperatorTransfer::create([
                    'operator_integration_id' => $integration->id,
                    'user_id' => $user->id,
                    'reference' => $data['reference'],
                    'direction' => $data['direction'],
                    'amount' => $data['amount'],
                    'currency' => $profile['code'],
                ]);
                $tx = $data['direction'] === 'in'
                    ? $wallets->deposit($wallet, $data['amount'], null, 'op_in_'.$data['reference'])
                    : $wallets->transferOut($wallet, $data['amount'], 'op_out_'.$data['reference']);
                $transfer->update(['wallet_transaction_id' => $tx->id]);

                return $transfer;
            });
        } catch (UniqueConstraintViolationException) {
            // The same reference raced in twice; the first one moved the money.
            $first = OperatorTransfer::where('operator_integration_id', $integration->id)->where('reference', $data['reference'])->firstOrFail();

            return $this->repeat($first, $data, $user->id, $wallet->fresh()->balance);
        } catch (\RuntimeException $e) {
            if (str_starts_with($e->getMessage(), 'Insufficient')) {
                return response()->json(['status' => 'error', 'code' => 'insufficient_funds', 'message' => 'The player does not have that much.', 'balance' => (string) $wallet->fresh()->balance], 402);
            }
            if (str_contains(strtolower($e->getMessage()), 'wallet')) {
                return $this->error('player_blocked', 'This player\'s wallet is not active.', 403);
            }
            throw $e;
        }

        return $this->ok($transfer, $wallet->fresh()->balance);
    }

    private function repeat(OperatorTransfer $transfer, array $data, int $userId, string $balance): JsonResponse
    {
        $same = $transfer->user_id === $userId && $transfer->direction === $data['direction']
            && bccomp((string) $transfer->amount, $data['amount'], 2) === 0;
        if (! $same) {
            return $this->error('reference_conflict', 'That reference was already used for a different transfer.', 409);
        }

        return $this->ok($transfer, $balance);
    }

    private function ok(OperatorTransfer $transfer, string $balance): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'transaction_id' => 'opt_'.$transfer->id,
            'reference' => $transfer->reference,
            'direction' => $transfer->direction,
            'amount' => bcadd((string) $transfer->amount, '0', 2),
            'balance' => $balance,
        ]);
    }
}
