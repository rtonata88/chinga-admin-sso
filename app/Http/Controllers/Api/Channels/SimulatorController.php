<?php

namespace App\Http\Controllers\Api\Channels;

use App\Channels\Contracts\ProviderOutcome;
use App\Http\Controllers\Controller;
use App\Models\MobileMoneyTransaction;
use App\Models\SmsMessage;
use App\Services\Channels\MobileMoneyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The simulated phone's side of the simulator providers (CHANNEL_SIMULATOR only):
 * test subscribers, the mobile-money PIN prompt, the SMS inbox. Development and
 * demos only; a real provider never reaches these.
 */
class SimulatorController extends Controller
{
    use ChannelAuth;

    public function __construct()
    {
        abort_unless((bool) config('channels.simulator'), 404);
    }

    public function subscribers(Request $request): JsonResponse
    {
        $tenant = $this->tenantFor($request);

        return response()->json(['data' => DB::table('channel_sim_subscribers')->where('tenant_id', $tenant->id)->orderBy('id')
            ->get(['msisdn', 'label', 'name', 'date_of_birth', 'kyc', 'money_balance'])]);
    }

    /** The demo's controls: KYC mode (verified / unverified / unavailable), date of birth, mobile-money balance. */
    public function updateSubscriber(Request $request, string $msisdn): JsonResponse
    {
        $tenant = $this->tenantFor($request);
        $data = $request->validate([
            'kyc' => ['sometimes', 'in:verified,unverified,unavailable'],
            'date_of_birth' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'money_balance' => ['sometimes', 'numeric', 'min:0'],
        ]);
        DB::table('channel_sim_subscribers')->where('tenant_id', $tenant->id)->where('msisdn', $msisdn)->update($data + ['updated_at' => now()]);

        return $this->subscribers($request);
    }

    /** What the phone shows: a waiting PIN prompt, the SMS inbox, the mobile-money balance. */
    public function phone(Request $request): JsonResponse
    {
        $tenant = $this->tenantFor($request);
        $msisdn = (string) $request->query('msisdn');
        $sub = DB::table('channel_sim_subscribers')->where('tenant_id', $tenant->id)->where('msisdn', $msisdn)->first();
        $prompt = MobileMoneyTransaction::where('tenant_id', $tenant->id)->where('msisdn', $msisdn)
            ->where('direction', 'deposit')->where('status', 'pending')->orderBy('id')->first();

        return response()->json([
            'money_balance' => $sub?->money_balance,
            'prompt' => $prompt === null ? null : [
                'reference' => $prompt->reference,
                'amount' => (string) $prompt->amount,
                'currency' => $prompt->currency,
                'payee' => $tenant->name,
            ],
            'sms' => SmsMessage::where('tenant_id', $tenant->id)->where('msisdn', $msisdn)->orderByDesc('id')->limit(30)
                ->get(['id', 'text', 'created_at']),
        ]);
    }

    /** The subscriber answers the PIN prompt; the rail then settles the deposit as a real one would. */
    public function answerPrompt(Request $request, string $reference, MobileMoneyService $money): JsonResponse
    {
        $tenant = $this->tenantFor($request);
        $txn = MobileMoneyTransaction::where('tenant_id', $tenant->id)->where('reference', $reference)->where('status', 'pending')->firstOrFail();
        if (! $request->boolean('approve')) {
            $money->apply($txn, new ProviderOutcome('failed', $txn->provider_reference, 'Declined by the subscriber'));

            return response()->json(['status' => 'failed']);
        }
        $sub = DB::table('channel_sim_subscribers')->where('tenant_id', $tenant->id)->where('msisdn', $txn->msisdn)->first();
        if ($sub === null || (string) $request->input('pin') !== $sub->pin) {
            return response()->json(['status' => 'pending', 'message' => 'Wrong PIN.'], 422);
        }
        $taken = DB::table('channel_sim_subscribers')->where('id', $sub->id)->where('money_balance', '>=', $txn->amount)->decrement('money_balance', $txn->amount);
        $money->apply($txn, $taken === 1
            ? new ProviderOutcome('succeeded', $txn->provider_reference)
            : new ProviderOutcome('failed', $txn->provider_reference, 'Not enough money in the mobile-money account'));

        return response()->json(['status' => $txn->fresh()->status]);
    }
}
