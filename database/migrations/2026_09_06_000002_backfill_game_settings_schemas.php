<?php

use App\Models\Game;
use App\Support\FantasySettingsSchema;
use App\Support\SettingsSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * P3 data backfill:
 *  - give Chinga Fantasy its settings_schema and coerce saved settings to it
 *  - repair tenant_games.custom_settings rows written by the old form, which
 *    could spread a JSON string into char-indexed keys and double-encode it
 */
return new class extends Migration
{
    public function up(): void
    {
        $fantasy = Game::query()->where('slug', 'chinga-fantasy')->first();
        if ($fantasy) {
            $schema = new SettingsSchema(FantasySettingsSchema::definition());
            $update = [];
            if ($fantasy->settings_schema === null) {
                $update['settings_schema'] = FantasySettingsSchema::definition();
            }
            $update['settings'] = $schema->coerce(self::decode($fantasy->getRawOriginal('settings')));
            $fantasy->forceFill($update)->save();
        }

        $schemasByGame = Game::query()
            ->whereNotNull('settings_schema')
            ->get()
            ->mapWithKeys(fn (Game $game) => [$game->id => new SettingsSchema($game->settings_schema)]);

        DB::table('tenant_games')->whereNotNull('custom_settings')->orderBy('id')->each(function ($row) use ($schemasByGame) {
            $values = self::decode($row->custom_settings);
            // Drop the char-indexed garbage the old form produced ("0" => "{", ...).
            $values = array_filter($values, fn ($v, $k) => is_string($k) && ! ctype_digit($k), ARRAY_FILTER_USE_BOTH);
            if (isset($schemasByGame[$row->game_id])) {
                $values = $schemasByGame[$row->game_id]->coerce($values);
            }
            DB::table('tenant_games')->where('id', $row->id)->update([
                'custom_settings' => $values === [] ? null : json_encode($values),
            ]);
        });
    }

    public function down(): void
    {
        // Data repair only; nothing to reverse.
    }

    /** Decode a value that may be an array, a JSON string, or a double-encoded JSON string. */
    private static function decode(mixed $value): array
    {
        for ($i = 0; $i < 3 && is_string($value); $i++) {
            $decoded = json_decode($value, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                return [];
            }
            $value = $decoded;
        }

        return is_array($value) ? $value : [];
    }
};
