<?php

namespace Database\Seeders\Concerns;

use App\Models\Game;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

trait CreatesOAuthClients
{
    /**
     * Find an existing non-revoked OAuth client by name, or create one using
     * the given factory. The plain secret is only available on a freshly
     * created client.
     */
    protected function firstOrCreateClient(string $name, callable $factory): Client
    {
        $existing = Passport::client()
            ->newQuery()
            ->where('name', $name)
            ->where('revoked', false)
            ->first();

        if ($existing) {
            return $existing;
        }

        return $factory(app(ClientRepository::class));
    }

    /**
     * Authorise the client for service-level calls against this game's
     * sessions (oauth_client_games). Idempotent; never detaches.
     */
    protected function bindClientToGame(Client $client, Game $game): void
    {
        $game->oauthClients()->syncWithoutDetaching([$client->id]);
    }

    /** Restrict the scopes a client may hold; null means unrestricted. */
    protected function restrictClientScopes(Client $client, array $scopes): void
    {
        if ($client->scopes !== $scopes) {
            $client->forceFill(['scopes' => $scopes])->save();
        }
    }

    protected function describeClient(Client $client, string $label): void
    {
        $this->command->line("  {$label} Client ID:     {$client->id}");
        $this->command->line("  {$label} Client Secret: ".($client->plainSecret ?? '(already exists — secret not shown again)'));
    }
}
