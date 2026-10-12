<?php

namespace App\Http\Controllers\Api\Channels;

use App\Http\Controllers\Controller;
use App\Models\ChannelProvider;
use App\Models\MobileMoneyTransaction;
use App\Services\Channels\MobileMoneyService;
use App\Services\Channels\SmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Deposits, withdrawals and SMS for the USSD gateway (USSD design U-D7 to U-D9). */
class ChannelMoneyController extends Controller
{
    use ChannelAuth;

    public function deposit(Request $request, MobileMoneyService $money): JsonResponse
    {
        $tenant = $this->tenantFor($request);
        $user = $this->playerFor($request, $tenant);
        try {
            $txn = $money->deposit($user, (string) $request->input('amount'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['code' => 'amount_invalid', 'message' => $e->getMessage()], 422);
        }

        return response()->json(['reference' => $txn->reference, 'status' => $txn->status, 'amount' => (string) $txn->amount]);
    }

    public function withdraw(Request $request, MobileMoneyService $money): JsonResponse
    {
        $tenant = $this->tenantFor($request);
        $user = $this->playerFor($request, $tenant);
        try {
            ['transaction' => $txn, 'request' => $wr] = $money->withdraw($user, (string) $request->input('amount'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['code' => 'amount_invalid', 'message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            return response()->json(['code' => 'refused', 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'status' => $txn === null ? 'under_review' : $txn->status,
            'reference' => $txn?->reference,
            'withdrawal_status' => $wr->status,
            'balance' => (string) $user->getOrCreateWallet()->fresh()->balance,
        ]);
    }

    /** An SMS from a channel string (e.g. a not-admitted screen kept for the player), by number or player. */
    public function sms(Request $request, SmsService $sms): JsonResponse
    {
        $tenant = $this->tenantFor($request);
        $user = $request->filled('user_uuid') ? $this->playerFor($request, $tenant) : null;
        $msisdn = $user?->phone ?? (string) $request->input('msisdn');
        abort_if($msisdn === '', 422, 'No number.');
        $key = (string) $request->input('key');
        abort_unless(preg_match('/^[a-z]+\.[a-z_0-9]+$/', $key) === 1, 422, 'Not a message key.');
        $params = $request->input('params', []);
        $sent = $sms->sendKey($tenant, $user, $msisdn, $key, is_array($params) ? $params : []);

        return response()->json(['status' => $sent->status]);
    }

    /**
     * A provider's callback (no client auth: the provider's own signature is checked by its
     * driver). Unknown references and bad signatures are recorded in the log and ignored.
     */
    public function callback(Request $request, string $providerUuid, MobileMoneyService $money): JsonResponse
    {
        $provider = ChannelProvider::where('uuid', $providerUuid)->where('kind', 'mobile_money')->first();
        abort_if($provider === null, 404);
        $verified = app(\App\Channels\ChannelRegistry::class)->money($provider)->verifyCallback($request);
        if ($verified === null) {
            \Log::warning('channel.callback_unverified', ['provider' => $providerUuid]);

            return response()->json(['message' => 'Signature not valid.'], 401);
        }
        $txn = MobileMoneyTransaction::where('reference', $verified->reference)->where('channel_provider_id', $provider->id)->first();
        if ($txn === null) {
            \Log::warning('channel.callback_unknown_reference', ['provider' => $providerUuid, 'reference' => $verified->reference]);

            return response()->json(['message' => 'Unknown reference.'], 200);
        }
        $money->apply($txn, $verified->outcome, $verified->raw);

        return response()->json(['message' => 'ok']);
    }
}
