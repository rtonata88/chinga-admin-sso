<?php

namespace App\Http\Controllers\Admin\Games;

use App\Services\FantasyAdminClient;

/** Chinga Fantasy v2 exposure and RTP consoles; rounds keep their own pages until the v2 switch. */
class FantasyConsoleController extends ProvablyFairConsoleController
{
    public function __construct(FantasyAdminClient $client)
    {
        $this->client = $client;
    }

    protected function gameName(): string
    {
        return 'Chinga Fantasy';
    }

    protected function base(): string
    {
        return '/fantasy';
    }
}
