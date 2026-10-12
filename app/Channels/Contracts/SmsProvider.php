<?php

namespace App\Channels\Contracts;

interface SmsProvider
{
    /** @return string|null the provider's message id */
    public function send(string $msisdn, string $text): ?string;
}
