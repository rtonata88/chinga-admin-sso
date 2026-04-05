# Fantasy Login Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `/login` page to the Fantasy frontend with account registration, account login (password grant), and voucher login — replacing the OAuth2 PKCE redirect flow.

**Architecture:** Two SSO endpoints are added (registration API + web voucher session). The frontend gets a tabbed login page that calls the SSO directly. The auth context supports two modes: account (with access token + user info) and voucher (with game session token + balance only). The game server's auth middleware is updated to pass through `gs_*` tokens.

**Tech Stack:** Laravel (SSO), React/Vite (frontend), Node.js/Express (game server)

**Spec:** `docs/superpowers/specs/2026-04-05-fantasy-login-page-design.md`

**Projects involved:**
- SSO: `/Users/richard/Projects/chinga-games-sso`
- Fantasy Frontend: `/Users/richard/Projects/gambling`
- Fantasy Game Server: `/Users/richard/Projects/chinga-fantasy`

---

## File Structure

### SSO — New/Modified Files
| Action | File | Purpose |
|--------|------|---------|
| Create | `app/Http/Controllers/Api/RegistrationController.php` | JSON API registration endpoint |
| Create | `app/Http/Controllers/Api/VoucherWebSessionController.php` | Web voucher session endpoint |
| Modify | `app/Services/GameSessionService.php` | Add `startWebVoucherSession` method (no terminal required) |
| Modify | `routes/api.php` | Add registration route |
| Modify | `routes/game.php` | Add web voucher session route |

### Fantasy Frontend — New/Modified Files
| Action | File | Purpose |
|--------|------|---------|
| Create | `client/src/pages/login.tsx` | Login page with 3 tabs |
| Rewrite | `client/src/lib/ssoAuth.ts` | Replace PKCE with password grant + registration + voucher API calls |
| Rewrite | `client/src/context/SSOAuthContext.tsx` | Dual-mode auth (account + voucher) |
| Modify | `client/src/App.tsx` | Replace /oauth/callback with /login route |
| Delete | `client/src/pages/oauth-callback.tsx` | No longer needed |

### Fantasy Game Server — Modified Files
| Action | File | Purpose |
|--------|------|---------|
| Modify | `app/middlewares/ssoAuth.js` | Pass through `gs_*` tokens for voucher sessions |

---

## Task 1: SSO Registration API Endpoint

**Files:**
- Create: `/Users/richard/Projects/chinga-games-sso/app/Http/Controllers/Api/RegistrationController.php`
- Modify: `/Users/richard/Projects/chinga-games-sso/routes/api.php`

- [ ] **Step 1: Create the registration controller**

```php
<?php
// app/Http/Controllers/Api/RegistrationController.php

namespace App\Http\Controllers\Api;

use App\Actions\Fortify\CreateNewUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\PersonalAccessTokenResult;

class RegistrationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        try {
            $creator = app(CreateNewUser::class);
            $user = $creator->create($request->all());
        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Validation failed.',
                'errors' => $e->errors(),
            ], 422);
        }

        // Issue a Passport token for the newly created user
        $token = $user->createToken('fantasy-app', ['openid', 'profile', 'email', 'wallet', 'gaming:history']);

        return response()->json([
            'user' => [
                'uuid' => $user->uuid,
                'name' => $user->name,
                'email' => $user->email,
                'username' => $user->username,
            ],
            'access_token' => $token->accessToken,
            'expires_in' => 3600,
        ], 201);
    }
}
```

- [ ] **Step 2: Add route in api.php**

In `/Users/richard/Projects/chinga-games-sso/routes/api.php`, add before the `v1` prefix group:

```php
use App\Http\Controllers\Api\RegistrationController;
```

Then inside the `v1` prefix group, add (outside the `auth:api` middleware group):

```php
// Public registration endpoint
Route::post('register', [RegistrationController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('api.register');
```

- [ ] **Step 3: Commit**

```bash
cd /Users/richard/Projects/chinga-games-sso
git add app/Http/Controllers/Api/RegistrationController.php routes/api.php
git commit -m "feat: add JSON API registration endpoint"
```

---

## Task 2: SSO Web Voucher Session Endpoint

