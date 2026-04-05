# Chinga Fantasy Integration — Plan A: Foundation

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace Chinga Fantasy's local auth and tenant systems with SSO OAuth2 and SSO tenant UUIDs, establishing the foundation for wallet integration (Plan B).

**Architecture:** The Fantasy frontend authenticates via OAuth2 PKCE against the SSO. The Fantasy game server validates SSO access tokens via JWKS. Tenant identity comes from the SSO tenant UUID embedded in the token. The Fantasy game server exposes a new SSO client service for calling SSO APIs.

**Tech Stack:** Laravel Passport (SSO), Node.js/Express (game server), React/Vite (frontend), jose (JWKS verification), OAuth2 PKCE

**Spec:** `docs/superpowers/specs/2026-04-05-chinga-fantasy-integration-design.md`

**Depends on:** Nothing (this is the first plan)

**Projects involved:**
- SSO: `/Users/richard/Projects/chinga-games-sso`
- Fantasy Game Server: `/Users/richard/Projects/chinga-fantasy`
- Fantasy Frontend: `/Users/richard/Projects/gambling`

---

## File Structure

### SSO (Laravel) — New/Modified Files
| Action | File | Purpose |
|--------|------|---------|
| Create | `database/seeders/ChingaFantasyGameSeeder.php` | Seed the "Chinga Fantasy" game record + OAuth clients |
| Modify | `database/seeders/DatabaseSeeder.php` | Call the new seeder |

### Fantasy Game Server (Node.js) — New/Modified Files
| Action | File | Purpose |
|--------|------|---------|
| Create | `app/middlewares/ssoAuth.js` | Validate SSO access tokens via JWKS |
| Create | `app/middlewares/ssoTenant.js` | Extract tenant UUID from validated token |
| Create | `app/services/ssoClient.js` | HTTP client for SSO Game Session API and config API |
| Create | `config/sso.js` | SSO configuration (URLs, client credentials) |
| Modify | `.env` | Add SSO environment variables |
| Modify | `app/routes/api.js` | Replace checkAuth with ssoAuth, remove local auth routes |
| Modify | `index.js` | Replace tenantResolver with ssoTenant middleware |
| Modify | `package.json` | Add jose dependency |

### Fantasy Frontend (React) — New/Modified Files
| Action | File | Purpose |
|--------|------|---------|
| Create | `client/src/lib/ssoAuth.ts` | OAuth2 PKCE flow utilities |
| Create | `client/src/context/SSOAuthContext.tsx` | Auth state with SSO tokens |
| Create | `client/src/pages/oauth-callback.tsx` | Handle OAuth2 redirect from SSO |
| Modify | `client/src/App.tsx` | Replace AuthProvider with SSOAuthProvider, add callback route |
| Modify | `client/src/lib/queryClient.ts` | Use SSO access token in requests |
| Modify | `client/src/services/socketService.ts` | Pass SSO token for socket auth |
| Modify | `client/.env` | Add SSO environment variables |

---

## Task 1: Seed Chinga Fantasy Game + OAuth Clients in SSO

**Files:**
- Create: `/Users/richard/Projects/chinga-games-sso/database/seeders/ChingaFantasyGameSeeder.php`
- Modify: `/Users/richard/Projects/chinga-games-sso/database/seeders/DatabaseSeeder.php`

- [ ] **Step 1: Create the seeder**

```php
<?php
// database/seeders/ChingaFantasyGameSeeder.php

namespace Database\Seeders;

use App\Models\Game;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\ClientRepository;

class ChingaFantasyGameSeeder extends Seeder
{
    public function run(): void
    {
        // Create the Chinga Fantasy game record
        $game = Game::firstOrCreate(
            ['slug' => 'chinga-fantasy'],
            [
                'name' => 'Chinga Fantasy',
                'description' => 'Fantasy sports betting game with team selection and round-based gameplay.',
                'type' => 'other',
                'status' => 'active',
                'version' => '1.0.0',
                'settings' => [
                    'min_bet_amount' => '5.00',
                    'max_bet_amount' => '50.00',
                    'display_teams' => 28,
                    'round_betting_seconds' => 30,
                    'round_results_seconds' => 30,
                    'round_dialog_seconds' => 30,
                    'min_jackpot_amount' => '100.00',
                    'jackpot_percentage' => 5,
                ],
            ]
        );

        $this->command->info("Game '{$game->name}' created/found with UUID: {$game->uuid}");

        // Create OAuth client for Fantasy Frontend (public client, PKCE)
        $clientRepository = app(ClientRepository::class);

        $frontendClient = DB::table('oauth_clients')
            ->where('name', 'Chinga Fantasy Frontend')
            ->first();

        if (!$frontendClient) {
            $frontendClient = $clientRepository->create(
                null, // no user
                'Chinga Fantasy Frontend',
                'http://localhost:5173/oauth/callback', // redirect URI
            );
            // Mark as public client (no secret required for PKCE)
            DB::table('oauth_clients')
                ->where('id', $frontendClient->id)
                ->update(['secret' => null, 'personal_access_client' => false, 'password_client' => false]);

            $this->command->info("Frontend OAuth client created. Client ID: {$frontendClient->id}");
        } else {
            $this->command->info("Frontend OAuth client already exists. Client ID: {$frontendClient->id}");
        }

        // Create OAuth client for Fantasy Game Server (confidential, client credentials)
        $serverClient = DB::table('oauth_clients')
            ->where('name', 'Chinga Fantasy Game Server')
            ->first();

        if (!$serverClient) {
            $serverClient = $clientRepository->create(
                null,
                'Chinga Fantasy Game Server',
                '', // no redirect for client credentials
            );
            DB::table('oauth_clients')
                ->where('id', $serverClient->id)
                ->update(['personal_access_client' => false, 'password_client' => false]);

            $this->command->info("Server OAuth client created. Client ID: {$serverClient->id}, Secret: {$serverClient->plainSecret}");
            $this->command->warn("⚠ Save the server client secret now — it cannot be retrieved later.");
        } else {
            $this->command->info("Server OAuth client already exists. Client ID: {$serverClient->id}");
        }
    }
}
```

