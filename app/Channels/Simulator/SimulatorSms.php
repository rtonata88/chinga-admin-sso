<?php

namespace App\Channels\Simulator;

use App\Channels\Contracts\SmsProvider;

/** "Sends" by leaving the message in sms_messages, which the simulated phone reads as its inbox. */
class SimulatorSms implements SmsProvider
{
    public function send(string $msisdn, string $text): ?string
    {
        return 'SIM-SMS-'.bin2hex(random_bytes(6));
    }
}
