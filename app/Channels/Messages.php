<?php

namespace App\Channels;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Channel strings (USSD design U-D19): every text a player sees on USSD or in SMS is a
 * key with a string per language. Platform defaults ship in resources/channel-messages;
 * a tenant overrides or adds languages in channel_messages. A missing string falls back
 * to the tenant's default language, then to English; never to machine translation.
 */
class Messages
{
    /** @return list<string> the tenant's languages, default first */
    public static function languages(Tenant $tenant): array
    {
        $langs = $tenant->settings['channel_languages'] ?? ['en'];
        $langs = array_values(array_filter(is_array($langs) ? $langs : ['en'], 'is_string'));

        return $langs === [] ? ['en'] : $langs;
    }

    /** @return array<string, string> */
    public static function defaults(string $language): array
    {
        $path = resource_path("channel-messages/{$language}.json");
        if (! is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }

    /** @return array<string, string> every key, resolved for one language */
    public static function forTenant(Tenant $tenant, string $language): array
    {
        $fallback = self::languages($tenant)[0];
        $out = self::defaults('en');
        foreach (array_unique([$fallback, $language]) as $lang) {
            $out = array_merge($out, self::defaults($lang), self::overrides($tenant, $lang));
        }

        return $out;
    }

    /** @return array<string, string> */
    private static function overrides(Tenant $tenant, string $language): array
    {
        return DB::table('channel_messages')->where('tenant_id', $tenant->id)->where('language', $language)
            ->pluck('text', 'key')->all();
    }

    /** "{amount}" → the value; an unknown placeholder is left visible rather than silently dropped. */
    public static function render(string $text, array $params): string
    {
        return preg_replace_callback('/\{([a-z_]+)\}/', fn ($m) => array_key_exists($m[1], $params) ? (string) $params[$m[1]] : $m[0], $text) ?? $text;
    }
}
