<?php

namespace App\Channels\Simulator;

use App\Channels\Contracts\SmsProvider;

/** "Sends" by leaving the message in sms_messages, which the simulated phone reads as its inbox. */
class SimulatorSms implements SmsProvider
{
    public function send(string $msisdn, string $text): ?string
    {
        // Load tests: a slow SMS gateway (CHANNEL_SIM_SMS_DELAY_MS, development only).
        $delay = (int) config('channels.simulator_sms_delay_ms', 0);
        if ($delay > 0) {
            usleep(min($delay, 10_000) * 1000);
        }

        return 'SIM-SMS-'.bin2hex(random_bytes(6));
    }
}
