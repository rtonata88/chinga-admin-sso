<?php

namespace App\Services\Channels;

use App\Channels\ChannelRegistry;
use App\Channels\Messages;
use App\Channels\Msisdn;
use App\Models\SmsMessage;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/** Every SMS to a player goes through here, in their language, logged in sms_messages (USSD design §5). */
class SmsService
{
    public function __construct(private readonly ChannelRegistry $registry) {}

    public function sendKey(Tenant $tenant, ?User $user, string $msisdn, string $key, array $params): SmsMessage
    {
        $language = $user?->language ?? Messages::languages($tenant)[0];
        $text = Messages::render(Messages::forTenant($tenant, $language)[$key] ?? $key, $params);

        return $this->send($tenant, $user, $msisdn, $text, $key);
    }

    public function send(Tenant $tenant, ?User $user, string $msisdn, string $text, string $purpose): SmsMessage
    {
        $provider = $this->registry->provider($tenant, 'sms');
        $sms = SmsMessage::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user?->id,
            'channel_provider_id' => $provider?->id,
            'msisdn' => $msisdn,
            'text' => $text,
            'purpose' => $purpose,
            'status' => 'queued',
        ]);
        if ($provider === null) {
            $sms->update(['status' => 'failed', 'last_error' => 'No SMS provider for this tenant']);

            return $sms;
        }
        try {
            $ref = $this->registry->sms($provider)->send($msisdn, $text);
            $sms->update(['status' => 'sent', 'provider_reference' => $ref, 'attempts' => 1, 'sent_at' => now()]);
        } catch (\Throwable $e) {
            Log::warning('channel.sms_failed', ['msisdn' => Msisdn::mask($msisdn), 'purpose' => $purpose, 'error' => $e->getMessage()]);
            $sms->update(['status' => 'failed', 'attempts' => 1, 'last_error' => mb_substr($e->getMessage(), 0, 250)]);
        }

        return $sms;
    }
}