**Files:**
- Modify: `/Users/richard/Projects/chinga-games-sso/app/Services/GameSessionService.php`
- Create: `/Users/richard/Projects/chinga-games-sso/app/Http/Controllers/Api/VoucherWebSessionController.php`
- Modify: `/Users/richard/Projects/chinga-games-sso/routes/game.php`

- [ ] **Step 1: Add `startWebVoucherSession` method to GameSessionService**

Read `app/Services/GameSessionService.php` first. Add this method after `startVoucherSession`:

```php
/**
 * Start a web voucher session (no terminal required).
 * Voucher is scoped by tenant instead of terminal/venue.
 */
public function startWebVoucherSession(
    VoucherCode $code,
    Game $game,
    ?string $pin = null,
    ?string $ipAddress = null
): GameSession {
    $this->validateGameForTenant($game, $code->tenant_id);

    if (!$code->canBeUsed()) {
        throw new \RuntimeException('Voucher code cannot be used.');
    }

    if ($code->hasPin()) {
        if (!$pin) {
            throw new \RuntimeException('PIN is required for this voucher code.');
        }
        if (!$code->verifyPin($pin)) {
            throw new \RuntimeException('Invalid PIN.');
        }
    }

    $this->ensureNoActiveSession($code);

    return DB::transaction(function () use ($code, $game, $ipAddress) {
        $session = GameSession::create([
            'tenant_id' => $code->tenant_id,
            'game_id' => $game->id,
            'source_type' => VoucherCode::class,
            'source_id' => $code->id,
            'terminal_id' => null,
            'ip_address' => $ipAddress,
            'balance_start' => $code->balance,
        ]);

        $code->update([
            'status' => 'in_use',
            'current_terminal_id' => null,
            'current_session_id' => $session->id,
            'last_activity_at' => now(),
        ]);

        return $session;
    });
}
```

- [ ] **Step 2: Create the controller**

```php
<?php
// app/Http/Controllers/Api/VoucherWebSessionController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\VoucherCode;
use App\Services\GameSessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoucherWebSessionController extends Controller
{
    public function __construct(
        protected GameSessionService $gameSessionService
    ) {}

    public function start(Request $request): JsonResponse
    {
        $request->validate([
            'game_id' => ['required', 'string'],
            'code' => ['required', 'string'],
            'pin' => ['nullable', 'string'],
        ]);

        $tenantId = app('current_tenant')?->id;
        if (!$tenantId) {
            return response()->json(['message' => 'Tenant context required.'], 400);
        }

        $game = Game::where('uuid', $request->input('game_id'))->first();
        if (!$game) {
            return response()->json(['message' => 'Game not found.'], 404);
        }

        $code = VoucherCode::where('code', strtoupper($request->input('code')))
            ->where('tenant_id', $tenantId)
            ->first();

        if (!$code) {
            return response()->json(['message' => 'Invalid voucher code.'], 404);
        }

        try {
            $session = $this->gameSessionService->startWebVoucherSession(
                $code,
                $game,
                $request->input('pin'),
                $request->ip()
            );

            return response()->json([
                'session_token' => $session->session_token,
                'balance' => $session->balance_start,
                'currency' => $code->currency,
                'game' => [
                    'uuid' => $game->uuid,
                    'name' => $game->name,
                    'slug' => $game->slug,
                ],
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
```

- [ ] **Step 3: Add route in game.php**

In `/Users/richard/Projects/chinga-games-sso/routes/game.php`, add after the terminal session route group:

```php
use App\Http\Controllers\Api\VoucherWebSessionController;

// Web voucher session start (no terminal key required, tenant-scoped)
Route::middleware(['api'])->prefix('api/v1/game/session/start')->group(function () {
    Route::post('/voucher-web', [VoucherWebSessionController::class, 'start'])
        ->middleware('throttle:20,1')
        ->name('api.game.session.start.voucher-web');
});
```

- [ ] **Step 4: Commit**

```bash
cd /Users/richard/Projects/chinga-games-sso
git add app/Services/GameSessionService.php app/Http/Controllers/Api/VoucherWebSessionController.php routes/game.php
git commit -m "feat: add web voucher session endpoint (no terminal required)"
```

