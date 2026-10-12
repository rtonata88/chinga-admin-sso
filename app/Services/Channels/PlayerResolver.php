<?php

namespace App\Services\Channels;

use App\Channels\ChannelRegistry;
use App\Channels\Contracts\KycUnavailable;
use App\Channels\Msisdn;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Who is dialling (USSD design U-D10). The phone number, from an authenticated
 * provider request, is the identity; the tenant's mobile-money KYC is the age check.
 * Age is computed here from the KYC date of birth. The player is never asked whether
 * they are 18, and a self-declaration is never accepted. A number is only admitted
 * with a verified KYC showing 18 or over; every other outcome says exactly why.
 */
class PlayerResolver
{
    public function __construct(private readonly ChannelRegistry $registry) {}

    /**
     * @return array{status: string, user_uuid?: string, language?: string, needs_language?: bool, account_status?: string}
     *                                                                                                                      status: admitted | needs_terms | kyc_unavailable | kyc_no_dob | kyc_not_verified | under_18 | account_closed
     */
    public function resolve(Tenant $tenant, string $msisdn): array
    {
        $user = User::where('tenant_id', $tenant->id)->where('phone', $msisdn)->first();
        if ($user !== null && $user->status !== 'active') {
            return ['status' => 'account_closed', 'account_status' => $user->status];
        }
        if ($user !== null && $user->kyc_status === 'verified' && $user->date_of_birth !== null
            && $this->isAdult($tenant, $user->date_of_birth->toDateString())) {
            return $this->admitted($tenant, $user);
        }

        $kyc = $this->registry->kyc($tenant);
        if ($kyc === null) {
            Log::warning('channel.kyc_not_configured', ['tenant' => $tenant->uuid]);

            return ['status' => 'kyc_unavailable'];
        }
        try {
            $result = $kyc->lookup($msisdn);
        } catch (KycUnavailable $e) {
            Log::warning('channel.kyc_unavailable', ['tenant' => $tenant->uuid, 'msisdn' => Msisdn::mask($msisdn), 'error' => $e->getMessage()]);

            return ['status' => 'kyc_unavailable'];
        }
        $provider = $this->registry->provider($tenant, 'kyc')?->driver;
        if ($result->status !== 'verified') {
            $user?->update(['kyc_status' => $result->status, 'kyc_provider' => $provider, 'kyc_checked_at' => now()]);

            return ['status' => 'kyc_not_verified'];
        }
        $dob = $this->dateOrNull($result->dateOfBirth);
        if ($dob === null) {
            Log::info('channel.kyc_no_dob', ['tenant' => $tenant->uuid, 'msisdn' => Msisdn::mask($msisdn)]);

            return ['status' => 'kyc_no_dob'];
        }
        if (! $this->isAdult($tenant, $dob)) {
            // No account is created for a minor, and nothing about them is kept beyond the log line.
            Log::info('channel.under_age', ['tenant' => $tenant->uuid, 'msisdn' => Msisdn::mask($msisdn)]);
            $user?->update(['kyc_status' => 'verified', 'kyc_provider' => $provider, 'kyc_checked_at' => now(), 'date_of_birth' => $dob]);

            return ['status' => 'under_18'];
        }

        $user = DB::transaction(function () use ($tenant, $msisdn, $result, $dob, $provider, $user) {
            $fields = [
                'kyc_status' => 'verified',
                'kyc_provider' => $provider,
                'kyc_checked_at' => now(),
                'kyc_verified_at' => now(),
                'date_of_birth' => $dob,
                'phone_verified_at' => now(),
            ];
            if ($user !== null) {
                $user->forceFill($fields)->save();

                return $user;
            }
            $created = User::forceCreate([
                'uuid' => (string) Str::uuid(),
                'tenant_id' => $tenant->id,
                'name' => $result->name ?: $msisdn,
                // Mobile accounts have no email or password; these satisfy the columns and can never log in.
                'email' => ltrim($msisdn, '+').'@msisdn.'.$tenant->slug.'.invalid',
                'password' => Hash::make(Str::random(40)),
                'phone' => $msisdn,
                'country_code' => $tenant->country_code,
                'language' => \App\Channels\Messages::languages($tenant)[0],
                'user_type' => 'mobile',
                'status' => 'active',
            ] + $fields);
            $created->getOrCreateWallet();

            return $created;
        });

        return $this->admitted($tenant, $user);
    }

    public function acceptTerms(User $user): void
    {
        $user->forceFill(['terms_accepted_at' => now(), 'terms_version' => config('channels.terms_version')])->save();
    }

    private function admitted(Tenant $tenant, User $user): array
    {
        $languages = \App\Channels\Messages::languages($tenant);
        $needsTerms = $user->terms_accepted_at === null || $user->terms_version !== config('channels.terms_version');

        return [
            'status' => $needsTerms ? 'needs_terms' : 'admitted',
            'user_uuid' => $user->uuid,
            'language' => in_array($user->language, $languages, true) ? $user->language : $languages[0],
            'needs_language' => $needsTerms && count($languages) > 1,
        ];
    }

    private function isAdult(Tenant $tenant, string $dob): bool
    {
        $minAge = (int) ($tenant->settings['min_age'] ?? 18);

        return Carbon::parse($dob)->addYears(max(18, $minAge))->lte(now()->startOfDay());
    }

    private function dateOrNull(?string $value): ?string
    {
        if ($value === null || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));

        return checkdate($m, $d, $y) && $y > 1900 ? $value : null;
    }
}
