<?php

namespace App\Console\Commands;

use App\Models\OperatorIntegration;
use Illuminate\Console\Command;

/**
 * The sites allowed to embed the games, from every active integration, as the
 * Content-Security-Policy frame-ancestors value for the game hosts' nginx config
 * (deploy/forge/*-nginx-location.conf in the platform repo).
 */
class OperatorFrameOrigins extends Command
{
    protected $signature = 'operators:frame-origins {--nginx : print the add_header line}';

    protected $description = 'Print the frame-ancestors list for the game hosts';

    public function handle(): int
    {
        $origins = OperatorIntegration::where('status', 'active')->get()
            ->flatMap(fn (OperatorIntegration $i) => $i->embed_origins ?? [])
            ->unique()->sort()->values()->all();
        $value = trim("frame-ancestors 'self' ".implode(' ', $origins));
        $this->line($this->option('nginx') ? "add_header Content-Security-Policy \"{$value}\" always;" : $value);

        return self::SUCCESS;
    }
}