---

## Task 3: Rewrite Frontend Auth Utilities

Replace the PKCE flow in `ssoAuth.ts` with direct API calls for password grant, registration, and voucher login.

**Files:**
- Rewrite: `/Users/richard/Projects/gambling/client/src/lib/ssoAuth.ts`

- [ ] **Step 1: Replace ssoAuth.ts contents**

```typescript
// client/src/lib/ssoAuth.ts
// Direct SSO API calls for login, registration, and voucher sessions.

const SSO_BASE_URL = import.meta.env.VITE_SSO_BASE_URL || 'http://chinga-games-sso.test';
const CLIENT_ID = import.meta.env.VITE_SSO_CLIENT_ID || '';
const SCOPES = 'openid profile email wallet gaming:history';

// Storage keys
const KEY_ACCESS_TOKEN = 'sso_access_token';
const KEY_REFRESH_TOKEN = 'sso_refresh_token';
const KEY_SESSION_TOKEN = 'sso_game_session';
const KEY_AUTH_MODE = 'sso_auth_mode'; // 'account' | 'voucher'

export type AuthMode = 'account' | 'voucher' | null;

// --- Token storage ---

export function getAccessToken(): string | null {
  return sessionStorage.getItem(KEY_ACCESS_TOKEN);
}

export function getRefreshToken(): string | null {
  return localStorage.getItem(KEY_REFRESH_TOKEN);
}

export function getGameSessionToken(): string | null {
  return sessionStorage.getItem(KEY_SESSION_TOKEN);
}

export function getAuthMode(): AuthMode {
  return (sessionStorage.getItem(KEY_AUTH_MODE) as AuthMode) || null;
}

export function clearTokens(): void {
  sessionStorage.removeItem(KEY_ACCESS_TOKEN);
  sessionStorage.removeItem(KEY_SESSION_TOKEN);
  sessionStorage.removeItem(KEY_AUTH_MODE);
  localStorage.removeItem(KEY_REFRESH_TOKEN);
}

function storeAccountTokens(accessToken: string, refreshToken?: string): void {
  sessionStorage.setItem(KEY_ACCESS_TOKEN, accessToken);
  sessionStorage.setItem(KEY_AUTH_MODE, 'account');
  if (refreshToken) {
    localStorage.setItem(KEY_REFRESH_TOKEN, refreshToken);
  }
}

function storeVoucherSession(sessionToken: string): void {
  sessionStorage.setItem(KEY_SESSION_TOKEN, sessionToken);
  sessionStorage.setItem(KEY_AUTH_MODE, 'voucher');
}

// --- Tenant headers ---

function getTenantHeaders(): Record<string, string> {
  const headers: Record<string, string> = {};
  const hostname = window.location.hostname;
  const parts = hostname.split('.');
  if (parts.length > 2) {
    headers['X-Tenant-Subdomain'] = parts[0];
  }
  // Also check URL params for dev
  const params = new URLSearchParams(window.location.search);
  const tenantParam = params.get('tenant');
  if (tenantParam) {
    headers['X-Tenant-Subdomain'] = tenantParam;
  }
  return headers;
}

// --- Account login (password grant) ---

export async function loginWithPassword(
  emailOrUsername: string,
  password: string
): Promise<{ user: { uuid: string; name: string; email: string } }> {
  const response = await fetch(`${SSO_BASE_URL}/oauth/token`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', ...getTenantHeaders() },
    body: JSON.stringify({
      grant_type: 'password',
      client_id: CLIENT_ID,
      username: emailOrUsername,
      password,
      scope: SCOPES,
    }),
  });

  if (!response.ok) {
    const error = await response.json().catch(() => ({ message: 'Login failed' }));
    throw new Error(error.message || error.error_description || 'Invalid credentials');
  }

  const tokens = await response.json();
  storeAccountTokens(tokens.access_token, tokens.refresh_token);

  // Fetch user info
  const userInfo = await fetchUserInfo();
  return { user: userInfo as { uuid: string; name: string; email: string } };
}

// --- Registration ---

export interface RegisterData {
  name: string;
  email: string;
  username: string;
  password: string;
  password_confirmation: string;
  date_of_birth: string;
  country_code: string;
  terms_accepted: boolean;
}

export async function register(
  data: RegisterData
): Promise<{ user: { uuid: string; name: string; email: string; username: string } }> {
  const response = await fetch(`${SSO_BASE_URL}/api/v1/register`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', ...getTenantHeaders() },
    body: JSON.stringify(data),
  });

  if (!response.ok) {
    const error = await response.json().catch(() => ({ message: 'Registration failed' }));
    if (error.errors) {
      // Flatten validation errors
      const messages = Object.values(error.errors).flat().join('. ');
      throw new Error(messages);
    }
    throw new Error(error.message || 'Registration failed');
  }

  const result = await response.json();
  storeAccountTokens(result.access_token);
  return { user: result.user };
}

// --- Voucher login ---

export async function loginWithVoucher(
  code: string,
  pin?: string
): Promise<{ sessionToken: string; balance: string; currency: string }> {
  const gameUuid = import.meta.env.VITE_SSO_GAME_UUID || '';

  const response = await fetch(`${SSO_BASE_URL}/api/v1/game/session/start/voucher-web`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', ...getTenantHeaders() },
    body: JSON.stringify({
      game_id: gameUuid,
      code,
      pin: pin || undefined,
    }),
  });

  if (!response.ok) {
    const error = await response.json().catch(() => ({ message: 'Voucher login failed' }));
    throw new Error(error.message || 'Invalid voucher code');
  }

  const result = await response.json();
  storeVoucherSession(result.session_token);
  return {
    sessionToken: result.session_token,
    balance: result.balance,
    currency: result.currency,
  };
}

// --- User info (account mode only) ---

export async function fetchUserInfo(): Promise<Record<string, unknown>> {
  const accessToken = getAccessToken();
  if (!accessToken) throw new Error('No access token');

  const response = await fetch(`${SSO_BASE_URL}/api/v1/oauth/userinfo`, {
    headers: { Authorization: `Bearer ${accessToken}`, Accept: 'application/json' },
  });

  if (response.status === 401) {
    // Try refresh
    await refreshAccessToken();
    const retryToken = getAccessToken();
    const retry = await fetch(`${SSO_BASE_URL}/api/v1/oauth/userinfo`, {
      headers: { Authorization: `Bearer ${retryToken}`, Accept: 'application/json' },
    });
    if (!retry.ok) throw new Error('Failed to fetch user info after refresh');
    return retry.json();
  }

  if (!response.ok) throw new Error('Failed to fetch user info');
  return response.json();
}

// --- Token refresh ---

export async function refreshAccessToken(): Promise<void> {
  const refreshToken = getRefreshToken();
  if (!refreshToken) throw new Error('No refresh token');

  const response = await fetch(`${SSO_BASE_URL}/oauth/token`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      grant_type: 'refresh_token',
      client_id: CLIENT_ID,
      refresh_token: refreshToken,
      scope: SCOPES,
    }),
  });

  if (!response.ok) {
    clearTokens();
    throw new Error('Token refresh failed');
  }

  const tokens = await response.json();
  storeAccountTokens(tokens.access_token, tokens.refresh_token);
}

// --- Logout ---

export function logout(): void {
  clearTokens();
  window.location.href = '/login';
}
```

