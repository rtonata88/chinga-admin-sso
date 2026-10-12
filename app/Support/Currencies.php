<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Currency profiles (USSD design U-D15): how a tenant's money is written.
 * Every engine, client, USSD screen and SMS formats through the profile the
 * SSO serves with the game config, so a figure is never shown in the wrong
 * currency. Only 0- and 2-decimal currencies are supported (U-D16); a tenant
 * may override the symbol in tenants.settings.currency_symbol.
 */
final class Currencies
{
    /** @var array<string, array{name: string, symbol: string, decimals: int, symbol_position: string, space: bool, thousands: string, decimal: string}> */
    private const PROFILES = [
        'NAD' => ['name' => 'Namibian dollar', 'symbol' => 'N$', 'decimals' => 2, 'symbol_position' => 'before', 'space' => false, 'thousands' => ',', 'decimal' => '.'],
        'BWP' => ['name' => 'Botswana pula', 'symbol' => 'P', 'decimals' => 2, 'symbol_position' => 'before', 'space' => false, 'thousands' => ',', 'decimal' => '.'],
        'ZAR' => ['name' => 'South African rand', 'symbol' => 'R', 'decimals' => 2, 'symbol_position' => 'before', 'space' => false, 'thousands' => ',', 'decimal' => '.'],
        'LSL' => ['name' => 'Lesotho loti', 'symbol' => 'M', 'decimals' => 2, 'symbol_position' => 'before', 'space' => false, 'thousands' => ',', 'decimal' => '.'],
        'SZL' => ['name' => 'Eswatini lilangeni', 'symbol' => 'E', 'decimals' => 2, 'symbol_position' => 'before', 'space' => false, 'thousands' => ',', 'decimal' => '.'],
        'ZMW' => ['name' => 'Zambian kwacha', 'symbol' => 'K', 'decimals' => 2, 'symbol_position' => 'before', 'space' => false, 'thousands' => ',', 'decimal' => '.'],
        'MWK' => ['name' => 'Malawian kwacha', 'symbol' => 'MK', 'decimals' => 2, 'symbol_position' => 'before', 'space' => false, 'thousands' => ',', 'decimal' => '.'],
        'KES' => ['name' => 'Kenyan shilling', 'symbol' => 'KSh', 'decimals' => 2, 'symbol_position' => 'before', 'space' => true, 'thousands' => ',', 'decimal' => '.'],
        'UGX' => ['name' => 'Ugandan shilling', 'symbol' => 'USh', 'decimals' => 0, 'symbol_position' => 'before', 'space' => true, 'thousands' => ',', 'decimal' => '.'],
        'USD' => ['name' => 'US dollar', 'symbol' => '$', 'decimals' => 2, 'symbol_position' => 'before', 'space' => false, 'thousands' => ',', 'decimal' => '.'],
    ];

    public static function supports(?string $code): bool
    {
        return is_string($code) && isset(self::PROFILES[$code]);
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::PROFILES);
    }

    /** @return array{code: string, name: string, symbol: string, decimals: int, symbol_position: string, space: bool, thousands: string, decimal: string} */
    public static function profile(string $code, ?string $symbolOverride = null): array
    {
        if (! self::supports($code)) {
            throw new \InvalidArgumentException("Unsupported currency {$code}");
        }
        $p = self::PROFILES[$code];
        if (is_string($symbolOverride) && trim($symbolOverride) !== '') {
            $p['symbol'] = mb_substr(trim($symbolOverride), 0, 6);
        }

        return ['code' => $code] + $p;
    }

    /** The tenant's profile; a tenant on an unsupported code is an error, never silently NAD. */
    public static function forTenant(Tenant $tenant): array
    {
        $override = is_array($tenant->settings) ? ($tenant->settings['currency_symbol'] ?? null) : null;

        return self::profile($tenant->currency ?: 'NAD', is_string($override) ? $override : null);
    }

    /**
     * Formats a decimal amount ("1184.6", "50", 1200) for server-side text (admin, SMS) without
     * floats. A 0-decimal currency with a non-zero fraction is an error, never rounded.
     */
    public static function format(string|int $amount, array $profile): string
    {
        $text = trim((string) $amount);
        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $text, $m)) {
            throw new \InvalidArgumentException("Not a decimal amount: {$text}");
        }
        [$neg, $whole, $fraction] = [$m[1] === '-', ltrim($m[2], '0') ?: '0', $m[3] ?? ''];
        $decimals = $profile['decimals'];
        if (rtrim(substr($fraction, $decimals), '0') !== '') {
            throw new \InvalidArgumentException("{$text} has more than {$decimals} decimals for {$profile['code']}");
        }
        $grouped = strrev(implode($profile['thousands'], str_split(strrev($whole), 3)));
        $number = $decimals > 0 ? $grouped.$profile['decimal'].str_pad(substr($fraction, 0, $decimals), $decimals, '0') : $grouped;
        $sep = $profile['space'] ? ' ' : '';
        $body = $profile['symbol_position'] === 'after' ? $number.$sep.$profile['symbol'] : $profile['symbol'].$sep.$number;

        return ($neg && trim($number, '0.,') !== '' ? '-' : '').$body;
    }
}
