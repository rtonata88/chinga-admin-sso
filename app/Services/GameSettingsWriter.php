<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Tenant;
use App\Models\User;
use App\Support\FantasySettingsSchema;
use App\Support\KulipiKunaConfigRules;
use App\Support\SettingsSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The one way game settings are written (K4 final review, findings 1 and 2).
 * Every write runs in a transaction: lock the game row (and the tenant_games
 * row for a tenant write), read the before state, apply the Kulipi Kuna
 * rules to the effective settings, write, then audit. The audit row and the
 * settings change commit together or not at all.
 *
 * Lock order is always game row, then tenant_games row, so a global save
 * and a tenant save on the same game serialise instead of deadlocking, and
 * the Kulipi check of a global save sees every tenant's current overrides.
 */
class GameSettingsWriter
{
    public function __construct(private readonly GameSettingsAuditor $auditor) {}

    /**
     * Validate global settings against the schema (required keys honoured)
     * and return them sparse, restricted to schema keys when the schema
     * declares any. Error keys carry $errorPrefix (e.g. "settings.").
     *
     * @throws ValidationException
     */
    public static function validateGlobal(SettingsSchema $schema, array $values, string $errorPrefix = ''): array
    {
        return self::validateAgainst($schema->rules(), $schema->keys(), $values, $errorPrefix);
    }

    /**
     * Validate sparse tenant overrides (all nullable, non-overridable keys
     * dropped) and return them without nulls.
     *
     * @throws ValidationException
     */
    public static function validateOverrides(SettingsSchema $schema, array $values, string $errorPrefix = 'custom_settings.'): array
    {
        // A key the schema keeps global (house_edge on Kulipi Kuna, say) is
        // refused outright rather than dropped, so an API caller is not told
        // a commercial override was accepted.
        $locked = [];
        foreach ($values as $key => $value) {
            if ($value !== null && $schema->property((string) $key) !== null && ! $schema->isOverridable((string) $key)) {
                $locked[(string) $key] = "{$key} cannot be overridden per tenant.";
            }
        }
        if ($locked !== []) {
            throw self::prefixed($locked, $errorPrefix);
        }

        return self::validateAgainst($schema->rules(tenantOverride: true), $schema->overridableKeys(), $values, $errorPrefix);
    }

    /**
     * Replace the game's global settings. Returns the theoretical RTP after
     * the save (null when the game does not price by house edge).
     *
     * @throws ValidationException when the Kulipi Kuna rules refuse the result
     */
    public function writeGlobal(Game $game, array $next, ?User $user, string $errorPrefix = ''): ?float
    {
        return DB::transaction(function () use ($game, $next, $user, $errorPrefix) {
            $locked = Game::query()->lockForUpdate()->findOrFail($game->id);
            $beforeSettings = $locked->settings ?? [];

            if ($locked->slug === KulipiKunaConfigRules::GAME_SLUG) {
                $errors = KulipiKunaConfigRules::violations($next);
                if ($errors === []) {
                    $rows = DB::table('tenant_games')
                        ->join('tenants', 'tenants.id', '=', 'tenant_games.tenant_id')
                        ->where('tenant_games.game_id', $locked->id)
                        ->orderBy('tenants.name')
                        ->get(['tenants.name', 'tenant_games.custom_settings']);
                    foreach ($rows as $row) {
                        $overrides = self::decode($row->custom_settings);
                        foreach (KulipiKunaConfigRules::violations(array_merge($next, $overrides)) as $key => $message) {
                            $errors[$key] ??= "{$row->name}'s overrides would break this: {$message}";
                        }
                    }
                }
                if ($errors !== []) {
                    throw self::prefixed($errors, $errorPrefix);
                }
            }

            // PRD §11 admin guardrails: the theoretical RTP is computed on every save and every
            // change of it is logged with before and after. The allowed band is the schema's
            // house_edge bounds, so an out-of-band edge never reaches here.
            $rtpBefore = FantasySettingsSchema::rtp($beforeSettings);
            $rtpAfter = FantasySettingsSchema::rtp($next);
            if ($rtpAfter !== null && $rtpBefore !== $rtpAfter) {
                Log::info('game.rtp_changed', [
                    'game' => $locked->slug,
                    'by' => $user?->id,
                    'house_edge_before' => $beforeSettings['house_edge'] ?? null,
                    'house_edge_after' => $next['house_edge'] ?? null,
                    'rtp_before' => $rtpBefore,
                    'rtp_after' => $rtpAfter,
                ]);
            }

            $locked->update(['settings' => $next]);
            $this->auditor->record($locked, null, $user, $beforeSettings, $next, $rtpBefore, $rtpAfter);
            $game->settings = $next;
            $game->syncOriginalAttribute('settings');

            return $rtpAfter;
        });
    }