- [ ] **Step 2: Add VITE_SSO_GAME_UUID to frontend .env**

In `/Users/richard/Projects/gambling/client/.env`, add:

```
VITE_SSO_GAME_UUID=bd526f78-0368-4f7a-8211-2c1632c1ca61
```

- [ ] **Step 3: Commit**

```bash
cd /Users/richard/Projects/gambling
git add client/src/lib/ssoAuth.ts client/.env
git commit -m "feat: replace PKCE auth with password grant, registration, and voucher login"
```

---

## Task 4: Rewrite Auth Context for Dual Mode

**Files:**
- Rewrite: `/Users/richard/Projects/gambling/client/src/context/SSOAuthContext.tsx`

- [ ] **Step 1: Replace SSOAuthContext.tsx contents**

```tsx
// client/src/context/SSOAuthContext.tsx
import { createContext, useContext, useEffect, useState, useCallback, ReactNode } from 'react';
import {
  getAccessToken,
  getGameSessionToken,
  getAuthMode,
  fetchUserInfo,
  loginWithPassword,
  register,
  loginWithVoucher,
  logout as ssoLogout,
  clearTokens,
  type AuthMode,
  type RegisterData,
} from '@/lib/ssoAuth';

interface SSOUser {
  sub: string;
  name: string;
  email: string;
  tenant_id?: string;
}

interface VoucherSession {
  sessionToken: string;
  balance: string;
  currency: string;
}

interface SSOAuthContextProps {
  user: SSOUser | null;
  voucherSession: VoucherSession | null;
  authMode: AuthMode;
  isLoading: boolean;
  isAuthenticated: boolean;
  loginAccount: (emailOrUsername: string, password: string) => Promise<void>;
  registerAccount: (data: RegisterData) => Promise<void>;
  loginVoucher: (code: string, pin?: string) => Promise<void>;
  logout: () => void;
  accessToken: string | null;
  gameSessionToken: string | null;
}

const SSOAuthContext = createContext<SSOAuthContextProps | undefined>(undefined);

export function SSOAuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<SSOUser | null>(null);
  const [voucherSession, setVoucherSession] = useState<VoucherSession | null>(null);
  const [authMode, setAuthMode] = useState<AuthMode>(null);
  const [isLoading, setIsLoading] = useState(true);

  // Restore session on mount
  useEffect(() => {
    async function restoreSession() {
      const mode = getAuthMode();

      if (mode === 'account') {
        const token = getAccessToken();
        if (token) {
          try {
            const info = await fetchUserInfo();
            setUser(info as unknown as SSOUser);
            setAuthMode('account');
          } catch {
            clearTokens();
          }
        }
      } else if (mode === 'voucher') {
        const sessionToken = getGameSessionToken();
        if (sessionToken) {
          setVoucherSession({ sessionToken, balance: '0.00', currency: 'NAD' });
          setAuthMode('voucher');
        }
      }

      setIsLoading(false);
    }
    restoreSession();
  }, []);

  const loginAccount = useCallback(async (emailOrUsername: string, password: string) => {
    const result = await loginWithPassword(emailOrUsername, password);
    const info = await fetchUserInfo();
    setUser(info as unknown as SSOUser);
    setAuthMode('account');
  }, []);

  const registerAccount = useCallback(async (data: RegisterData) => {
    const result = await register(data);
    setUser({
      sub: result.user.uuid,
      name: result.user.name,
      email: result.user.email,
    });
    setAuthMode('account');
  }, []);

  const loginVoucher = useCallback(async (code: string, pin?: string) => {
    const result = await loginWithVoucher(code, pin);
    setVoucherSession(result);
    setAuthMode('voucher');
  }, []);

  const logout = useCallback(() => {
    setUser(null);
    setVoucherSession(null);
    setAuthMode(null);
    ssoLogout();
  }, []);

  const isAuthenticated = authMode === 'account' ? !!user : authMode === 'voucher' ? !!voucherSession : false;

  return (
    <SSOAuthContext.Provider
      value={{
        user,
        voucherSession,
        authMode,
        isLoading,
        isAuthenticated,
        loginAccount,
        registerAccount,
        loginVoucher,
        logout,
        accessToken: getAccessToken(),
        gameSessionToken: getGameSessionToken(),
      }}
    >
      {children}
    </SSOAuthContext.Provider>
  );
}

export function useSSOAuth() {
  const context = useContext(SSOAuthContext);
  if (!context) throw new Error('useSSOAuth must be used within SSOAuthProvider');
  return context;
}
```

