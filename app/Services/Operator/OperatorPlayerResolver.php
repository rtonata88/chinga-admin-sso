<?php

namespace App\Services\Operator;

use App\Channels\Messages;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The operator's player on our platform, found or created on first launch. The
 * operator is the account holder (KYC, age, self-exclusion stay with it), so the
 * account here exists only to carry the player's identity, games and wallet. Like
 * the USSD mobile accounts, it has no usable email or password and can never log in
 * directly: the only way in is a launch from the operator's own servers.
 */
class OperatorPlayerResolver
{
    /** @return array{user: User, created: bool} */
    public function resolve(Tenant $tenant, string $playerId, ?string $displayName = null, ?string $lang = null): array
    {
        $existing = $this->find($tenant, $playerId);
        if ($existing !== null) {
            if ($displayName !== null && $displayName !== '' && $existing->name !== $displayName) {
                $existing->forceFill(['name' => $displayName])->save();
            }

            return ['user' => $existing, 'created' => false];
        }

        // The operator's language when the tenant has it, otherwise the tenant's first.
        $languages = Messages::languages($tenant);
        $language = $lang !== null && in_array(strtolower(substr($lang, 0, 2)), $languages, true) ? strtolower(substr($lang, 0, 2)) : $languages[0];

        try {
            $user = DB::transaction(function () use ($tenant, $playerId, $displayName, $language) {
                $user = User::forceCreate([
                    'uuid' => (string) Str::uuid(),
                    'tenant_id' => $tenant->id,
                    'external_player_id' => $playerId,
                    'name' => $displayName !== null && $displayName !== '' ? $displayName : 'Player '.Str::limit($playerId, 40, ''),
                    // Satisfies the columns; can never receive mail or log in.
                    'email' => 'op-'.substr(hash('sha256', $playerId), 0, 24).'@ext.'.$tenant->slug.'.invalid',
                    'password' => Hash::make(Str::random(40)),
                    'country_code' => $tenant->country_code,
                    'language' => $language,
                    'user_type' => 'operator',
                    'status' => 'active',
                ]);
                $user->assignRole('player', $tenant->id);
                $user->getOrCreateWallet();

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // Two launches for a new player raced; the other one created it.
            return ['user' => $this->find($tenant, $playerId) ?? throw new \RuntimeException('Player could not be created.'), 'created' => false];
        }

        return ['user' => $user, 'created' => true];
    }

    public function find(Tenant $tenant, string $playerId): ?User
    {
        return User::where('tenant_id', $tenant->id)->where('external_player_id', $playerId)->first();
    }
}
