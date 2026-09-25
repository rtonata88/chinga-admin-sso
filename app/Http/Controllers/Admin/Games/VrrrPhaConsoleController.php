<?php

namespace App\Http\Controllers\Admin\Games;

use App\Services\VrrrPhaAdminClient;

class VrrrPhaConsoleController extends ProvablyFairConsoleController
{
    public function __construct(VrrrPhaAdminClient $client)
    {
        $this->client = $client;
    }

    protected function gameName(): string
    {
        return 'Vrrr Pha';
    }

    protected function base(): string
    {
        return '/vrrr-pha';
    }
}
