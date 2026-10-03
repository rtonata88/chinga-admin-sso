<?php

namespace App\Services;

use App\Models\Game;
use App\Models\GameSettingsAudit;
use App\Models\Tenant;
use App\Models\User;

/**
 * Writes and reads the settings audit (K4 design A2). A save that changes
 * neither the settings nor the RTP writes nothing. Values are compared
 * after sorting keys at every depth, so key order never counts as a change.
 */
class GameSettingsAuditor
{
    public function record(Game $game, ?Tenant $tenant, ?User $user, array $before, array $after, ?float $rtpBefore, ?float $rtpAfter): ?GameSettingsAudit
    {
        if (self::normalise($before) === self::normalise($after) && $rtpBefore === $rtpAfter) {
            return null;
        }

        return GameSettingsAudit::query()->create([
            'game_id' => $game->id,
            'tenant_id' => $tenant?->id,
            'user_id' => $user?->id,
            'scope' => $tenant === null ? 'global' : 'tenant',
            'before' => $before,
            'after' => $after,
            'rtp_before' => $rtpBefore,
            'rtp_after' => $rtpAfter,
        ]);
    }

    /**
     * The latest changes for the Settings page, newest first.
     *
     * @return list<array{id:int, at:string, by:?string, scope:string, tenant:?string, changes:list<array{key:string, before:mixed, after:mixed}>, rtp_before:?float, rtp_after:?float}>
     */
    public function recent(Game $game, int $limit = 50): array
    {
        return GameSettingsAudit::query()
            ->with(['user:id,name', 'tenant:id,name'])
            ->where('game_id', $game->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (GameSettingsAudit $a) => [
                'id' => $a->id,
                'at' => $a->created_at->toIso8601String(),
                'by' => $a->user?->name,
                'scope' => $a->scope,
                'tenant' => $a->tenant?->name,
                'changes' => self::changes($a->before ?? [], $a->after ?? []),
                'rtp_before' => $a->rtp_before === null ? null : (float) $a->rtp_before,
                'rtp_after' => $a->rtp_after === null ? null : (float) $a->rtp_after,
            ])
            ->all();
    }

    /**
     * What changed, one entry per key in alphabetical order. A tenant's
     * custom_settings is diffed per override, so History reads
     * "custom_settings.max_bet_amount 50 → 60" rather than two whole objects.
     *
     * @return list<array{key:string, before:mixed, after:mixed}>
     */
    public static function changes(array $before, array $after, string $prefix = ''): array
    {
        $keys = array_unique([...array_keys($before), ...array_keys($after)]);
        sort($keys);
        $out = [];
        foreach ($keys as $key) {
            $b = $before[$key] ?? null;
            $a = $after[$key] ?? null;
            if ($prefix === '' && $key === 'custom_settings' && (is_array($b) || $b === null) && (is_array($a) || $a === null)) {
                array_push($out, ...self::changes($b ?? [], $a ?? [], 'custom_settings.'));

                continue;
            }
            if (self::encode($b) !== self::encode($a)) {
                $out[] = ['key' => $prefix.$key, 'before' => $b, 'after' => $a];
            }
        }

        return $out;
    }

    private static function normalise(array $values): string
    {
        return self::encode($values);
    }

    /** JSON with object keys sorted at every depth, so key order never counts as a change. */
    private static function encode(mixed $value): string
    {
        return (string) json_encode(self::sortKeys($value));
    }

    private static function sortKeys(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sortKeys(...), $value);
    }
}
