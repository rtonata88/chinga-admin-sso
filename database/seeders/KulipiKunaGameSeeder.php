<?php

namespace Database\Seeders;

use App\Models\Game;
use App\Support\KulipiKunaSettingsSchema;
use App\Support\SettingsSchema;
use Database\Seeders\Concerns\CreatesOAuthClients;
use Illuminate\Database\Seeder;
use Laravel\Passport\ClientRepository;

/**
 * Registers Kulipi Kuna in the catalogue (design D17): the game row with its
 * PRD §9 settings and schema, the engine's client_credentials client bound
 * via oauth_client_games and restricted to wallet:write + gaming:read, and
 * the public web client the player SPA logs in with.
 *
 * Idempotent. backend_url / launch_url come from KULIPI_KUNA_BACKEND_URL and
 * KULIPI_KUNA_LAUNCH_URL and are null by default, as for Vrrr Pha.
 */
class KulipiKunaGameSeeder extends Seeder
{
    use CreatesOAuthClients;

    public const SLUG = 'kulipi-kuna';

    public const CLIENT_NAME = 'Kulipi Kuna Engine';

    public const SCOPES = ['wallet:write', 'gaming:read'];

    public const WEB_CLIENT_NAME = 'Kulipi Kuna Web';

    public function run(): void
    {
        $schema = KulipiKunaSettingsSchema::definition();

        $game = Game::firstOrCreate(
            ['slug' => self::SLUG],
            [
                'name' => 'Kulipi Kuna',
                'description' => 'Which hand has it? Pick a hand. Win, then collect or go again for double.',
                'type' => 'instant',
                'status' => 'development',
                'version' => '0.1.0',
                'settings' => (new SettingsSchema($schema))->defaults(),
                'settings_schema' => $schema,
            ]
        );

        $updates = [];
        if ($game->settings_schema === null) {
            $updates['settings_schema'] = $schema;
        }
        foreach (['backend_url', 'launch_url'] as $column) {
            $configured = config("services.kulipi_kuna.{$column}");
            if (is_string($configured) && $configured !== '' && $game->{$column} !== $configured) {
                $updates[$column] = rtrim($configured, '/');
            }
        }
        if ($updates !== []) {
            $game->update($updates);
        }

        $client = $this->firstOrCreateClient(
            self::CLIENT_NAME,
            fn (ClientRepository $clients) => $clients->createClientCredentialsGrantClient(self::CLIENT_NAME),
        );
        $this->restrictClientScopes($client, self::SCOPES);
        $this->bindClientToGame($client, $game);

        $web = $this->firstOrCreateClient(
            self::WEB_CLIENT_NAME,
            fn (ClientRepository $clients) => $clients->createAuthorizationCodeGrantClient(self::WEB_CLIENT_NAME, ['http://localhost:5176/oauth/callback'], false),
        );
        if ($web->grant_types !== ['password', 'refresh_token']) {
            $web->forceFill(['grant_types' => ['password', 'refresh_token']])->save();
        }

        $this->command?->info('Kulipi Kuna seeded.');
        $this->command?->line("  Game UUID:      {$game->uuid}");
        $this->command?->line('  Backend URL:    '.($game->backend_url ?? '(not set — KULIPI_KUNA_BACKEND_URL)'));
        $this->command?->line('  Client scopes:  '.implode(' ', self::SCOPES));
        if ($this->command) {
            $this->describeClient($client, 'Engine');
            $this->command->line("  Web Client ID (VITE_SSO_CLIENT_ID): {$web->id}  (public, password + refresh_token)");
        }
    }
}
