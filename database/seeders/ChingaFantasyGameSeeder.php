<?php

namespace Database\Seeders;

use App\Models\Game;
use App\Support\FantasySettingsSchema;
use App\Support\SettingsSchema;
use Database\Seeders\Concerns\CreatesOAuthClients;
use Illuminate\Database\Seeder;
use Laravel\Passport\ClientRepository;

class ChingaFantasyGameSeeder extends Seeder
{
    use CreatesOAuthClients;

    public function run(): void
    {
        // 1. Create or find the Chinga Fantasy game record. Settings and their
        //    schema come from one source so the admin form, the config
        //    endpoint and the engine defaults agree.
        $schema = FantasySettingsSchema::definition();
        $game = Game::firstOrCreate(
            ['slug' => 'chinga-fantasy'],
            [
                'name' => 'Chinga Fantasy',
                'type' => 'other',
                'status' => 'active',
                'settings' => (new SettingsSchema($schema))->defaults(),
                'settings_schema' => $schema,
                'backend_url' => config('services.chinga_fantasy.api_url'),
            ]
        );
        if ($game->settings_schema === null) {
            $game->update(['settings_schema' => $schema]);
        }

        // 2. Create (or find) the public PKCE OAuth client for the Fantasy Frontend
        $frontendClient = $this->firstOrCreateClient(
            'Chinga Fantasy Frontend',
            fn (ClientRepository $clients) => $clients->createAuthorizationCodeGrantClient(
                'Chinga Fantasy Frontend',
                ['http://localhost:5173/oauth/callback'],
                false, // public (PKCE), no secret
            )
        );

        // 3. Create (or find) the confidential OAuth client for the Fantasy Game Server
        $serverClient = $this->firstOrCreateClient(
            'Chinga Fantasy Game Server',
            fn (ClientRepository $clients) => $clients->createClientCredentialsGrantClient(
                'Chinga Fantasy Game Server',
            )
        );

        // 4. Authorise the game server for service-level calls (settlement
        //    credit) against this game's sessions. Production rows were bound
        //    by the oauth_client_games backfill; fresh installs need this.
        $this->bindClientToGame($serverClient, $game);

        // 5. Output the results
        $this->command->info('Chinga Fantasy Game seeded successfully.');
        $this->command->newLine();
        $this->command->line("  Game UUID:          {$game->uuid}");
        $this->command->newLine();
        $this->command->line("  Frontend Client ID: {$frontendClient->id}");
        $this->command->line('  (Public PKCE client — no secret)');
        $this->command->newLine();
        $this->command->line("  Server Client ID:   {$serverClient->id}");
        $this->command->line('  Server Client Secret: '.($serverClient->plainSecret ?? '(already exists — secret not shown again)'));
    }
}
