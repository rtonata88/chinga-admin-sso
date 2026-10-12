<?php

namespace App\Channels\Contracts;

final class KycResult
{
    /**
     * @param  'verified'|'unverified'|'not_found'  $status
     * @param  string|null  $dateOfBirth  Y-m-d, as the provider holds it
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $name = null,
        public readonly ?string $dateOfBirth = null,
    ) {}
}
