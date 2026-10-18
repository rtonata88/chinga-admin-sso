<?php

namespace App\Http\Controllers\Api\Operator;

use App\Http\Controllers\Controller;
use App\Models\OperatorIntegration;
use App\Models\User;
use App\Services\Operator\OperatorPlayerResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared by the operator-facing endpoints. Answers use the operator contract's shape
 * (docs/platform/seamless-wallet-spec.md): {"status":"ok", ...} on success and
 * {"status":"error","code":"<code>","message":"<plain words>"} on failure.
 */
abstract class OperatorController extends Controller
{
    /** Printable, no spaces: the operator's own player id, 1-100 characters. */
    protected const PLAYER_ID = ['required', 'string', 'min:1', 'max:100', 'regex:/^[\x21-\x7E]+$/'];

    /** A positive amount with at most two decimals, as a string: never a float. */
    protected const AMOUNT = ['required', 'string', 'regex:/^(?:0|[1-9]\d{0,12})(?:\.\d{1,2})?$/'];

    protected function integration(Request $request): OperatorIntegration
    {
        return $request->attributes->get('operator_integration');
    }

    protected function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['status' => 'error', 'code' => $code, 'message' => $message], $status);
    }

    protected function player(Request $request, string $playerId): ?User
    {
        return app(OperatorPlayerResolver::class)->find($this->integration($request)->tenant, $playerId);
    }
}