    /**
     * Set a tenant's enabled flag and sparse overrides. The tenant must
     * already be attached to the game; otherwise 404 and nothing is written.
     * Null overrides mean "keep what is stored": a caller that did not send
     * custom_settings (the tenants page's toggle and Manage games) must never
     * wipe an operator's overrides.
     *
     * @throws ValidationException when the Kulipi Kuna rules refuse the merged settings
     */
    public function writeTenant(Game $game, Tenant $tenant, bool $enabled, ?array $overrides, ?User $user, string $errorPrefix = 'custom_settings.'): void
    {
        DB::transaction(function () use ($game, $tenant, $enabled, $overrides, $user, $errorPrefix) {
            $locked = Game::query()->lockForUpdate()->findOrFail($game->id);
            $row = DB::table('tenant_games')
                ->where('game_id', $locked->id)
                ->where('tenant_id', $tenant->id)
                ->lockForUpdate()
                ->first();
            abort_if($row === null, 404, 'This tenant does not have this game.');

            $stored = self::decode($row->custom_settings);
            $overrides ??= $stored;
            $global = $locked->settings ?? [];
            $this->assertKulipiTenant($locked, $global, $overrides, $errorPrefix);

            $before = ['enabled' => (bool) $row->enabled, 'custom_settings' => $stored];
            $after = ['enabled' => $enabled, 'custom_settings' => $overrides];

            DB::table('tenant_games')->where('id', $row->id)->update([
                'enabled' => $enabled,
                'custom_settings' => $overrides === [] ? null : json_encode($overrides),
                'updated_at' => now(),
            ]);

            $this->auditor->record(
                $locked,
                $tenant,
                $user,
                $before,
                $after,
                FantasySettingsSchema::rtp(array_merge($global, $before['custom_settings'])),
                FantasySettingsSchema::rtp(array_merge($global, $overrides)),
            );
        });
    }

    /**
     * Attach a game to a tenant with its overrides, audited as a tenant
     * change from nothing. An already-attached pair is a writeTenant, so
     * null overrides keep what is stored; a new attachment starts with none.
     *
     * @throws ValidationException
     */
    public function attachTenant(Game $game, Tenant $tenant, bool $enabled, ?array $overrides, ?User $user, string $errorPrefix = 'custom_settings.'): void
    {
        DB::transaction(function () use ($game, $tenant, $enabled, $overrides, $user, $errorPrefix) {
            $locked = Game::query()->lockForUpdate()->findOrFail($game->id);
            $exists = DB::table('tenant_games')->where('game_id', $locked->id)->where('tenant_id', $tenant->id)->lockForUpdate()->exists();
            if ($exists) {
                $this->writeTenant($locked, $tenant, $enabled, $overrides, $user, $errorPrefix);

                return;
            }

            $overrides ??= [];
            $global = $locked->settings ?? [];
            $this->assertKulipiTenant($locked, $global, $overrides, $errorPrefix);

            DB::table('tenant_games')->insert([
                'tenant_id' => $tenant->id,
                'game_id' => $locked->id,
                'enabled' => $enabled,
                'custom_settings' => $overrides === [] ? null : json_encode($overrides),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->auditor->record($locked, $tenant, $user, [], ['enabled' => $enabled, 'custom_settings' => $overrides],
                null, FantasySettingsSchema::rtp(array_merge($global, $overrides)));
        });
    }

    /** Detach a game from a tenant, auditing the overrides that go with it. */
    public function detachTenant(Game $game, Tenant $tenant, ?User $user): void
    {
        DB::transaction(function () use ($game, $tenant, $user) {
            $locked = Game::query()->lockForUpdate()->findOrFail($game->id);
            $row = DB::table('tenant_games')->where('game_id', $locked->id)->where('tenant_id', $tenant->id)->lockForUpdate()->first();
            if ($row === null) {
                return;
            }
            $before = ['enabled' => (bool) $row->enabled, 'custom_settings' => self::decode($row->custom_settings)];
            DB::table('tenant_games')->where('id', $row->id)->delete();
            $this->auditor->record($locked, $tenant, $user, $before, [],
                FantasySettingsSchema::rtp(array_merge($locked->settings ?? [], $before['custom_settings'])), null);
        });
    }

    private function assertKulipiTenant(Game $game, array $global, array $overrides, string $errorPrefix): void
    {
        if ($game->slug !== KulipiKunaConfigRules::GAME_SLUG) {
            return;
        }
        $errors = KulipiKunaConfigRules::violations(array_merge($global, $overrides));
        if ($errors !== []) {
            throw self::prefixed($errors, $errorPrefix);
        }
    }

    /** @param array<string, string|list<string>> $errors */
    private static function prefixed(array $errors, string $prefix): ValidationException
    {
        return ValidationException::withMessages(
            collect($errors)->mapWithKeys(fn ($m, $k) => ["{$prefix}{$k}" => $m])->all()
        );
    }

    /** @param list<string> $keys */
    private static function validateAgainst(array $rules, array $keys, array $values, string $errorPrefix): array
    {
        if ($rules === []) {
            // No schema to validate against: free-form settings, stored as given.
            return array_filter($values, fn ($value) => $value !== null);
        }
        $validator = Validator::make($values, $rules);
        if ($validator->fails()) {
            throw self::prefixed($validator->errors()->toArray(), $errorPrefix);
        }
        $validated = array_intersect_key($validator->validated(), array_flip($keys));

        return array_filter($validated, fn ($value) => $value !== null);
    }

    private static function decode(mixed $json): array
    {
        if (is_array($json)) {
            return $json;
        }
        $decoded = is_string($json) ? json_decode($json, true) : null;

        return is_array($decoded) ? $decoded : [];
    }
}