- [ ] **Step 2: Register seeder in DatabaseSeeder**

Add to `/Users/richard/Projects/chinga-games-sso/database/seeders/DatabaseSeeder.php` in the `run()` method:

```php
$this->call(ChingaFantasyGameSeeder::class);
```

- [ ] **Step 3: Run the seeder**

```bash
cd /Users/richard/Projects/chinga-games-sso
php artisan db:seed --class=ChingaFantasyGameSeeder
```

Expected: Output showing game UUID, frontend client ID, and server client ID + secret. **Record these values** — they're needed for Fantasy `.env` files.

- [ ] **Step 4: Commit**

```bash
cd /Users/richard/Projects/chinga-games-sso
git add database/seeders/ChingaFantasyGameSeeder.php database/seeders/DatabaseSeeder.php
git commit -m "feat: add Chinga Fantasy game seeder with OAuth clients"
```

---

## Task 2: SSO Config + Client Service in Fantasy Game Server

**Files:**
- Create: `/Users/richard/Projects/chinga-fantasy/config/sso.js`
- Create: `/Users/richard/Projects/chinga-fantasy/app/services/ssoClient.js`
- Modify: `/Users/richard/Projects/chinga-fantasy/.env`
- Modify: `/Users/richard/Projects/chinga-fantasy/package.json`

- [ ] **Step 1: Add jose dependency**

```bash
cd /Users/richard/Projects/chinga-fantasy
npm install jose
```

- [ ] **Step 2: Add SSO env vars**

Append to `/Users/richard/Projects/chinga-fantasy/.env`:

```
# SSO Integration
SSO_BASE_URL=http://chinga-games-sso.test
SSO_JWKS_URL=http://chinga-games-sso.test/.well-known/jwks.json
SSO_CLIENT_ID=<server-client-id-from-seeder>
SSO_CLIENT_SECRET=<server-client-secret-from-seeder>
SSO_GAME_UUID=<game-uuid-from-seeder>
```

Replace placeholders with values from Task 1 Step 3.

- [ ] **Step 3: Create SSO config file**

```javascript
// config/sso.js
require('dotenv').config();

module.exports = {
  baseUrl: process.env.SSO_BASE_URL || 'http://chinga-games-sso.test',
  jwksUrl: process.env.SSO_JWKS_URL || 'http://chinga-games-sso.test/.well-known/jwks.json',
  clientId: process.env.SSO_CLIENT_ID,
  clientSecret: process.env.SSO_CLIENT_SECRET,
  gameUuid: process.env.SSO_GAME_UUID,
};
```

- [ ] **Step 4: Create SSO client service**

```javascript
// app/services/ssoClient.js
const ssoConfig = require('../../config/sso');

let cachedToken = null;
let tokenExpiresAt = 0;

/**
 * Get a client credentials access token for server-to-server calls.
 */
async function getClientToken() {
  if (cachedToken && Date.now() < tokenExpiresAt - 60000) {
    return cachedToken;
  }

  const response = await fetch(`${ssoConfig.baseUrl}/oauth/token`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      grant_type: 'client_credentials',
      client_id: ssoConfig.clientId,
      client_secret: ssoConfig.clientSecret,
      scope: 'wallet wallet:write gaming:history',
    }),
  });

  if (!response.ok) {
    const error = await response.text();
    throw new Error(`SSO token request failed: ${response.status} ${error}`);
  }

  const data = await response.json();
  cachedToken = data.access_token;
  tokenExpiresAt = Date.now() + data.expires_in * 1000;
  return cachedToken;
}

/**
 * Start a wallet game session for an online player.
 * @param {string} userAccessToken - The player's SSO OAuth access token
 */
async function startWalletSession(userAccessToken) {
  const response = await fetch(`${ssoConfig.baseUrl}/api/v1/game/session/start/wallet`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${userAccessToken}`,
    },
    body: JSON.stringify({ game_id: ssoConfig.gameUuid }),
  });

  if (!response.ok) {
    const error = await response.json().catch(() => ({ message: 'Unknown error' }));
    throw new Error(error.message || `Failed to start wallet session: ${response.status}`);
  }

  return response.json();
}

/**
 * Start a terminal/voucher game session.
 * @param {string} terminalApiKey - The terminal's API key
 * @param {string} code - Voucher code
 * @param {string|null} pin - Optional PIN
 */
async function startTerminalSession(terminalApiKey, code, pin) {
  const response = await fetch(`${ssoConfig.baseUrl}/api/v1/game/session/start/terminal`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-Terminal-Key': terminalApiKey,
    },
    body: JSON.stringify({
      game_id: ssoConfig.gameUuid,
      code,
      pin: pin || undefined,
    }),
  });

  if (!response.ok) {
    const error = await response.json().catch(() => ({ message: 'Unknown error' }));
    throw new Error(error.message || `Failed to start terminal session: ${response.status}`);
  }

  return response.json();
}

/**
 * Debit (bet) from a game session.
 * @param {string} sessionToken - The gs_* session token
 * @param {string} amount - Bet amount
 * @param {string} reference - Unique reference for idempotency
 */
