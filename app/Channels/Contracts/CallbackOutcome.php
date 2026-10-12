<?php

namespace App\Channels\Contracts;

final class CallbackOutcome
{
    public function __construct(
        public readonly string $reference,
        public readonly ProviderOutcome $outcome,
        public readonly array $raw,
    ) {}
}
