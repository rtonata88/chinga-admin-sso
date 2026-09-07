<?php

namespace App\Exceptions;

use App\Models\Game;
use RuntimeException;

class MissingBackendUrlException extends RuntimeException
{
    public static function forGame(Game $game): self
    {
        return new self("Game \"{$game->name}\" ({$game->slug}) has no backend_url configured.");
    }
}