async function debit(sessionToken, amount, reference) {
  const response = await fetch(`${ssoConfig.baseUrl}/api/v1/game/debit`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${sessionToken}`,
    },
    body: JSON.stringify({ amount, reference }),
  });

  if (!response.ok) {
    const error = await response.json().catch(() => ({ message: 'Unknown error' }));
    throw new Error(error.message || `Debit failed: ${response.status}`);
  }

  return response.json();
}

/**
 * Credit (win) to a game session.
 * @param {string} sessionToken - The gs_* session token
 * @param {string} amount - Win amount
 * @param {string} reference - Unique reference for idempotency
 */
async function credit(sessionToken, amount, reference) {
  const response = await fetch(`${ssoConfig.baseUrl}/api/v1/game/credit`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${sessionToken}`,
    },
    body: JSON.stringify({ amount, reference }),
  });

  if (!response.ok) {
    const error = await response.json().catch(() => ({ message: 'Unknown error' }));
    throw new Error(error.message || `Credit failed: ${response.status}`);
  }

  return response.json();
}

/**
 * Get balance for a game session.
 * @param {string} sessionToken - The gs_* session token
 */
async function getBalance(sessionToken) {
  const response = await fetch(`${ssoConfig.baseUrl}/api/v1/game/balance`, {
    headers: {
      'Authorization': `Bearer ${sessionToken}`,
    },
  });

  if (!response.ok) {
    const error = await response.json().catch(() => ({ message: 'Unknown error' }));
    throw new Error(error.message || `Balance check failed: ${response.status}`);
  }

  return response.json();
}

/**
 * End a game session.
 * @param {string} sessionToken - The gs_* session token
 * @param {string} reason - End reason: logout, timeout, cashed_out
 */
