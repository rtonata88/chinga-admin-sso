<?php

namespace App\Services\OAuth;

use App\Models\User;
use Illuminate\Support\Facades\URL;
use Laravel\Passport\Token;

class OpenIDConnectService
{
    /**
     * Build OIDC userinfo claims based on scopes.
     */
    public function buildUserInfoClaims(User $user, array $scopes): array
    {
        $claims = [
            'sub' => $user->uuid,
            'tenant_id' => $user->tenant?->uuid,
            'tenant_name' => $user->tenant?->name,
        ];

        if (in_array('profile', $scopes)) {
            $claims = array_merge($claims, $this->getProfileClaims($user));
        }

        if (in_array('email', $scopes)) {
            $claims = array_merge($claims, $this->getEmailClaims($user));
        }

        if (in_array('phone', $scopes)) {
            $claims = array_merge($claims, $this->getPhoneClaims($user));
        }

        if (in_array('wallet', $scopes)) {
            $claims = array_merge($claims, $this->getWalletClaims($user));
        }

        if (in_array('kyc', $scopes)) {
            $claims = array_merge($claims, $this->getKycClaims($user));
        }

        return $claims;
    }

    /**
     * Get profile claims.
     */
    protected function getProfileClaims(User $user): array
    {
        return [
            'name' => $user->name,
            'preferred_username' => $user->username,
            'nickname' => $user->display_name,
            'picture' => $user->avatar_url,
            'updated_at' => $user->updated_at?->timestamp,
            'zoneinfo' => $user->timezone,
            'locale' => $user->language,
        ];
    }

    /**
     * Get email claims.
     */
    protected function getEmailClaims(User $user): array
    {
        return [
            'email' => $user->email,
            'email_verified' => $user->email_verified_at !== null,
        ];
    }

    /**
     * Get phone claims.
     */
    protected function getPhoneClaims(User $user): array
    {
        return [
            'phone_number' => $user->phone,
            'phone_number_verified' => $user->phone_verified_at !== null,
        ];
    }

    /**
     * Get wallet claims.
     */
    protected function getWalletClaims(User $user): array
    {
        $wallet = $user->wallet;

        return [
            'wallet_balance' => $wallet?->balance ?? '0.00',
            'wallet_currency' => $wallet?->currency ?? 'NAD',
            'wallet_status' => $wallet?->status ?? null,
        ];
    }

    /**
     * Get KYC claims.
     */
    protected function getKycClaims(User $user): array
    {
        return [
            'kyc_level' => $user->kyc_level ?? 0,
            'kyc_verified' => ($user->kyc_level ?? 0) >= 1,
        ];
    }

    /**
     * Build ID token claims.
     */
    public function buildIdTokenClaims(User $user, Token $token, string $clientId): array
    {
        return [
            'iss' => config('app.url'),
            'sub' => $user->uuid,
            'aud' => $clientId,
            'exp' => $token->expires_at->timestamp,
            'iat' => now()->timestamp,
            'auth_time' => $user->last_login_at?->timestamp ?? now()->timestamp,
        ];
    }

    /**
     * Get OpenID Connect discovery document.
     */
    public function getDiscoveryDocument(): array
    {
        $issuer = config('app.url');

        return [
            'issuer' => $issuer,
            'authorization_endpoint' => $issuer . '/oauth/authorize',
            'token_endpoint' => $issuer . '/oauth/token',
            'userinfo_endpoint' => $issuer . '/api/v1/oauth/userinfo',
            'jwks_uri' => $issuer . '/.well-known/jwks.json',
            'revocation_endpoint' => $issuer . '/oauth/token/revoke',
            'introspection_endpoint' => $issuer . '/oauth/token/introspect',
            'scopes_supported' => [
                'openid',
                'profile',
                'email',
                'phone',
                'wallet',
                'wallet:write',
                'kyc',
                'gaming:history',
            ],
            'response_types_supported' => [
                'code',
                'token',
                'id_token',
                'code token',
                'code id_token',
                'token id_token',
                'code token id_token',
            ],
            'grant_types_supported' => [
                'authorization_code',
                'refresh_token',
                'client_credentials',
                'password',
            ],
            'subject_types_supported' => ['public'],
            'id_token_signing_alg_values_supported' => ['RS256'],
            'token_endpoint_auth_methods_supported' => [
                'client_secret_basic',
                'client_secret_post',
            ],
            'code_challenge_methods_supported' => ['S256', 'plain'],
            'claims_supported' => [
                'sub',
                'name',
                'preferred_username',
                'nickname',
                'picture',
                'email',
                'email_verified',
                'phone_number',
                'phone_number_verified',
                'updated_at',
                'zoneinfo',
                'locale',
            ],
        ];
    }

    /**
     * Get JWKS (JSON Web Key Set).
     */
    public function getJwks(): array
    {
        $publicKey = file_get_contents(storage_path('oauth-public.key'));
        $keyInfo = openssl_pkey_get_details(openssl_pkey_get_public($publicKey));

        return [
            'keys' => [
                [
                    'kty' => 'RSA',
                    'alg' => 'RS256',
                    'use' => 'sig',
                    'n' => rtrim(strtr(base64_encode($keyInfo['rsa']['n']), '+/', '-_'), '='),
                    'e' => rtrim(strtr(base64_encode($keyInfo['rsa']['e']), '+/', '-_'), '='),
                ],
            ],
        ];
    }
}
