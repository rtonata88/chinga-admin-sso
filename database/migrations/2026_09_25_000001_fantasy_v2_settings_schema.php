<?php

use App\Models\Game;
use App\Support\FantasySettingsSchema;
use App\Support\SettingsSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Chinga Fantasy v2 (PRD §11): the settings schema becomes the derived-
 * odds model with house_edge as the only RTP input. Saved global and
 * per-tenant values are carried across where a v2 key exists and the
 * v1 keys that made RTP an accident are dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        $fantasy = Game::query()->where('slug', 'chinga-fantasy')->first();
        if (! $fantasy) {
            return;
        }
        $schema = new SettingsSchema(FantasySettingsSchema::definition());
        $old = self::decode($fantasy->getRawOriginal('settings'));
        $fantasy->forceFill([
            'settings_schema' => FantasySettingsSchema::definition(),
            'settings' => $schema->coerce(array_merge($schema->defaults(), FantasySettingsSchema::migrateV1($old))),
        ])->save();

        DB::table('tenant_games')->where('game_id', $fantasy->id)->whereNotNull('custom_settings')->orderBy('id')->each(function ($row) use ($schema) {
            $old = self::decode($row->custom_settings);
            $new = $schema->coerce(FantasySettingsSchema::migrateV1($old));
            DB::table('tenant_games')->where('id', $row->id)->update(['custom_settings' => $new === [] ? null : json_encode($new)]);
        });
    }

    public function down(): void
    {
        // Forward only: v1's keys are not restorable from v2 values.
    }

    private static function decode(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
};