- [ ] **Step 2: Commit**

```bash
cd /Users/richard/Projects/gambling
git add client/src/context/SSOAuthContext.tsx
git commit -m "feat: rewrite auth context for dual-mode (account + voucher)"
```

---

## Task 5: Create Login Page

**Files:**
- Create: `/Users/richard/Projects/gambling/client/src/pages/login.tsx`

- [ ] **Step 1: Create the login page**

Read the existing `pages/auth.tsx` (the old login page that was replaced) and `pages/home.tsx` to understand the UI patterns and styling used. Then create `pages/login.tsx` with three tabs: Login, Register, Voucher.

```tsx
// client/src/pages/login.tsx
import { useState } from 'react';
import { useSSOAuth } from '@/context/SSOAuthContext';
import { useLocation } from 'wouter';
import type { RegisterData } from '@/lib/ssoAuth';

type Tab = 'login' | 'register' | 'voucher';

export default function LoginPage() {
  const [tab, setTab] = useState<Tab>('login');
  const [error, setError] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const { loginAccount, registerAccount, loginVoucher } = useSSOAuth();
  const [, setLocation] = useLocation();

  // Login form state
  const [loginEmail, setLoginEmail] = useState('');
  const [loginPassword, setLoginPassword] = useState('');

  // Register form state
  const [regName, setRegName] = useState('');
  const [regEmail, setRegEmail] = useState('');
  const [regUsername, setRegUsername] = useState('');
  const [regPassword, setRegPassword] = useState('');
  const [regPasswordConfirm, setRegPasswordConfirm] = useState('');
  const [regDob, setRegDob] = useState('');
  const [regCountry, setRegCountry] = useState('NA');
  const [regTerms, setRegTerms] = useState(false);

  // Voucher form state
  const [voucherCode, setVoucherCode] = useState('');
  const [voucherPin, setVoucherPin] = useState('');

  async function handleLogin(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setLoading(true);
    try {
      await loginAccount(loginEmail, loginPassword);
      setLocation('/');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Login failed');
    } finally {
      setLoading(false);
    }
  }

  async function handleRegister(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    if (regPassword !== regPasswordConfirm) {
      setError('Passwords do not match');
      return;
    }
    setLoading(true);
    try {
      const data: RegisterData = {
        name: regName,
        email: regEmail,
        username: regUsername,
        password: regPassword,
        password_confirmation: regPasswordConfirm,
        date_of_birth: regDob,
        country_code: regCountry,
        terms_accepted: regTerms,
      };
      await registerAccount(data);
      setLocation('/');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Registration failed');
    } finally {
      setLoading(false);
    }
  }

  async function handleVoucher(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setLoading(true);
    try {
      await loginVoucher(voucherCode, voucherPin || undefined);
      setLocation('/');
    } catch (err) {
      setError(err instanceof Error ? err.message : 'Voucher login failed');
    } finally {
      setLoading(false);
    }
  }

  const tabClass = (t: Tab) =>
    `flex-1 py-3 text-center text-sm font-medium transition-colors ${
      tab === t
        ? 'border-b-2 border-blue-500 text-blue-400'
        : 'text-gray-500 hover:text-gray-300'
    }`;

  const inputClass =
    'w-full rounded-lg border border-gray-700 bg-gray-800 px-4 py-3 text-white placeholder-gray-500 focus:border-blue-500 focus:outline-none';

  return (
    <div className="flex min-h-screen items-center justify-center bg-gray-950 px-4">
      <div className="w-full max-w-md">
        <h1 className="mb-8 text-center text-2xl font-bold text-white">
          Chinga Fantasy
        </h1>

        {/* Tabs */}
        <div className="mb-6 flex border-b border-gray-800">
          <button className={tabClass('login')} onClick={() => { setTab('login'); setError(null); }}>
            Login
          </button>
          <button className={tabClass('register')} onClick={() => { setTab('register'); setError(null); }}>
            Register
          </button>
          <button className={tabClass('voucher')} onClick={() => { setTab('voucher'); setError(null); }}>
            Voucher
          </button>
        </div>

        {/* Error */}
        {error && (
          <div className="mb-4 rounded-lg border border-red-800 bg-red-900/30 px-4 py-3 text-sm text-red-400">
            {error}
          </div>
        )}

        {/* Login Tab */}
        {tab === 'login' && (
          <form onSubmit={handleLogin} className="space-y-4">
            <input
              type="text"
              placeholder="Email or username"
              value={loginEmail}
              onChange={(e) => setLoginEmail(e.target.value)}
              className={inputClass}
              required
            />
            <input
              type="password"
              placeholder="Password"
              value={loginPassword}
              onChange={(e) => setLoginPassword(e.target.value)}
              className={inputClass}
              required
            />
            <button
              type="submit"
              disabled={loading}
              className="w-full rounded-lg bg-blue-600 py-3 font-medium text-white transition-colors hover:bg-blue-700 disabled:opacity-50"
            >
              {loading ? 'Signing in...' : 'Sign In'}
            </button>
          </form>
        )}

        {/* Register Tab */}
        {tab === 'register' && (
          <form onSubmit={handleRegister} className="space-y-4">
            <input type="text" placeholder="Full name" value={regName} onChange={(e) => setRegName(e.target.value)} className={inputClass} required />
            <input type="email" placeholder="Email" value={regEmail} onChange={(e) => setRegEmail(e.target.value)} className={inputClass} required />
            <input type="text" placeholder="Username" value={regUsername} onChange={(e) => setRegUsername(e.target.value)} className={inputClass} required />
            <input type="password" placeholder="Password" value={regPassword} onChange={(e) => setRegPassword(e.target.value)} className={inputClass} required />
            <input type="password" placeholder="Confirm password" value={regPasswordConfirm} onChange={(e) => setRegPasswordConfirm(e.target.value)} className={inputClass} required />
            <input type="date" placeholder="Date of birth" value={regDob} onChange={(e) => setRegDob(e.target.value)} className={inputClass} required />
            <select value={regCountry} onChange={(e) => setRegCountry(e.target.value)} className={inputClass} required>
              <option value="NA">Namibia</option>
              <option value="ZA">South Africa</option>
              <option value="BW">Botswana</option>
              <option value="ZW">Zimbabwe</option>
              <option value="ZM">Zambia</option>
            </select>
            <label className="flex items-center gap-2 text-sm text-gray-400">
              <input type="checkbox" checked={regTerms} onChange={(e) => setRegTerms(e.target.checked)} required />
              I accept the Terms of Service and Privacy Policy
            </label>
            <button type="submit" disabled={loading} className="w-full rounded-lg bg-blue-600 py-3 font-medium text-white transition-colors hover:bg-blue-700 disabled:opacity-50">
              {loading ? 'Creating account...' : 'Create Account'}
            </button>
          </form>
        )}

        {/* Voucher Tab */}
        {tab === 'voucher' && (
          <form onSubmit={handleVoucher} className="space-y-4">
            <input
              type="text"
              placeholder="Voucher code"
              value={voucherCode}
              onChange={(e) => setVoucherCode(e.target.value.toUpperCase())}
              className={inputClass}
              required
            />
            <input
              type="password"
              placeholder="PIN (if required)"
              value={voucherPin}
              onChange={(e) => setVoucherPin(e.target.value)}
              className={inputClass}
            />
            <button type="submit" disabled={loading} className="w-full rounded-lg bg-green-600 py-3 font-medium text-white transition-colors hover:bg-green-700 disabled:opacity-50">
              {loading ? 'Starting session...' : 'Play with Voucher'}
            </button>
          </form>
        )}
      </div>
    </div>
  );
}
```

