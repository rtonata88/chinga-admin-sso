<?php

namespace Database\Seeders;

use App\Models\Game;
use Illuminate\Database\Seeder;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

class ChingaFantasyGameSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create or find the Chinga Fantasy game record
        $game = Game::firstOrCreate(
            ['slug' => 'chinga-fantasy'],
            [
                'name' => 'Chinga Fantasy',
                'type' => 'other',
                'status' => 'active',
                'settings' => [
                    'min_bet_amount' => 10,
                    'max_bet_amount' => 1000,
                    'display_teams' => true,
                    'round_betting_seconds' => 60,
                    'round_results_seconds' => 30,
                    'round_dialog_seconds' => 10,
                    'min_jackpot_amount' => 5000,
                    'jackpot_percentage' => 5,
                ],
            ]
        );

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

        // 4. Output the results
        $this->command->info('Chinga Fantasy Game seeded successfully.');
        $this->command->newLine();
        $this->command->line("  Game UUID:          {$game->uuid}");
        $this->command->newLine();
        $this->command->line("  Frontend Client ID: {$frontendClient->id}");
        $this->command->line("  (Public PKCE client — no secret)");
        $this->command->newLine();
        $this->command->line("  Server Client ID:   {$serverClient->id}");
        $this->command->line("  Server Client Secret: " . ($serverClient->plainSecret ?? '(already exists — secret not shown again)'));
    }

    /**
     * Find an existing non-revoked OAuth client by name, or create one using the given factory.
     */
    private function firstOrCreateClient(string $name, callable $factory): Client
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
}