async function endSession(sessionToken, reason = 'logout') {
  const response = await fetch(`${ssoConfig.baseUrl}/api/v1/game/session/end`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${sessionToken}`,
    },
    body: JSON.stringify({ reason }),
  });

  if (!response.ok) {
    const error = await response.json().catch(() => ({ message: 'Unknown error' }));
    throw new Error(error.message || `End session failed: ${response.status}`);
  }

  return response.json();
}

module.exports = {
  getClientToken,
  startWalletSession,
  startTerminalSession,
  debit,
  credit,
  getBalance,
  endSession,
};
```

- [ ] **Step 5: Commit**

```bash
cd /Users/richard/Projects/chinga-fantasy
git add config/sso.js app/services/ssoClient.js package.json package-lock.json .env
git commit -m "feat: add SSO config and client service for game session API"
```

---

## Task 3: SSO Token Validation Middleware in Fantasy Game Server

**Files:**
- Create: `/Users/richard/Projects/chinga-fantasy/app/middlewares/ssoAuth.js`

- [ ] **Step 1: Create the JWKS-based auth middleware**

```javascript
// app/middlewares/ssoAuth.js
const { createRemoteJWKSet, jwtVerify } = require('jose');
const ssoConfig = require('../../config/sso');

let jwks = null;

function getJWKS() {
  if (!jwks) {
    jwks = createRemoteJWKSet(new URL(ssoConfig.jwksUrl));
  }
  return jwks;
}

/**
 * Middleware that validates SSO OAuth2 access tokens via JWKS.
 * Attaches decoded token payload to req.ssoUser with:
 *   - sub: user UUID
 *   - tenant_id: tenant UUID (from token claims)
 *   - scopes: array of granted scopes
 *   - accessToken: the raw access token (for forwarding to SSO API)
 */
async function ssoAuth(req, res, next) {
  const authHeader = req.headers.authorization;
  if (!authHeader || !authHeader.startsWith('Bearer ')) {
    return res.status(401).json({ message: 'Authorization token required.' });
  }

  const token = authHeader.substring(7);

  try {
    const { payload } = await jwtVerify(token, getJWKS(), {
      issuer: ssoConfig.baseUrl,
    });

    req.ssoUser = {
      sub: payload.sub,
      tenant_id: payload.tenant_id || null,
      scopes: (payload.scopes || '').split(' ').filter(Boolean),
      accessToken: token,
    };

    next();
  } catch (error) {
    console.error('SSO token validation failed:', error.message);

    if (error.code === 'ERR_JWT_EXPIRED') {
      return res.status(401).json({ message: 'Token expired.' });
    }

    return res.status(401).json({ message: 'Invalid token.' });
  }
}

module.exports = ssoAuth;
```

- [ ] **Step 2: Verify the middleware works with a manual test**

Start the Fantasy game server and make a request with an SSO access token:

```bash
cd /Users/richard/Projects/chinga-fantasy
# Start the server (should not error on require)
node -e "const m = require('./app/middlewares/ssoAuth'); console.log('ssoAuth loaded:', typeof m)"
```

Expected: `ssoAuth loaded: function`

- [ ] **Step 3: Commit**

```bash
cd /Users/richard/Projects/chinga-fantasy
git add app/middlewares/ssoAuth.js
git commit -m "feat: add SSO JWKS token validation middleware"
```

---

## Task 4: SSO Tenant Resolution Middleware in Fantasy Game Server

**Files:**
- Create: `/Users/richard/Projects/chinga-fantasy/app/middlewares/ssoTenant.js`
- Modify: `/Users/richard/Projects/chinga-fantasy/index.js`

- [ ] **Step 1: Create the SSO tenant middleware**

```javascript
// app/middlewares/ssoTenant.js

/**
 * Middleware that extracts tenant context from the SSO-validated token.
 * Must run AFTER ssoAuth middleware for authenticated routes.
 * For unauthenticated routes (public game state), falls back to X-Tenant-ID header.
 *
 * Sets req.tenantUuid for use in all downstream queries.
 */
function ssoTenant(req, res, next) {
  // Skip for health check or public routes
  const skipPaths = ['/api/get-start-time'];
  if (skipPaths.includes(req.path)) {
    return next();
  }

  // Try to get tenant from SSO token (set by ssoAuth middleware)
  if (req.ssoUser && req.ssoUser.tenant_id) {
    req.tenantUuid = req.ssoUser.tenant_id;
    return next();
  }

  // Fallback: X-Tenant-ID header (for socket connections and public routes)
  const headerTenantId = req.headers['x-tenant-id'];
  if (headerTenantId) {
    req.tenantUuid = headerTenantId;
    return next();
  }

  // No tenant context available — let the route handler decide if that's OK
  req.tenantUuid = null;
  next();
}

module.exports = ssoTenant;
```

- [ ] **Step 2: Replace tenantResolver with ssoTenant in index.js**

In `/Users/richard/Projects/chinga-fantasy/index.js`, replace:

```javascript
const tenantResolver = require('./app/middlewares/tenantResolver');
```

with:

```javascript
const ssoTenant = require('./app/middlewares/ssoTenant');
```

And replace:

```javascript
app.use(tenantResolver);
```

with:

```javascript
app.use(ssoTenant);
```

- [ ] **Step 3: Verify server starts without errors**

```bash
cd /Users/richard/Projects/chinga-fantasy
node -e "require('./index')" 2>&1 | head -5
```

Expected: Server starts listening on port 3001 without import errors.

- [ ] **Step 4: Commit**

```bash
cd /Users/richard/Projects/chinga-fantasy
git add app/middlewares/ssoTenant.js index.js
git commit -m "feat: replace local tenant resolver with SSO tenant middleware"
```

---

## Task 5: Update Fantasy API Routes to Use SSO Auth

**Files:**
- Modify: `/Users/richard/Projects/chinga-fantasy/app/routes/api.js`

- [ ] **Step 1: Replace local auth with SSO auth in routes**

Replace the full contents of `/Users/richard/Projects/chinga-fantasy/app/routes/api.js` with:

```javascript
const express = require('express');
const router = express.Router();
const ssoAuth = require('../middlewares/ssoAuth');

// Controllers
const TeamController = require('../controllers/TeamController');
const RoundController = require('../controllers/RoundController');
const BetController = require('../controllers/BetController');
const SettingsController = require('../controllers/SettingsController');

// ─── Public routes (no auth required, tenant from header) ───

// Game state
router.get('/rounds/next', RoundController.getNextRound);
router.get('/get-start-time', (req, res) => {
  res.json({ startTime: Date.now() });
});

// Teams (read-only, will be replaced by SSO API in Plan C)
router.get('/teams', TeamController.getAll);
router.get('/jackpot', TeamController.getJackpot);

// Settings (read-only)
router.get('/default-settings', SettingsController.getFrontEndSettings);

// ─── Protected routes (SSO auth required) ───

// User info (from SSO token, no local DB lookup)
router.get('/me', ssoAuth, (req, res) => {
  res.json({
    user: {
      uuid: req.ssoUser.sub,
      tenant_id: req.ssoUser.tenant_id,
    },
  });
});

// Betting
router.post('/bets/place', ssoAuth, BetController.placeBet);
router.post('/bets/check-status', ssoAuth, BetController.checkBetStatus);
router.put('/bets/update', ssoAuth, BetController.updateBetOutcome);

// User credit (now proxied from SSO — will be fully replaced in Plan B)
router.get('/user/credit', ssoAuth, async (req, res) => {
  try {
    const ssoClient = require('../services/ssoClient');
    const data = await ssoClient.getBalance(req.ssoUser.sessionToken);
    res.json({ balance: data.balance });
  } catch (error) {
    // Fallback: return 0 if no active session yet
    res.json({ balance: '0.00' });
  }
});

// Round statistics (public for now)
router.get('/rounds/:roundId/statistics', RoundController.getStatistics);
router.get('/rounds/:roundId/players', RoundController.getPlayers);
router.get('/rounds/:roundId/user-position', ssoAuth, RoundController.getUserPosition);

module.exports = router;
```

Note: The `/api/login`, `/api/register`, `/api/refresh-token`, `/api/auth/logout`, `/api/tenant/resolve`, `/api/tenants` routes are removed — auth and tenants are handled by SSO.

- [ ] **Step 2: Commit**

```bash
cd /Users/richard/Projects/chinga-fantasy
git add app/routes/api.js
git commit -m "feat: replace local auth routes with SSO auth middleware"
```

---

## Task 6: OAuth2 PKCE Utilities in Fantasy Frontend

**Files:**
- Create: `/Users/richard/Projects/gambling/client/src/lib/ssoAuth.ts`
- Modify: `/Users/richard/Projects/gambling/client/.env`

- [ ] **Step 1: Add SSO env vars to frontend**

Replace `/Users/richard/Projects/gambling/client/.env` with:

```
PORT=3000
VITE_BACKEND_URL=http://localhost:3001
VITE_SSO_BASE_URL=http://chinga-games-sso.test
VITE_SSO_CLIENT_ID=<frontend-client-id-from-seeder>
VITE_SSO_REDIRECT_URI=http://localhost:5173/oauth/callback
```

Replace `<frontend-client-id-from-seeder>` with the actual client ID from Task 1.

- [ ] **Step 2: Create OAuth2 PKCE utilities**

```typescript
// client/src/lib/ssoAuth.ts

const SSO_BASE_URL = import.meta.env.VITE_SSO_BASE_URL || 'http://chinga-games-sso.test';
const CLIENT_ID = import.meta.env.VITE_SSO_CLIENT_ID || '';
const REDIRECT_URI = import.meta.env.VITE_SSO_REDIRECT_URI || `${window.location.origin}/oauth/callback`;
const SCOPES = 'openid profile email wallet gaming:history';

// ─── PKCE helpers ───

function generateRandomString(length: number): string {
  const array = new Uint8Array(length);
  crypto.getRandomValues(array);
  return Array.from(array, (b) => b.toString(16).padStart(2, '0')).join('').slice(0, length);
}

async function sha256(plain: string): Promise<ArrayBuffer> {
  const encoder = new TextEncoder();
  return crypto.subtle.digest('SHA-256', encoder.encode(plain));
}

function base64UrlEncode(buffer: ArrayBuffer): string {
  const bytes = new Uint8Array(buffer);
  let str = '';
  bytes.forEach((b) => (str += String.fromCharCode(b)));
  return btoa(str).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

// ─── Token storage ───

const TOKEN_KEY = 'sso_access_token';
const REFRESH_KEY = 'sso_refresh_token';
const VERIFIER_KEY = 'sso_pkce_verifier';
const STATE_KEY = 'sso_oauth_state';

export function getAccessToken(): string | null {
  return sessionStorage.getItem(TOKEN_KEY);
}

export function getRefreshToken(): string | null {
  return localStorage.getItem(REFRESH_KEY);
}

function storeTokens(accessToken: string, refreshToken?: string) {
  sessionStorage.setItem(TOKEN_KEY, accessToken);
  if (refreshToken) {
    localStorage.setItem(REFRESH_KEY, refreshToken);
  }
}

export function clearTokens() {
  sessionStorage.removeItem(TOKEN_KEY);
  localStorage.removeItem(REFRESH_KEY);
  sessionStorage.removeItem(VERIFIER_KEY);
  sessionStorage.removeItem(STATE_KEY);
}

// ─── OAuth2 PKCE flow ───

/**
 * Redirect the user to the SSO login page with PKCE challenge.
 */
export async function startLogin() {
  const verifier = generateRandomString(64);
  const state = generateRandomString(32);

  sessionStorage.setItem(VERIFIER_KEY, verifier);
  sessionStorage.setItem(STATE_KEY, state);

  const challengeBuffer = await sha256(verifier);
  const codeChallenge = base64UrlEncode(challengeBuffer);

  const params = new URLSearchParams({
    client_id: CLIENT_ID,
    redirect_uri: REDIRECT_URI,
    response_type: 'code',
    scope: SCOPES,
    state,
    code_challenge: codeChallenge,
    code_challenge_method: 'S256',
  });

  window.location.href = `${SSO_BASE_URL}/oauth/authorize?${params.toString()}`;
}

/**
 * Exchange the authorization code for tokens.
 * Call this from the OAuth callback page.
 */
export async function handleCallback(code: string, state: string): Promise<{ accessToken: string }> {
  const savedState = sessionStorage.getItem(STATE_KEY);
  if (state !== savedState) {
    throw new Error('OAuth state mismatch — possible CSRF attack.');
  }

  const verifier = sessionStorage.getItem(VERIFIER_KEY);
  if (!verifier) {
    throw new Error('PKCE verifier not found. Please try logging in again.');
  }

  const response = await fetch(`${SSO_BASE_URL}/oauth/token`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      grant_type: 'authorization_code',
      client_id: CLIENT_ID,
      redirect_uri: REDIRECT_URI,
      code,
      code_verifier: verifier,
    }),
  });

  if (!response.ok) {
    const error = await response.json().catch(() => ({ error_description: 'Token exchange failed' }));
    throw new Error(error.error_description || 'Token exchange failed');
  }

  const data = await response.json();

  storeTokens(data.access_token, data.refresh_token);

  // Clean up PKCE state
  sessionStorage.removeItem(VERIFIER_KEY);
  sessionStorage.removeItem(STATE_KEY);

  return { accessToken: data.access_token };
}

/**
 * Refresh the access token using the refresh token.
 */
export async function refreshAccessToken(): Promise<string | null> {
  const refreshToken = getRefreshToken();
  if (!refreshToken) return null;

  const response = await fetch(`${SSO_BASE_URL}/oauth/token`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      grant_type: 'refresh_token',
      client_id: CLIENT_ID,
      refresh_token: refreshToken,
    }),
  });

  if (!response.ok) {
    clearTokens();
    return null;
  }

  const data = await response.json();
  storeTokens(data.access_token, data.refresh_token);
  return data.access_token;
}

/**
 * Fetch user info from SSO.
 */
export async function fetchUserInfo(): Promise<{ sub: string; name: string; email: string; tenant_id?: string } | null> {
  const token = getAccessToken();
  if (!token) return null;

  const response = await fetch(`${SSO_BASE_URL}/api/v1/oauth/userinfo`, {
    headers: { Authorization: `Bearer ${token}` },
  });

  if (response.status === 401) {
    // Try refresh
    const newToken = await refreshAccessToken();
    if (!newToken) return null;

    const retry = await fetch(`${SSO_BASE_URL}/api/v1/oauth/userinfo`, {
      headers: { Authorization: `Bearer ${newToken}` },
    });
    if (!retry.ok) return null;
    return retry.json();
  }

  if (!response.ok) return null;
  return response.json();
}

/**
 * Logout — clear tokens and optionally redirect to SSO logout.
 */
export function logout() {
  clearTokens();
  // Redirect to SSO login (which will redirect back via OAuth)
  window.location.href = '/';
}
```

- [ ] **Step 3: Commit**

```bash
cd /Users/richard/Projects/gambling
git add client/src/lib/ssoAuth.ts client/.env
git commit -m "feat: add OAuth2 PKCE utilities for SSO authentication"
```

---

## Task 7: SSO Auth Context + Callback Page in Fantasy Frontend

**Files:**
- Create: `/Users/richard/Projects/gambling/client/src/context/SSOAuthContext.tsx`
- Create: `/Users/richard/Projects/gambling/client/src/pages/oauth-callback.tsx`

- [ ] **Step 1: Create SSOAuthContext**

```tsx
// client/src/context/SSOAuthContext.tsx
import { createContext, useContext, useEffect, useState, ReactNode } from 'react';
import {
  getAccessToken,
  fetchUserInfo,
  startLogin,
  logout as ssoLogout,
  clearTokens,
} from '@/lib/ssoAuth';

interface SSOUser {
  sub: string;
  name: string;
  email: string;
  tenant_id?: string;
}

interface SSOAuthContextProps {
  user: SSOUser | null;
  isLoading: boolean;
  isAuthenticated: boolean;
  login: () => void;
  logout: () => void;
  accessToken: string | null;
}

const SSOAuthContext = createContext<SSOAuthContextProps | undefined>(undefined);

export function SSOAuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<SSOUser | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    async function checkAuth() {
      const token = getAccessToken();
      if (!token) {
        setIsLoading(false);
        return;
      }

      const userInfo = await fetchUserInfo();
      if (userInfo) {
        setUser(userInfo);
      } else {
        clearTokens();
      }
      setIsLoading(false);
    }

    checkAuth();
  }, []);

  const login = () => startLogin();

  const logout = () => {
    setUser(null);
    ssoLogout();
  };

  return (
    <SSOAuthContext.Provider
      value={{
        user,
        isLoading,
        isAuthenticated: !!user,
        login,
        logout,
        accessToken: getAccessToken(),
      }}
    >
      {children}
    </SSOAuthContext.Provider>
  );
}

export function useSSOAuth() {
  const context = useContext(SSOAuthContext);
  if (!context) {
    throw new Error('useSSOAuth must be used within SSOAuthProvider');
  }
  return context;
}
```

- [ ] **Step 2: Create OAuth callback page**

```tsx
// client/src/pages/oauth-callback.tsx
import { useEffect, useState } from 'react';
import { useLocation } from 'wouter';
import { handleCallback } from '@/lib/ssoAuth';

export default function OAuthCallback() {
  const [, setLocation] = useLocation();
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    async function processCallback() {
      const params = new URLSearchParams(window.location.search);
      const code = params.get('code');
      const state = params.get('state');
      const errorParam = params.get('error');

      if (errorParam) {
        setError(params.get('error_description') || 'Authentication was denied.');
        return;
      }

      if (!code || !state) {
        setError('Missing authorization code. Please try logging in again.');
        return;
      }

      try {
        await handleCallback(code, state);
        // Force full page reload so SSOAuthContext picks up the new token
        window.location.href = '/';
      } catch (err) {
        setError(err instanceof Error ? err.message : 'Authentication failed.');
      }
    }

    processCallback();
  }, [setLocation]);

  if (error) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <div className="text-center">
          <h1 className="text-xl font-bold text-red-500">Login Failed</h1>
          <p className="mt-2 text-gray-400">{error}</p>
          <a href="/" className="mt-4 inline-block text-blue-400 underline">
            Try again
          </a>
        </div>
      </div>
    );
  }

  return (
    <div className="flex min-h-screen items-center justify-center">
      <p className="text-gray-400">Completing login...</p>
    </div>
  );
}
```

- [ ] **Step 3: Commit**

```bash
cd /Users/richard/Projects/gambling
git add client/src/context/SSOAuthContext.tsx client/src/pages/oauth-callback.tsx
git commit -m "feat: add SSO auth context and OAuth callback page"
```

---

## Task 8: Wire Up SSO Auth in Fantasy Frontend App

**Files:**
- Modify: `/Users/richard/Projects/gambling/client/src/App.tsx`
- Modify: `/Users/richard/Projects/gambling/client/src/lib/queryClient.ts`
- Modify: `/Users/richard/Projects/gambling/client/src/services/socketService.ts`

- [ ] **Step 1: Update App.tsx to use SSOAuthProvider and add callback route**

In `/Users/richard/Projects/gambling/client/src/App.tsx`:

Replace the import of `AuthProvider`:

```typescript
import { AuthProvider } from "./context/AuthContext";
```

with:

```typescript
import { SSOAuthProvider } from "./context/SSOAuthContext";
```

Replace the import of the auth page:

```typescript
import AuthPage from "./pages/auth";
```

with:

```typescript
import OAuthCallback from "./pages/oauth-callback";
```

In the Router component, replace the `/login` route:

```tsx
<Route path="/login" component={AuthPage} />
```

with:

```tsx
<Route path="/oauth/callback" component={OAuthCallback} />
```

Replace `<AuthProvider>` wrapper with `<SSOAuthProvider>`:

```tsx
<AuthProvider>
```

with:

```tsx
<SSOAuthProvider>
```

And the closing tag similarly.

Also remove the localStorage token cleanup logic in the `useEffect` that references the old `token` key — the SSOAuthContext handles its own token lifecycle.

- [ ] **Step 2: Update queryClient.ts to use SSO token**

In `/Users/richard/Projects/gambling/client/src/lib/queryClient.ts`, update the `apiRequest` function to use the SSO token instead of the old localStorage JWT.

Replace the token retrieval:

```typescript
const token = localStorage.getItem('token');
```

with:

```typescript
const token = sessionStorage.getItem('sso_access_token');
```

Remove the `getTenantHeaders()` function that reads from old tenant context. The tenant is embedded in the SSO token, so no explicit header is needed for authenticated requests. For unauthenticated requests to the game server, keep the `X-Tenant-ID` header from the tenant context.

- [ ] **Step 3: Update socketService.ts to pass SSO token**

In `/Users/richard/Projects/gambling/client/src/services/socketService.ts`, update the socket connection to pass the SSO access token:

Where the socket is initialized with `io(backendUrl, options)`, add auth to the options:

```typescript
const token = sessionStorage.getItem('sso_access_token');
this.socket = io(backendUrl, {
  // ... existing options ...
  auth: {
    token: token || undefined,
  },
});
```

- [ ] **Step 4: Update home.tsx to use useSSOAuth instead of useAuth**

In `/Users/richard/Projects/gambling/client/src/pages/home.tsx`:

Replace:

```typescript
import { useAuth } from "@/context/AuthContext";
```

with:

```typescript
import { useSSOAuth } from "@/context/SSOAuthContext";
```

And in the component body, replace:

```typescript
const { user } = useAuth();
```

with:

```typescript
const { user, isAuthenticated, login } = useSSOAuth();
```

Where the component checks if user is logged in, use `isAuthenticated`. Where it would redirect to `/login`, call `login()` instead (which triggers the OAuth2 PKCE redirect to SSO).

- [ ] **Step 5: Commit**

```bash
cd /Users/richard/Projects/gambling
git add client/src/App.tsx client/src/lib/queryClient.ts client/src/services/socketService.ts client/src/pages/home.tsx
git commit -m "feat: wire SSO auth into frontend app, routes, and services"
```

---

## Task 9: Update Fantasy Game Server Socket.IO to Accept SSO Tokens

**Files:**
- Modify: `/Users/richard/Projects/chinga-fantasy/index.js`

- [ ] **Step 1: Add token validation to Socket.IO connection**

In `/Users/richard/Projects/chinga-fantasy/index.js`, after the Socket.IO server is created, add authentication middleware:

```javascript
const { createRemoteJWKSet, jwtVerify } = require('jose');
const ssoConfig = require('./config/sso');

let jwks = null;
function getJWKS() {
  if (!jwks) jwks = createRemoteJWKSet(new URL(ssoConfig.jwksUrl));
  return jwks;
}

// Socket.IO authentication middleware
io.use(async (socket, next) => {
  const token = socket.handshake.auth?.token;
  if (!token) {
    // Allow unauthenticated connections (spectators, public game state)
    socket.ssoUser = null;
    return next();
  }

  try {
    const { payload } = await jwtVerify(token, getJWKS(), {
      issuer: ssoConfig.baseUrl,
    });
    socket.ssoUser = {
      sub: payload.sub,
      tenant_id: payload.tenant_id || null,
    };
    next();
  } catch (error) {
    // Allow connection but mark as unauthenticated
    socket.ssoUser = null;
    next();
  }
});
```

- [ ] **Step 2: Commit**

```bash
cd /Users/richard/Projects/chinga-fantasy
git add index.js
git commit -m "feat: add SSO token validation to Socket.IO connections"
```

---

## Task 10: Database Schema Migration — Replace tenant_id/site_id with tenant_uuid

**Files:**
- New SQL migration or manual schema update in Fantasy PostgreSQL

- [ ] **Step 1: Create migration SQL**

Create a file `/Users/richard/Projects/chinga-fantasy/migrations/001_replace_tenant_with_uuid.sql`:

```sql
-- Replace integer tenant_id/site_id with SSO tenant UUID across all tables.
-- Run this against the chingadb PostgreSQL database.

BEGIN;

-- Add tenant_uuid column to all tenant-scoped tables
ALTER TABLE rounds ADD COLUMN IF NOT EXISTS tenant_uuid VARCHAR(36);
ALTER TABLE bets ADD COLUMN IF NOT EXISTS tenant_uuid VARCHAR(36);
ALTER TABLE bets ADD COLUMN IF NOT EXISTS user_uuid VARCHAR(36);
ALTER TABLE round_teams ADD COLUMN IF NOT EXISTS tenant_uuid VARCHAR(36);
ALTER TABLE jackpot_transactions ADD COLUMN IF NOT EXISTS tenant_uuid VARCHAR(36);
ALTER TABLE pool_transactions ADD COLUMN IF NOT EXISTS tenant_uuid VARCHAR(36);
ALTER TABLE settings ADD COLUMN IF NOT EXISTS tenant_uuid VARCHAR(36);

-- Add indexes for the new UUID columns
CREATE INDEX IF NOT EXISTS idx_rounds_tenant_uuid ON rounds(tenant_uuid);
CREATE INDEX IF NOT EXISTS idx_bets_tenant_uuid ON bets(tenant_uuid);
CREATE INDEX IF NOT EXISTS idx_bets_user_uuid ON bets(user_uuid);
CREATE INDEX IF NOT EXISTS idx_settings_tenant_uuid ON settings(tenant_uuid);

COMMIT;
```

Note: We keep the old `tenant_id`, `site_id`, and `user_id` columns for now — they'll be dropped after full migration is verified (Plan cleanup phase). The code will write to both during transition.

- [ ] **Step 2: Run the migration**

```bash
cd /Users/richard/Projects/chinga-fantasy
psql -U richard -d chingadb -f migrations/001_replace_tenant_with_uuid.sql
```

Expected: All ALTER TABLE and CREATE INDEX statements succeed.

- [ ] **Step 3: Commit**

```bash
cd /Users/richard/Projects/chinga-fantasy
mkdir -p migrations
git add migrations/001_replace_tenant_with_uuid.sql
git commit -m "feat: add tenant_uuid and user_uuid columns for SSO integration"
```

---

## Task 11: Update Fantasy Models to Use tenant_uuid and user_uuid

**Files:**
- Modify: `/Users/richard/Projects/chinga-fantasy/app/models/Round.js`
- Modify: `/Users/richard/Projects/chinga-fantasy/app/models/Bet.js`
- Modify: `/Users/richard/Projects/chinga-fantasy/app/models/Settings.js`

- [ ] **Step 1: Update Round model to use tenant_uuid**

In `/Users/richard/Projects/chinga-fantasy/app/models/Round.js`, update `getLastRound()` to scope by `tenant_uuid` instead of `tenant_id`/`site_id`:

Where queries use:
```javascript
query += ' WHERE tenant_id = $1 AND site_id = $2';
```

Replace with:
```javascript
query += ' WHERE tenant_uuid = $1';
```

And update `createNewRound()` to insert `tenant_uuid` instead of `tenant_id`/`site_id`:

Where the INSERT uses:
```javascript
INSERT INTO rounds (tenant_id, site_id, ...) VALUES ($1, $2, ...)
```

Replace with:
```javascript
INSERT INTO rounds (tenant_uuid, ...) VALUES ($1, ...)
```

The `req.tenantUuid` (set by `ssoTenant` middleware) replaces `req.tenant.id` and `req.currentSite.id`.

- [ ] **Step 2: Update Bet model to use tenant_uuid and user_uuid**

In `/Users/richard/Projects/chinga-fantasy/app/models/Bet.js`, update the `create()` method:

Where it uses `user_id`, add `user_uuid` from `req.ssoUser.sub`. Where it scopes by `tenant_id`/`site_id`, use `tenant_uuid` from `req.tenantUuid`.

In `getBetsByUserAndRound()`, replace:
```javascript
WHERE user_id = $1 AND round_id = $2
```
with:
```javascript
WHERE user_uuid = $1 AND round_id = $2
```

The `userId` parameter becomes the SSO user UUID (`req.ssoUser.sub`).

- [ ] **Step 3: Update Settings model to use tenant_uuid**

In `/Users/richard/Projects/chinga-fantasy/app/models/Settings.js`, update `getValueByKey()` and `getFrontEndSettings()`:

Where queries scope by `tenant_id`:
```javascript
WHERE key = $1 AND tenant_id = $2
```

Replace with:
```javascript
WHERE key = $1 AND tenant_uuid = $2
```

Use `req.tenantUuid` instead of `req.tenant.id`.

- [ ] **Step 4: Commit**

```bash
cd /Users/richard/Projects/chinga-fantasy
git add app/models/Round.js app/models/Bet.js app/models/Settings.js
git commit -m "feat: update models to use SSO tenant_uuid and user_uuid"
```

---

## Task 12: Update Fantasy Controllers to Use SSO User Context

**Files:**
- Modify: `/Users/richard/Projects/chinga-fantasy/app/controllers/BetController.js`
- Modify: `/Users/richard/Projects/chinga-fantasy/app/controllers/RoundController.js`

- [ ] **Step 1: Update BetController to use req.ssoUser**

In `/Users/richard/Projects/chinga-fantasy/app/controllers/BetController.js`:

In `placeBet()`, replace references to `req.user.id` (from old JWT) with `req.ssoUser.sub` (SSO user UUID). For example:

```javascript
// Old:
const userId = req.user.id;
// New:
const userUuid = req.ssoUser.sub;
```

Pass `userUuid` to `Bet.create()` and `Bet.getBetsByUserAndRound()` instead of the old integer `userId`.

In `checkBetStatus()`, replace `req.body.token` JWT user extraction with `req.ssoUser.sub`.

In `updateBetOutcome()`, similarly use `req.ssoUser.sub`.

Remove any references to `User.getUserCredit()` — balance checks are now handled by the SSO debit call (Plan B). For now, skip the local balance validation.

- [ ] **Step 2: Update RoundController to use req.tenantUuid**

In `/Users/richard/Projects/chinga-fantasy/app/controllers/RoundController.js`:

The `getNextRound()` method calls `Round.getLastRound(req)` and `Round.createNewRound(req)`. These now read `req.tenantUuid` instead of `req.tenant.id`. No controller changes needed if the model methods accept `req` — the model changes from Task 11 handle this.

Verify `getNextRound` doesn't reference `req.tenant` or `req.currentSite` directly.

- [ ] **Step 3: Commit**

```bash
cd /Users/richard/Projects/chinga-fantasy
git add app/controllers/BetController.js app/controllers/RoundController.js
git commit -m "feat: update controllers to use SSO user UUID and tenant UUID"
```

---

## Task 13: End-to-End Verification

- [ ] **Step 1: Start all three services**

Terminal 1 — SSO:
```bash
cd /Users/richard/Projects/chinga-games-sso
php artisan serve
```

Terminal 2 — Fantasy Game Server:
```bash
cd /Users/richard/Projects/chinga-fantasy
npm run devStart
```

Terminal 3 — Fantasy Frontend:
```bash
cd /Users/richard/Projects/gambling
npm run dev
```

- [ ] **Step 2: Verify OAuth2 login flow**

1. Open `http://localhost:5173` in browser
2. Click login → should redirect to SSO login page at `http://chinga-games-sso.test/oauth/authorize?...`
3. Login with SSO credentials
4. Should redirect back to `http://localhost:5173/oauth/callback?code=...&state=...`
5. Callback page exchanges code for token and redirects to `/`
6. Home page should show user is authenticated

- [ ] **Step 3: Verify game server accepts SSO token**

```bash
# Get an SSO access token (using password grant for testing):
curl -s -X POST http://chinga-games-sso.test/oauth/token \
  -H "Content-Type: application/json" \
  -d '{"grant_type":"password","client_id":"<frontend-client-id>","username":"<test-user-email>","password":"<test-password>","scope":"openid profile email wallet"}' | jq .

# Use the access_token to call Fantasy game server:
curl -s http://localhost:3001/api/me \
  -H "Authorization: Bearer <access_token>" | jq .
```

Expected: Returns `{ "user": { "uuid": "...", "tenant_id": "..." } }`

- [ ] **Step 4: Verify Socket.IO connects with token**

Open browser devtools Network tab → WS tab. The Socket.IO connection should include the auth token in the handshake. Game state events should still flow normally.

- [ ] **Step 5: Document any issues found and create follow-up tasks**

If the SSO doesn't include `tenant_id` in the JWT claims, we may need to add a custom claim. Check the Passport token payload and update the SSO's token generation if needed.