- [ ] **Step 2: Commit**

```bash
cd /Users/richard/Projects/gambling
git add client/src/pages/login.tsx
git commit -m "feat: add login page with account, register, and voucher tabs"
```

---

## Task 6: Update App.tsx and Clean Up

**Files:**
- Modify: `/Users/richard/Projects/gambling/client/src/App.tsx`
- Delete: `/Users/richard/Projects/gambling/client/src/pages/oauth-callback.tsx`
- Modify: `/Users/richard/Projects/gambling/client/src/pages/home.tsx`

- [ ] **Step 1: Update App.tsx**

Read the current `App.tsx`. Make these changes:

1. Replace `import OAuthCallback from "@/pages/oauth-callback"` with `import LoginPage from "@/pages/login"`
2. In the Router, replace `<Route path="/oauth/callback" component={OAuthCallback} />` with `<Route path="/login" component={LoginPage} />`

- [ ] **Step 2: Delete oauth-callback.tsx**

```bash
rm /Users/richard/Projects/gambling/client/src/pages/oauth-callback.tsx
```

- [ ] **Step 3: Update home.tsx auth redirect**

In `home.tsx`, the auth guard currently calls `login()` which triggers OAuth redirect. Change it to navigate to `/login`:

Replace:
```typescript
const { user, isAuthenticated, isLoading, login } = useSSOAuth();
```
with:
```typescript
const { user, isAuthenticated, isLoading, authMode, voucherSession } = useSSOAuth();
const [, setLocation] = useLocation();
```

