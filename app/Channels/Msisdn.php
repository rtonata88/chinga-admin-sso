<?php

namespace App\Channels;

/** Phone numbers as E.164 ("+26771234567"), the one spelling every lookup uses. */
final class Msisdn
{
    /** @param  string  $countryDial  the tenant country's dialling code without +, e.g. "267" */
    public static function normalize(string $raw, string $countryDial): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (str_starts_with(trim($raw), '+') || str_starts_with($digits, $countryDial)) {
            $e164 = $digits;
        } elseif (str_starts_with($digits, '00')) {
            $e164 = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $e164 = $countryDial.substr($digits, 1);
        } else {
            $e164 = $countryDial.$digits;
        }

        return preg_match('/^[1-9]\d{7,14}$/', $e164) ? '+'.$e164 : null;
    }

    /** "+267•••••567": what logs and admin lists show. */
    public static function mask(string $msisdn): string
    {
        return substr($msisdn, 0, 4).str_repeat('•', max(0, strlen($msisdn) - 7)).substr($msisdn, -3);
    }

    public static function dialCode(?string $countryCode): string
    {
        return match (strtoupper((string) $countryCode)) {
            'BW' => '267', 'NA' => '264', 'ZA' => '27', 'ZM' => '260', 'KE' => '254', 'UG' => '256',
            'LS' => '266', 'SZ' => '268', 'MW' => '265', default => '264',
        };
    }
}
