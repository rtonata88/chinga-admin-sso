<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\VoucherCode;
use App\Services\GameSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoucherWebSessionController extends Controller
{
    public function __construct(
        protected GameSessionService $gameSessionService
    ) {}

    public function start(Request $request): JsonResponse
    {
        $request->validate([
            'game_id' => ['required', 'string'],
            'code' => ['required', 'string'],
            'pin' => ['nullable', 'string'],
        ]);

        $tenantId = app('current_tenant')?->id;
        if (!$tenantId) {
            return response()->json(['message' => 'Tenant context required.'], 400);
        }

        $game = Game::where('uuid', $request->input('game_id'))->first();
        if (!$game) {
            return response()->json(['message' => 'Game not found.'], 404);
        }

        $code = VoucherCode::where('code', strtoupper($request->input('code')))
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$code) {
            return response()->json(['message' => 'Invalid voucher code.'], 404);
        }

        try {
            $session = $this->gameSessionService->startWebVoucherSession(
                $code,
                $game,
                $request->input('pin'),
                $request->ip()
            );

            return response()->json([
                'session_token' => $session->session_token,
                'balance' => $session->balance_start,
                'currency' => $code->currency,
                'game' => [
                    'uuid' => $game->uuid,
                    'name' => $game->name,
                    'slug' => $game->slug,
                ],
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