And in the auth guard `useEffect`, replace `login()` with `setLocation('/login')`.

Also update the balance display: if `authMode === 'voucher'`, show the voucher session balance. If `authMode === 'account'`, show the wallet balance (existing behavior).

- [ ] **Step 4: Commit**

```bash
cd /Users/richard/Projects/gambling
git add client/src/App.tsx client/src/pages/home.tsx
git add client/src/pages/oauth-callback.tsx  # stages deletion
git commit -m "feat: wire login page into app, remove OAuth callback"
```

---

## Task 7: Update Game Server Auth Middleware for Voucher Tokens

**Files:**
- Modify: `/Users/richard/Projects/chinga-fantasy/app/middlewares/ssoAuth.js`

- [ ] **Step 1: Update ssoAuth.js to handle gs_* tokens**

Read the current `ssoAuth.js`. Add handling for game session tokens at the top of the middleware, before JWKS validation:

```javascript
async function ssoAuth(req, res, next) {
  const authHeader = req.headers.authorization;
  if (!authHeader || !authHeader.startsWith('Bearer ')) {
    return res.status(401).json({ message: 'Authorization token required.' });
  }

  const token = authHeader.substring(7);

  // Game session tokens (voucher login) — pass through without JWKS validation
  if (token.startsWith('gs_')) {
    req.ssoUser = {
      sub: null,  // No user identity for voucher sessions
      tenant_id: req.headers['x-tenant-id'] || null,
      scopes: [],
      accessToken: token,
      isVoucherSession: true,
      sessionToken: token,
    };
    return next();
  }

  // SSO access tokens — validate via JWKS (existing code continues below)
  try {
    // ... existing JWKS validation code ...
```

