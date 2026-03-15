<?php

namespace App\Services\Tenant;

use App\Models\Game;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Passport\ClientRepository;

class TenantOnboardingService
{
    public function onboard(array $data): array
    {
        return DB::transaction(function () use ($data) {
            // 1. Generate unique slug
            $slug = $data['slug'] ?? Str::slug($data['name']);
            $slug = $this->ensureUniqueSlug($slug);

            // 2. Create tenant
            $tenant = Tenant::create([
                'name' => $data['name'],
                'slug' => $slug,
                'legal_name' => $data['legal_name'] ?? null,
                'registration_number' => $data['registration_number'] ?? null,
                'license_number' => $data['license_number'] ?? null,
                'contact_email' => $data['contact_email'],
                'contact_phone' => $data['contact_phone'] ?? null,
                'logo_url' => $data['logo_url'] ?? null,
                'domain' => $data['domain'] ?? null,
                'country_code' => $data['country_code'] ?? 'NA',
                'currency' => $data['currency'] ?? 'NAD',
                'timezone' => $data['timezone'] ?? 'Africa/Windhoek',
                'revenue_share_pct' => $data['revenue_share_pct'] ?? 0,
                'contract_starts_at' => $data['contract_starts_at'] ?? null,
                'contract_ends_at' => $data['contract_ends_at'] ?? null,
                'settings' => $data['settings'] ?? null,
            ]);

            // 3. Create default OAuth client
            $oauthClient = $this->createDefaultOAuthClient($tenant);

            // 4. Assign games
            $this->assignGames($tenant, $data['game_ids'] ?? []);

            // 5. Create initial tenant admin user (if provided)
            $adminUser = null;
            if (! empty($data['admin_email'])) {
                $adminUser = $this->createTenantAdmin($tenant, $data);
            }

            return [
                'tenant' => $tenant,
                'oauth_client' => [
                    'id' => $oauthClient->id,
                    'name' => $oauthClient->name,
                    'secret' => $oauthClient->plainSecret,
                    'redirect' => $oauthClient->redirect,
                ],
                'admin_user' => $adminUser ? [
                    'uuid' => $adminUser->uuid,
                    'email' => $adminUser->email,
                ] : null,
                'games_assigned' => count($data['game_ids'] ?? []),
            ];
        });
    }

    private function createDefaultOAuthClient(Tenant $tenant): mixed
    {
        $clients = app(ClientRepository::class);

        $redirectUri = $tenant->domain
            ? "https://{$tenant->domain}/callback"
            : "https://{$tenant->slug}.sso.chingagames.com/callback";

        $client = $clients->createAuthorizationCodeGrantClient(
            "{$tenant->name} Default Client",
            [$redirectUri],
        );

        $client->tenant_id = $tenant->id;
        $client->save();

        return $client;
    }

    private function ensureUniqueSlug(string $slug): string
    {
        $original = $slug;
        $counter = 1;

        while (Tenant::where('slug', $slug)->exists()) {
            $slug = "{$original}-{$counter}";
            $counter++;
        }

        return $slug;
    }

    private function assignGames(Tenant $tenant, array $gameUuids): void
    {
        if (empty($gameUuids)) {
            return;
        }

        $games = Game::whereIn('uuid', $gameUuids)->where('status', 'active')->get();

        $syncData = $games->mapWithKeys(fn (Game $game) => [
            $game->id => ['enabled' => true],
        ])->toArray();

        $tenant->games()->sync($syncData);
    }

    private function createTenantAdmin(Tenant $tenant, array $data): User
    {
        $password = Str::random(16);

        $user = User::withoutGlobalScopes()->create([
            'name' => $data['admin_name'] ?? 'Tenant Admin',
            'email' => $data['admin_email'],
            'password' => Hash::make($password),
            'tenant_id' => $tenant->id,
            'email_verified_at' => now(),
        ]);

        $user->assignRole('tenant_admin', $tenant->id);

        // TODO: Send welcome email with temporary password

        return $user;
    }
}
