<?php

namespace App\Channels\Contracts;

final class ProviderOutcome
{
    /** @param  'pending'|'succeeded'|'failed'|'unknown'  $status */
    public function __construct(
        public readonly string $status,
        public readonly ?string $providerReference = null,
        public readonly ?string $error = null,
    ) {}
}