The key addition is the `gs_` prefix check that creates a special `req.ssoUser` with `isVoucherSession: true` and `sessionToken` set. The game server can use `req.ssoUser.sessionToken` for SSO Game Session API calls.

- [ ] **Step 2: Commit**

```bash
cd /Users/richard/Projects/chinga-fantasy
git add app/middlewares/ssoAuth.js
git commit -m "feat: handle gs_* voucher session tokens in auth middleware"
```

---

## Task 8: Update Frontend queryClient and creditService

**Files:**
- Modify: `/Users/richard/Projects/gambling/client/src/lib/queryClient.ts`
- Modify: `/Users/richard/Projects/gambling/client/src/services/creditService.ts`

- [ ] **Step 1: Update queryClient.ts to send correct token based on auth mode**

Read the current `queryClient.ts`. Update the token selection to check for both account and voucher modes:

```typescript
// Get the appropriate auth token
const accessToken = sessionStorage.getItem('sso_access_token');
const sessionToken = sessionStorage.getItem('sso_game_session');
const token = accessToken || sessionToken;
```

This ensures API requests to the game server include whichever token is available.

- [ ] **Step 2: Update creditService.ts to handle voucher balance**

Read the current `creditService.ts`. Update it to also work with voucher session tokens:

```typescript
export async function fetchUserCredit(): Promise<number> {
  const accessToken = sessionStorage.getItem('sso_access_token');
  const sessionToken = sessionStorage.getItem('sso_game_session');
  const token = accessToken || sessionToken;
  if (!token) return 0;

  try {
    const response = await fetch(`${BACKEND_URL}/api/user/credit`, {
      headers: {
        Authorization: `Bearer ${token}`,
      },
    });

    if (!response.ok) return 0;

    const data = await response.json();
    return parseFloat(data.balance) || 0;
  } catch (error) {
    console.error('Failed to fetch credit:', error);
    return 0;
  }
}
```

- [ ] **Step 3: Commit**

```bash
cd /Users/richard/Projects/gambling
git add client/src/lib/queryClient.ts client/src/services/creditService.ts
git commit -m "feat: support both account and voucher tokens in API requests"
```
