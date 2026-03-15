# Chinga Games SSO - Integration Guide

This document provides comprehensive documentation for integrating external applications with the Chinga Games Single Sign-On (SSO) platform.

---

## Table of Contents

1. [Overview](#overview)
2. [Quick Start](#quick-start)
3. [Authentication Flows](#authentication-flows)
4. [OAuth2 Scopes](#oauth2-scopes)
5. [API Reference](#api-reference)
6. [OpenID Connect](#openid-connect)
7. [User Data & Claims](#user-data--claims)
8. [KYC Verification Levels](#kyc-verification-levels)
9. [Responsible Gambling](#responsible-gambling)
10. [Terminal/Venue Integration](#terminalvenue-integration)
11. [Security Best Practices](#security-best-practices)
12. [Error Handling](#error-handling)
13. [SDKs & Examples](#sdks--examples)

---

## Overview

The Chinga Games SSO is a centralized authentication and identity management platform built for gaming applications. It provides:

- **OAuth2/OpenID Connect** compliant authentication
- **KYC (Know Your Customer)** verification with multiple levels
- **Responsible Gambling** controls and self-exclusion
- **Multi-Factor Authentication** (TOTP and SMS)
- **Terminal/Venue** authentication for gaming machines
- **Voucher Code** system for cashless gaming

### Base URLs

| Environment | URL |
|-------------|-----|
| Production | `https://sso.chingagames.com` |
| Staging | `https://sso-staging.chingagames.com` |
| Development | `http://chinga-games-sso.test` |

---

## Quick Start

### 1. Register Your Application

Contact the Chinga Games admin team to register your OAuth client, or use the API if you have admin access:

```bash
POST /api/v1/oauth/clients
Authorization: Bearer {admin_token}
Content-Type: application/json

{
  "name": "My Gaming App",
  "redirect": "https://myapp.com/callback",
  "confidential": true
}
```

You will receive:
- `client_id` - Your application's unique identifier
- `client_secret` - Keep this secure (confidential clients only)

### 2. Implement Authorization Flow

Redirect users to the authorization endpoint:

```
GET /oauth/authorize?
  client_id={client_id}&
  redirect_uri={redirect_uri}&
  response_type=code&
  scope=openid profile email&
  state={random_state}
```

### 3. Exchange Code for Token

After user authorization, exchange the code:

```bash
POST /oauth/token
Content-Type: application/x-www-form-urlencoded

grant_type=authorization_code&
code={authorization_code}&
client_id={client_id}&
client_secret={client_secret}&
redirect_uri={redirect_uri}
```

### 4. Access User Data

Use the access token to fetch user information:

```bash
GET /api/v1/oauth/userinfo
Authorization: Bearer {access_token}
```

---

## Authentication Flows

### Authorization Code Flow (Recommended)

Best for server-side applications with a secure backend.

```
┌──────────┐     ┌──────────┐     ┌──────────┐
│  Client  │────>│   SSO    │────>│   User   │
│   App    │     │  Server  │     │  Login   │
└──────────┘     └──────────┘     └──────────┘
     │                │                │
     │  1. Redirect   │                │
     │───────────────>│                │
     │                │  2. Login Form │
     │                │───────────────>│
     │                │  3. Credentials│
     │                │<───────────────│
     │  4. Auth Code  │                │
     │<───────────────│                │
     │                │                │
     │  5. Exchange   │                │
     │───────────────>│                │
     │  6. Tokens     │                │
     │<───────────────│                │
```

### Authorization Code with PKCE (Public Clients)

Required for mobile apps, SPAs, and other public clients.

```javascript
// 1. Generate code verifier and challenge
const codeVerifier = generateRandomString(128);
const codeChallenge = base64UrlEncode(sha256(codeVerifier));

// 2. Authorization request
const authUrl = `${SSO_URL}/oauth/authorize?
  client_id=${clientId}&
  redirect_uri=${redirectUri}&
  response_type=code&
  scope=openid profile email&
  code_challenge=${codeChallenge}&
  code_challenge_method=S256&
  state=${state}`;

// 3. Token exchange (include code_verifier)
const tokenResponse = await fetch(`${SSO_URL}/oauth/token`, {
  method: 'POST',
  headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
  body: new URLSearchParams({
    grant_type: 'authorization_code',
    code: authorizationCode,
    client_id: clientId,
    redirect_uri: redirectUri,
    code_verifier: codeVerifier
  })
});
```

### Password Grant (Machine-to-Machine)

For trusted first-party applications only.

```bash
POST /oauth/token
Content-Type: application/x-www-form-urlencoded

grant_type=password&
username={email_or_username}&
password={password}&
client_id={client_id}&
client_secret={client_secret}&
scope=openid profile email
```

### Refresh Token

Exchange a refresh token for a new access token:

```bash
POST /oauth/token
Content-Type: application/x-www-form-urlencoded

grant_type=refresh_token&
refresh_token={refresh_token}&
client_id={client_id}&
client_secret={client_secret}
```

### Client Credentials (Server-to-Server)

For backend services without user context:

```bash
POST /oauth/token
Content-Type: application/x-www-form-urlencoded

grant_type=client_credentials&
client_id={client_id}&
client_secret={client_secret}&
scope=admin
```

---

## OAuth2 Scopes

Request only the scopes your application needs.

| Scope | Description | Claims/Access Granted |
|-------|-------------|----------------------|
| `openid` | OpenID Connect identity | `sub` (user UUID) |
| `profile` | Basic profile information | `name`, `preferred_username`, `nickname`, `picture`, `zoneinfo`, `locale` |
| `email` | Email address | `email`, `email_verified` |
| `phone` | Phone number | `phone_number`, `phone_number_verified` |
| `kyc` | KYC verification status | `kyc_level`, `kyc_verified` |
| `wallet` | Read wallet balance | Balance read access |
| `wallet:write` | Wallet transactions | Deposit/withdraw capabilities |
| `gaming:history` | Gaming history | Transaction and session history |
| `admin` | Administrative access | Admin API endpoints |

### Default Scopes

If no scopes are specified, the following are granted by default:
- `openid`
- `profile`
- `email`

### Example Scope Combinations

**Gaming Platform:**
```
scope=openid profile email kyc gaming:history
```

**Wallet Application:**
```
scope=openid profile wallet wallet:write
```

**Basic Profile:**
```
scope=openid profile email phone
```

---

## API Reference

### Token Information

| Property | Value |
|----------|-------|
| Access Token Lifetime | 1 hour |
| Refresh Token Lifetime | 30 days |
| Personal Access Token Lifetime | 6 months |

### Authentication Header

All authenticated requests require:

```
Authorization: Bearer {access_token}
```

### User Endpoints

#### Get Current User

```http
GET /api/v1/user
Authorization: Bearer {access_token}
```

**Response:**
```json
{
  "uuid": "550e8400-e29b-41d4-a716-446655440000",
  "name": "John Doe",
  "email": "john@example.com",
  "username": "johndoe",
  "phone": "+264811234567",
  "email_verified_at": "2024-01-15T10:30:00Z",
  "phone_verified_at": "2024-01-15T10:35:00Z",
  "date_of_birth": "1990-05-15",
  "country_code": "NA",
  "display_name": "JohnD",
  "avatar_url": "https://example.com/avatar.jpg",
  "timezone": "Africa/Windhoek",
  "language": "en",
  "status": "active",
  "kyc_level": 2,
  "kyc_verified_at": "2024-01-20T14:00:00Z",
  "created_at": "2024-01-15T10:00:00Z"
}
```

#### Get UserInfo (OpenID Connect)

```http
GET /api/v1/oauth/userinfo
Authorization: Bearer {access_token}
```

Returns claims based on granted scopes.

### KYC Endpoints

#### Get KYC Status

```http
GET /api/v1/kyc/status
Authorization: Bearer {access_token}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "current_level": 1,
    "level_name": "Basic",
    "verified_at": "2024-01-20T14:00:00Z",
    "limits": {
      "daily_deposit": 1000,
      "monthly_deposit": 5000,
      "max_withdrawal": 2000
    },
    "can_upgrade": true,
    "next_level_requirements": [
      "identity_document"
    ]
  }
}
```

#### Get KYC Requirements

```http
GET /api/v1/kyc/requirements
Authorization: Bearer {access_token}
```

#### Upload KYC Document

```http
POST /api/v1/kyc/documents
Authorization: Bearer {access_token}
Content-Type: multipart/form-data

document_type: passport|national_id|drivers_license|proof_of_address|selfie|source_of_funds
document_file: {file}
document_number: ABC123456 (optional)
expiry_date: 2030-01-01 (optional)
```

### Responsible Gambling Endpoints

#### Get Current Settings

```http
GET /api/v1/responsible-gambling
Authorization: Bearer {access_token}
```

#### Update Deposit Limits

```http
PUT /api/v1/responsible-gambling/deposit-limits
Authorization: Bearer {access_token}
Content-Type: application/json

{
  "daily_limit": 500,
  "weekly_limit": 2000,
  "monthly_limit": 5000
}
```

#### Create Self-Exclusion

```http
POST /api/v1/responsible-gambling/self-exclude
Authorization: Bearer {access_token}
Content-Type: application/json

{
  "duration": "7_days",
  "reason": "Taking a break"
}
```

Duration options: `24_hours`, `7_days`, `30_days`, `90_days`, `permanent`

---

## OpenID Connect

### Discovery Endpoint

```http
GET /.well-known/openid-configuration
```

**Response:**
```json
{
  "issuer": "https://sso.chingagames.com",
  "authorization_endpoint": "https://sso.chingagames.com/oauth/authorize",
  "token_endpoint": "https://sso.chingagames.com/oauth/token",
  "userinfo_endpoint": "https://sso.chingagames.com/api/v1/oauth/userinfo",
  "jwks_uri": "https://sso.chingagames.com/.well-known/jwks.json",
  "revocation_endpoint": "https://sso.chingagames.com/oauth/token/revoke",
  "introspection_endpoint": "https://sso.chingagames.com/oauth/token/introspect",
  "scopes_supported": ["openid", "profile", "email", "phone", "kyc", "wallet", "wallet:write", "gaming:history", "admin"],
  "response_types_supported": ["code", "token", "id_token", "code token", "code id_token", "token id_token", "code token id_token"],
  "code_challenge_methods_supported": ["S256", "plain"],
  "token_endpoint_auth_methods_supported": ["client_secret_basic", "client_secret_post"],
  "id_token_signing_alg_values_supported": ["RS256"],
  "claims_supported": ["sub", "name", "email", "email_verified", "phone_number", "phone_number_verified", "preferred_username", "nickname", "picture", "zoneinfo", "locale", "kyc_level", "kyc_verified"]
}
```

### JWKS Endpoint

```http
GET /.well-known/jwks.json
```

Use this to validate ID tokens signed with RS256.

### ID Token Validation

```javascript
const jwt = require('jsonwebtoken');
const jwksClient = require('jwks-rsa');

const client = jwksClient({
  jwksUri: 'https://sso.chingagames.com/.well-known/jwks.json'
});

function getKey(header, callback) {
  client.getSigningKey(header.kid, (err, key) => {
    callback(null, key.getPublicKey());
  });
}

jwt.verify(idToken, getKey, {
  issuer: 'https://sso.chingagames.com',
  audience: clientId,
  algorithms: ['RS256']
}, (err, decoded) => {
  if (err) {
    console.error('Token validation failed:', err);
  } else {
    console.log('Valid token:', decoded);
  }
});
```

---

## User Data & Claims

### Standard Claims by Scope

#### openid (Always included)
```json
{
  "sub": "550e8400-e29b-41d4-a716-446655440000"
}
```

#### profile
```json
{
  "name": "John Doe",
  "preferred_username": "johndoe",
  "nickname": "JohnD",
  "picture": "https://example.com/avatar.jpg",
  "zoneinfo": "Africa/Windhoek",
  "locale": "en",
  "updated_at": 1705320000
}
```

#### email
```json
{
  "email": "john@example.com",
  "email_verified": true
}
```

#### phone
```json
{
  "phone_number": "+264811234567",
  "phone_number_verified": true
}
```

#### kyc
```json
{
  "kyc_level": 2,
  "kyc_verified": true
}
```

---

## KYC Verification Levels

### Level Overview

| Level | Name | Requirements | Daily Deposit | Monthly Deposit | Max Withdrawal |
|-------|------|--------------|---------------|-----------------|----------------|
| 0 | Unverified | Email only | N$0 | N$0 | N$0 |
| 1 | Basic | Email + Phone + DOB | N$1,000 | N$5,000 | N$2,000 |
| 2 | Enhanced | + Identity Document | N$10,000 | N$50,000 | N$25,000 |
| 3 | Full | + Address + Source of Funds | Unlimited | Unlimited | Unlimited |

### Accepted Documents

| Document Type | Accepted For |
|---------------|--------------|
| `passport` | Level 2+ identity verification |
| `national_id` | Level 2+ identity verification |
| `drivers_license` | Level 2+ identity verification |
| `proof_of_address` | Level 3 address verification |
| `selfie` | Identity confirmation |
| `source_of_funds` | Level 3 wealth verification |

### Document Requirements

- **File Size:** Maximum 10MB
- **Formats:** JPEG, PNG, WebP, PDF
- **Address Documents:** Must be less than 3 months old
- **Identity Documents:** Must not be expired

### Checking KYC in Your Application

```javascript
// Get user's KYC level from token or API
const user = await getUserInfo(accessToken);

if (user.kyc_level < 1) {
  // Redirect to KYC verification
  redirectToKycVerification();
} else if (user.kyc_level < 2 && depositAmount > 1000) {
  // Prompt for enhanced verification
  showEnhancedKycPrompt();
}
```

---

## Responsible Gambling

### Available Controls

1. **Deposit Limits** - Daily, weekly, monthly caps
2. **Session Limits** - Time and loss limits per session
3. **Login Restrictions** - Blocked time periods
4. **Reality Check** - Periodic notifications
5. **Self-Exclusion** - Temporary or permanent account suspension

### Checking Player Eligibility

```http
GET /api/v1/responsible-gambling
Authorization: Bearer {access_token}
```

**Response includes:**
```json
{
  "can_play": true,
  "active_limits": {
    "daily_deposit_remaining": 500,
    "session_time_remaining": 3600
  },
  "self_exclusion": null,
  "restrictions": {
    "blocked_hours": ["02:00-06:00"]
  }
}
```

### Enforcement

Your application should:

1. Check `can_play` before allowing gaming sessions
2. Enforce deposit limits client-side and server-side
3. Display reality check notifications at configured intervals
4. Respect self-exclusion status (HTTP 403 returned for excluded users)

---

## Terminal/Venue Integration

For gaming terminals and venue systems.

### Terminal Authentication

Terminals authenticate using API keys instead of OAuth.

```http
POST /api/v1/terminal/auth
X-Terminal-Key: {api_key}
Content-Type: application/json

{
  "terminal_id": "terminal-uuid",
  "venue_id": "venue-uuid"
}
```

### Terminal Heartbeat

Keep terminal session alive:

```http
POST /api/v1/terminal/heartbeat
X-Terminal-Key: {api_key}
```

### Voucher Code Authentication

Authenticate a player's voucher code:

```http
POST /api/v1/venue/auth/code
X-Terminal-Key: {api_key}
Content-Type: application/json

{
  "code": "ABC-1234-5678-9012",
  "pin": "1234"
}
```

**Response:**
```json
{
  "success": true,
  "session_token": "session-jwt-token",
  "player": {
    "balance": 500.00,
    "currency": "NAD",
    "can_play": true
  }
}
```

### Game Operations

All game operations require the session token:

```http
# Check Balance
GET /api/v1/venue/player/balance
Authorization: Bearer {session_token}

# Debit (Player Bet)
POST /api/v1/venue/player/debit
Authorization: Bearer {session_token}
Content-Type: application/json

{
  "amount": 10.00,
  "game_id": "slot-machine-1",
  "transaction_ref": "unique-ref-123"
}

# Credit (Player Win)
POST /api/v1/venue/player/credit
Authorization: Bearer {session_token}
Content-Type: application/json

{
  "amount": 25.00,
  "game_id": "slot-machine-1",
  "transaction_ref": "unique-ref-124"
}
```

---

## Security Best Practices

### Token Storage

| Client Type | Recommended Storage |
|-------------|---------------------|
| Web (Server-side) | Encrypted session/database |
| Web (SPA) | Memory only, use refresh tokens |
| Mobile | Secure keychain/keystore |
| Desktop | OS credential manager |

### PKCE Requirements

- **Always use PKCE** for public clients (mobile, SPA)
- Use `S256` challenge method (not `plain`)
- Generate cryptographically random code verifier (min 43 chars)

### Token Refresh Strategy

```javascript
// Refresh token 5 minutes before expiry
const tokenExpiresAt = decoded.exp * 1000;
const refreshAt = tokenExpiresAt - (5 * 60 * 1000);

setTimeout(async () => {
  const newTokens = await refreshAccessToken(refreshToken);
  updateStoredTokens(newTokens);
}, refreshAt - Date.now());
```

### Rate Limiting

| Endpoint | Limit |
|----------|-------|
| `/oauth/token` | 60 requests/minute |
| `/oauth/authorize` | 30 requests/minute |
| `/api/v1/*` | 120 requests/minute |
| Login attempts | 5/minute per email |

### Logout/Revocation

Always revoke tokens on logout:

```http
POST /oauth/token/revoke
Content-Type: application/x-www-form-urlencoded

token={access_token}&
token_type_hint=access_token&
client_id={client_id}&
client_secret={client_secret}
```

---

## Error Handling

### OAuth2 Errors

| Error | Description |
|-------|-------------|
| `invalid_request` | Missing or invalid parameter |
| `invalid_client` | Client authentication failed |
| `invalid_grant` | Invalid authorization code or refresh token |
| `unauthorized_client` | Client not authorized for this grant type |
| `unsupported_grant_type` | Grant type not supported |
| `invalid_scope` | Requested scope is invalid |

### API Errors

```json
{
  "success": false,
  "message": "Validation failed",
  "errors": {
    "email": ["The email field is required."]
  }
}
```

### HTTP Status Codes

| Code | Meaning |
|------|---------|
| 200 | Success |
| 201 | Created |
| 400 | Bad Request |
| 401 | Unauthorized (invalid/missing token) |
| 403 | Forbidden (insufficient permissions or self-excluded) |
| 404 | Not Found |
| 422 | Validation Error |
| 429 | Rate Limited |
| 500 | Server Error |

---

## SDKs & Examples

### JavaScript/TypeScript

```typescript
class ChingaSSO {
  private baseUrl: string;
  private clientId: string;
  private clientSecret?: string;

  constructor(config: { baseUrl: string; clientId: string; clientSecret?: string }) {
    this.baseUrl = config.baseUrl;
    this.clientId = config.clientId;
    this.clientSecret = config.clientSecret;
  }

  getAuthorizationUrl(params: {
    redirectUri: string;
    scope?: string;
    state?: string;
    codeChallenge?: string;
  }): string {
    const url = new URL(`${this.baseUrl}/oauth/authorize`);
    url.searchParams.set('client_id', this.clientId);
    url.searchParams.set('redirect_uri', params.redirectUri);
    url.searchParams.set('response_type', 'code');
    url.searchParams.set('scope', params.scope || 'openid profile email');
    if (params.state) url.searchParams.set('state', params.state);
    if (params.codeChallenge) {
      url.searchParams.set('code_challenge', params.codeChallenge);
      url.searchParams.set('code_challenge_method', 'S256');
    }
    return url.toString();
  }

  async exchangeCode(code: string, redirectUri: string, codeVerifier?: string): Promise<TokenResponse> {
    const body = new URLSearchParams({
      grant_type: 'authorization_code',
      code,
      client_id: this.clientId,
      redirect_uri: redirectUri,
    });

    if (this.clientSecret) body.set('client_secret', this.clientSecret);
    if (codeVerifier) body.set('code_verifier', codeVerifier);

    const response = await fetch(`${this.baseUrl}/oauth/token`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body,
    });

    return response.json();
  }

  async getUserInfo(accessToken: string): Promise<UserInfo> {
    const response = await fetch(`${this.baseUrl}/api/v1/oauth/userinfo`, {
      headers: { Authorization: `Bearer ${accessToken}` },
    });
    return response.json();
  }
}
```

### PHP (Laravel)

```php
// config/services.php
'chinga' => [
    'client_id' => env('CHINGA_CLIENT_ID'),
    'client_secret' => env('CHINGA_CLIENT_SECRET'),
    'redirect' => env('CHINGA_REDIRECT_URI'),
    'base_url' => env('CHINGA_SSO_URL', 'https://sso.chingagames.com'),
],

// app/Services/ChingaSSO.php
class ChingaSSO
{
    public function getAuthorizationUrl(string $state): string
    {
        return config('services.chinga.base_url') . '/oauth/authorize?' . http_build_query([
            'client_id' => config('services.chinga.client_id'),
            'redirect_uri' => config('services.chinga.redirect'),
            'response_type' => 'code',
            'scope' => 'openid profile email kyc',
            'state' => $state,
        ]);
    }

    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->post(config('services.chinga.base_url') . '/oauth/token', [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => config('services.chinga.client_id'),
            'client_secret' => config('services.chinga.client_secret'),
            'redirect_uri' => config('services.chinga.redirect'),
        ]);

        return $response->json();
    }

    public function getUserInfo(string $accessToken): array
    {
        $response = Http::withToken($accessToken)
            ->get(config('services.chinga.base_url') . '/api/v1/oauth/userinfo');

        return $response->json();
    }
}
```

### Python

```python
import requests
from urllib.parse import urlencode

class ChingaSSO:
    def __init__(self, client_id: str, client_secret: str = None, base_url: str = "https://sso.chingagames.com"):
        self.client_id = client_id
        self.client_secret = client_secret
        self.base_url = base_url

    def get_authorization_url(self, redirect_uri: str, scope: str = "openid profile email", state: str = None) -> str:
        params = {
            "client_id": self.client_id,
            "redirect_uri": redirect_uri,
            "response_type": "code",
            "scope": scope,
        }
        if state:
            params["state"] = state
        return f"{self.base_url}/oauth/authorize?{urlencode(params)}"

    def exchange_code(self, code: str, redirect_uri: str) -> dict:
        data = {
            "grant_type": "authorization_code",
            "code": code,
            "client_id": self.client_id,
            "redirect_uri": redirect_uri,
        }
        if self.client_secret:
            data["client_secret"] = self.client_secret

        response = requests.post(f"{self.base_url}/oauth/token", data=data)
        return response.json()

    def get_user_info(self, access_token: str) -> dict:
        headers = {"Authorization": f"Bearer {access_token}"}
        response = requests.get(f"{self.base_url}/api/v1/oauth/userinfo", headers=headers)
        return response.json()
```

---

## Support

For technical support or to register your application:

- **Email:** developers@chingagames.com
- **Documentation:** https://docs.chingagames.com
- **Status Page:** https://status.chingagames.com

---

## Changelog

| Version | Date | Changes |
|---------|------|---------|
| 1.0.0 | 2024-01-01 | Initial release |
| 1.1.0 | 2024-06-01 | Added PKCE support |
| 1.2.0 | 2024-09-01 | Added Responsible Gambling APIs |

---

*Last updated: January 2025*
