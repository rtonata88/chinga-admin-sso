# Fantasy Frontend Login Page Design

## Overview

Add a `/login` page to the Chinga Fantasy frontend with three authentication modes: account registration, account login, and voucher login. The page is the entry point for both online players (with SSO accounts) and in-store/anonymous players (with voucher codes).

## Authentication Modes

### 1. Register (New Account)

**Fields:** name, email, username, password, confirm password, date of birth

**Flow:**
1. Player fills out registration form
2. Frontend calls new SSO endpoint `POST /api/v1/register` with fields + tenant UUID from subdomain
3. SSO creates user via existing `CreateNewUser` action (already handles validation, age gate, tenant scoping, role assignment)
4. On success, SSO returns a Passport access token + refresh token (auto-login)
5. Frontend stores tokens and redirects to `/`

**Validation (handled by SSO):**
- Email unique within tenant
- Username unique within tenant
- Password strength rules
- Age 18+ (date of birth check)

### 2. Login with Account

**Fields:** email or username, password

**Flow:**
1. Player enters credentials
2. Frontend calls SSO `POST /oauth/token` with `grant_type=password`, `client_id` (public PKCE client), email/password
3. SSO validates credentials (existing Fortify auth callback handles lockout, suspension, tenant scoping)
4. On success, returns access token + refresh token
5. Frontend stores tokens, fetches user info from `/api/v1/oauth/userinfo`, redirects to `/`

**Error states:** invalid credentials, account locked, account suspended

### 3. Login with Voucher

**Fields:** voucher code, PIN (optional — shown only if voucher has a PIN)

**Flow:**
1. Player enters voucher code (and PIN if required)
2. Frontend calls new SSO endpoint `POST /api/v1/game/session/start/voucher-web` with `{ game_id, code, pin }` + tenant UUID from `X-Tenant-ID` header
3. SSO validates: voucher exists in tenant, voucher is active/usable, PIN correct (if set), game enabled for tenant
4. SSO creates a GameSession with `source_type=VoucherCode`, no terminal — sets `terminal_id=null`
5. Returns `{ session_token, balance, currency }`
6. Frontend stores session token. Player is "logged in" anonymously with voucher balance.
7. Redirect to `/`

**Key difference from terminal flow:** No `X-Terminal-Key` header. Voucher is scoped by tenant UUID (from subdomain) instead of terminal/venue.

## SSO Changes

### New Endpoint: `POST /api/v1/register`

A JSON API endpoint that wraps the existing `CreateNewUser` Fortify action.

**Request:**
```json
{
  "name": "John Doe",
  "email": "john@example.com",
  "username": "johndoe",
  "password": "securepassword",
  "password_confirmation": "securepassword",
  "date_of_birth": "1990-01-15"
}
```

Tenant resolved from `X-Tenant-ID` header or subdomain (existing `ResolveTenant` middleware).

**Response (success):**
```json
{
  "user": { "uuid": "...", "name": "...", "email": "...", "username": "..." },
  "access_token": "...",
  "refresh_token": "...",
  "expires_in": 3600
}
```

**Response (validation error):** 422 with field errors.

### New Endpoint: `POST /api/v1/game/session/start/voucher-web`

Starts a voucher-backed game session without requiring a terminal API key. Voucher is scoped by tenant.

**Request:**
```json
{
  "game_id": "game-uuid",
  "code": "ABC123",
  "pin": "1234"
}
```

Tenant resolved from `X-Tenant-ID` header.

**Response (success):**
```json
{
  "session_token": "gs_...",
  "balance": "500.00",
  "currency": "NAD",
  "game": { "uuid": "...", "name": "...", "slug": "..." }
}
```

**Validation:**
- Voucher must exist within the resolved tenant
- Voucher must be active and have balance (`canBeUsed()`)
- PIN must match if voucher has one
- Game must be active and enabled for tenant
- No existing active session for this voucher

### Route Registration

Both endpoints go in `routes/game.php` (voucher-web) and `routes/api.php` (register):
- `POST /api/v1/register` — no auth required, rate limited
- `POST /api/v1/game/session/start/voucher-web` — no auth required (voucher code is the credential), rate limited, tenant context required

## Frontend Changes

### New Page: `/login` (`pages/login.tsx`)

Replace the OAuth2 PKCE redirect flow with a direct login page. Three tabs:

- **Login** tab (default) — email/username + password form
- **Register** tab — full registration form
- **Voucher** tab — code + PIN form

### Auth State Updates

The `SSOAuthContext` needs to handle two auth modes:

1. **Account mode** — has access token, refresh token, user info. Same as current.
2. **Voucher mode** — has only a game session token (`gs_*`) and balance. No user identity.

Add a `sessionMode` field: `'account' | 'voucher' | null`

For voucher mode, the frontend:
- Stores the `gs_*` token for game API calls (debit/credit via game server)
- Displays voucher balance instead of wallet balance
- Hides account-specific UI (profile, etc.)
- Game server receives the `gs_*` token directly (no SSO access token needed)

### Updated App.tsx

- Remove OAuth callback route (`/oauth/callback`)
- Add `/login` route pointing to new login page
- Keep the auth guard that redirects unauthenticated users to `/login`

### Remove OAuth2 PKCE Flow

The `ssoAuth.ts` PKCE utilities and `oauth-callback.tsx` page are no longer needed since login happens directly on the frontend. Remove:
- `lib/ssoAuth.ts` — replace with direct API calls in the auth context
- `pages/oauth-callback.tsx` — no longer needed
- PKCE-related env vars (`VITE_SSO_REDIRECT_URI`)

Keep `VITE_SSO_BASE_URL` and `VITE_SSO_CLIENT_ID` for the password grant calls.

## Game Server Changes

The Fantasy game server's `ssoAuth.js` middleware currently expects SSO JWT tokens. For voucher sessions, the frontend sends the `gs_*` token directly to the game server. The game server needs to handle both:

1. **SSO token** (account login) — validate via JWKS as current
2. **Game session token** (voucher login) — pass through to SSO Game Session API directly

Update `ssoAuth.js` to detect `gs_*` prefix tokens and set `req.ssoUser` accordingly (with limited info — no user UUID, just session token).
