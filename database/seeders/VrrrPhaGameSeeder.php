<?php

namespace Database\Seeders;

use App\Models\Game;
use App\Support\SettingsSchema;
use App\Support\VrrrPhaSettingsSchema;
use Database\Seeders\Concerns\CreatesOAuthClients;
use Illuminate\Database\Seeder;
use Laravel\Passport\ClientRepository;

/**
 * Registers Vrrr Pha in the catalogue (PRD §4 P8 / M0): the game row with
 * its PRD §6 settings and schema, and the engine's client_credentials
 * OAuth client bound via oauth_client_games and restricted to
 * wallet:write + gaming:read.
 *
 * Idempotent. backend_url / launch_url come from VRRR_PHA_BACKEND_URL and
 * VRRR_PHA_LAUNCH_URL and are null by default: Fantasy's local backend is
 * already on :3001, so a hardcoded default would have the health probe and
 * revenue reports read Fantasy's engine as Vrrr Pha's. Games without a
 * backend are skipped by both.
 *
 * Enabling the game for a tenant is done through the platform UI or
 * POST /api/v1/platform/tenants/{tenant}/games.
 */
class VrrrPhaGameSeeder extends Seeder
{
    use CreatesOAuthClients;

    public const SLUG = 'vrrr-pha';

    public const CLIENT_NAME = 'Vrrr Pha Engine';

    public const SCOPES = ['wallet:write', 'gaming:read'];

    /** Public client the browser SPA logs in with through the auth proxy (password + refresh grants). */
    public const WEB_CLIENT_NAME = 'Vrrr Pha Web';

    public function run(): void
    {
        $schema = VrrrPhaSettingsSchema::definition();

        $game = Game::firstOrCreate(
            ['slug' => self::SLUG],
            [
                'name' => 'Vrrr Pha',
                'description' => 'Real-time crash game. A GTI accelerates, the multiplier climbs; cash out before the engine blows.',
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
            $configured = config("services.vrrr_pha.{$column}");
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

        // The player frontend: a PUBLIC client (no secret) that the SPA names
        // in POST /api/v1/auth/login|refresh. The repository has no public
        // password-client factory, so create an auth-code client and set the
        // grants explicitly. No tenant_id: one build serves every tenant.
        $web = $this->firstOrCreateClient(
            self::WEB_CLIENT_NAME,
            fn (ClientRepository $clients) => $clients->createAuthorizationCodeGrantClient(self::WEB_CLIENT_NAME, ['http://localhost:5174/oauth/callback'], false),
        );
        if ($web->grant_types !== ['password', 'refresh_token']) {
            $web->forceFill(['grant_types' => ['password', 'refresh_token']])->save();
        }

        $this->command?->info('Vrrr Pha seeded.');
        $this->command?->line("  Game UUID:      {$game->uuid}");
        $this->command?->line('  Backend URL:    '.($game->backend_url ?? '(not set — VRRR_PHA_BACKEND_URL)'));
        $this->command?->line('  Launch URL:     '.($game->launch_url ?? '(not set — VRRR_PHA_LAUNCH_URL)'));
        $this->command?->line('  Client scopes:  '.implode(' ', self::SCOPES));
        if ($this->command) {
            $this->describeClient($client, 'Engine');
            $this->command->line("  Web Client ID (VITE_SSO_CLIENT_ID): {$web->id}  (public, password + refresh_token)");
        }
    }
}
