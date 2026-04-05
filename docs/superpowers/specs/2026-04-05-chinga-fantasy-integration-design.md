# Chinga Fantasy Integration Design

## Overview

Integrate Chinga Fantasy (the platform's first game) with the Chinga Games SSO. This turns the Fantasy Node.js backend into a headless game server that delegates authentication, user management, wallets, and tenant identity to the SSO. The SSO admin gains game-specific configuration pages (teams, rounds, jackpot settings).

## Current State

### SSO Platform (Laravel)
- OAuth2/OIDC via Laravel Passport (auth code, PKCE, password, client credentials, refresh)
- Game Session API at `/api/v1/game/*` with debit/credit/balance endpoints
- Wallet system (per-user-per-tenant) with bcmath-safe transactions
- Voucher code system for in-store terminal play
- Multi-tenant architecture with `tenant_id` column scoping
- React/Inertia admin panel with Acumatica UI

### Chinga Fantasy Admin (Node.js/Express + PostgreSQL)
- Own JWT auth, user management, tenant/site tables
- Game logic: rounds (betting → results → dialog phases), team management, odds, bets
- Jackpot pool management and distribution
- Real-time game state via Socket.IO
- EJS admin dashboard for teams, rounds, bank accounts, pool deposits
- Credit system via `user_statements` table (internal wallet)

### Chinga Fantasy Frontend (React/Vite)
- Authenticates directly against Node.js backend via `POST /api/login`
- JWT stored in localStorage
- Socket.IO for real-time game updates
- Multi-tenant via subdomain extraction + `X-Tenant-Subdomain` header

## Design Decisions

1. **Fresh start** — No migration of existing Fantasy users/balances (game is in development)
2. **Per-bet wallet integration** — Each bet calls SSO debit; each win calls SSO credit. SSO wallet is single source of truth for money.
3. **SSO owns tenants** — Fantasy replaces its local tenant/site tables with SSO tenant UUIDs
4. **Unified admin** — Game config (teams, rounds, jackpot) managed in SSO admin UI, not a separate Fantasy admin
5. **Hybrid data split** — Static config in SSO MySQL; runtime game state in Fantasy PostgreSQL
6. **Both play modes** — Online (OAuth2 + wallet) and in-store (terminal + voucher code)

## Architecture

```
┌─────────────────────┐     OAuth2 PKCE       ┌──────────────────────┐
│  Fantasy Frontend    │◄────────────────────►│    SSO Platform      │
│  (React/Vite)        │     Login/Token       │    (Laravel)         │
│                      │                       │                      │
│  Socket.IO ◄─────────┼──────────────────────►│  Admin UI (Inertia)  │
│                      │                       │  - Team mgmt         │
└──────┬───────────────┘                       │  - Round config      │
       │ SSO access token                      │  - Jackpot settings  │
       │ + game session token                  └──────┬───────────────┘
       ▼                                              │ Internal API
┌─────────────────────┐    Debit/Credit        ┌──────▼───────────────┐
│  Fantasy Game Server │◄─────────────────────►│  SSO Game Session    │
│  (Node.js)           │    Game Session API    │  API                 │
│                      │                        │  /api/v1/game/*      │
│  - Rounds/Bets       │                        │  - Wallet service    │
│  - Teams/Odds        │                        │  - Voucher service   │
│  - Jackpot logic     │                        └──────────────────────┘
│  - Socket.IO         │
└──────────────────────┘
```

## Component Design

### 1. Authentication Changes

#### Fantasy Frontend → SSO OAuth2 PKCE

The frontend replaces its current JWT login with OAuth2 Authorization Code + PKCE flow:

- **Remove**: `AuthContext.tsx` login/register calls to `POST /api/login` and `POST /api/auth/register`
- **Add**: OAuth2 PKCE flow against SSO's `/oauth/authorize` and `/oauth/token` endpoints
- **Token storage**: SSO access token in memory (or secure cookie), refresh token for renewal
- **Scopes requested**: `openid profile email wallet gaming:history`
- **User info**: Fetched from SSO's `/api/v1/oauth/userinfo` endpoint

Registration happens on the SSO platform directly. The Fantasy frontend only handles login via OAuth2 redirect.

#### Fantasy Game Server → Token Validation

The Node.js backend needs to validate SSO access tokens on incoming requests:

- **Remove**: Local JWT auth middleware (`checkAuth.js`), local user table lookups
- **Add**: Token validation via either:
  - **JWKS verification** (preferred): Fetch SSO's public keys from `/.well-known/jwks.json`, verify token signature locally. Cache JWKS with TTL.
  - **Token introspection** (fallback): Call SSO's token info endpoint to validate
- Extract `sub` (user UUID), `tenant_id`, and scopes from the validated token
- Attach user context to request for downstream use

#### Terminal/Voucher Flow

No changes to terminal auth — terminals authenticate directly with the SSO via `X-Terminal-Key` header. The Fantasy game server receives a game session token (`gs_*`) for terminal-initiated sessions and uses the SSO Game Session API for all debit/credit operations.

### 2. Wallet Integration (Per-Bet)

Replace Fantasy's internal `user_statements` credit system with SSO Game Session API calls.

#### Game Session Lifecycle

**Online player:**
1. Player authenticates via OAuth2, frontend has SSO access token
2. Frontend calls Fantasy game server to join a round
3. Game server calls SSO `POST /api/v1/game/session/start/wallet` with the player's SSO token to start a game session
4. SSO returns `session_token` (`gs_*`), `balance_start`, `currency`
5. Game server stores `session_token` associated with the player for the round

**Terminal/voucher player:**
1. Terminal calls SSO `POST /api/v1/game/session/start/terminal` with `game_id`, `code`, `pin`
2. SSO returns `session_token`, `balance_start`, `currency`
3. Terminal passes `session_token` to Fantasy game server

#### Bet Placement (Debit)

When a player places a bet:
1. Fantasy game server receives bet request with amount and team selections
2. Game server calls SSO `POST /api/v1/game/debit` with:
   - `session_token` (in Authorization header as Bearer)
   - `amount`: bet amount
   - `reference`: unique bet reference (e.g., `bet_{bet_uuid}`)
3. If SSO returns success → bet is recorded in Fantasy's `bets` table
4. If SSO returns insufficient balance → reject bet, notify player

#### Win Payout (Credit)

When a round resolves and a player wins:
1. Fantasy calculates winning amount based on odds
2. Game server calls SSO `POST /api/v1/game/credit` with:
   - `session_token`
   - `amount`: winning amount
   - `reference`: unique win reference (e.g., `win_{bet_uuid}`)
3. Win recorded in Fantasy's `winnings` table

#### Jackpot Distribution (Credit)

When jackpot is distributed to winners:
1. For each jackpot winner, game server calls SSO `POST /api/v1/game/credit` with:
   - `session_token`
   - `amount`: jackpot share
   - `reference`: `jackpot_{round_uuid}_{user_uuid}`

#### Balance Display

Frontend fetches balance from SSO via `GET /api/v1/game/balance` using the game session token, or via the SSO's `/api/v1/oauth/userinfo` with wallet scope for the overall wallet balance.

### 3. Tenant Integration

#### Replace Fantasy's Tenant System

- **Remove**: Fantasy's `tenants` and `sites` tables, `tenantResolver.js` middleware, `tenantScoping.js` utility
- **Add**: Use SSO `tenant_uuid` as the scoping key in all Fantasy tables
- Fantasy's `tenant_id` columns become `tenant_uuid` (VARCHAR) referencing the SSO tenant's UUID
- The `sites` concept maps to SSO's `venues` — if site-level scoping is needed, use SSO venue UUIDs

#### Tenant Resolution in Fantasy

The Fantasy game server resolves tenant context from:
1. **Game session token**: The SSO game session carries `tenant_id`. Fantasy extracts it from the session info (`GET /api/v1/game/session/info`).
2. **SSO access token**: The validated JWT contains tenant context in its claims.
3. **Forwarded header**: Frontend passes `X-Tenant-ID` (SSO tenant UUID) in requests.

### 4. Data Split (Hybrid)

#### Moves to SSO MySQL (managed via SSO admin)

| Data | SSO Table | Notes |
|------|-----------|-------|
| Teams | `fantasy_teams` | Global team roster (name, logo, stats) |
| Game settings | `game.settings` JSON + `tenant_games.custom_settings` | Per-tenant: MAX_BET, MIN_BET, DISPLAY_TEAMS, round durations, MIN_JACKPOT_AMOUNT |
| Jackpot config | Part of game/tenant settings | Thresholds, distribution rules |

#### Stays in Fantasy PostgreSQL (runtime game state)

| Data | Table | Notes |
|------|-------|-------|
| Rounds | `rounds` | Active/historical rounds, scoped by `tenant_uuid` |
| Round teams | `round_teams` | Teams assigned to a round with odds, bonus flag |
| Bets | `bets` | Player bets per round |
| Bet teams | `bet_teams` | Team selections per bet |
| Jackpot pool | `jackpot_transactions` | Running jackpot pool balance |
| Pool transactions | `pool_transactions` | Pool deposits/withdrawals |

#### Config Sync

The Fantasy game server fetches team roster and game settings from the SSO at:
- Server startup (cache locally)
- Round creation (refresh if stale, TTL-based)
- On-demand when SSO admin pushes config changes (webhook or polling)

The SSO exposes internal API endpoints for the Fantasy game server:
- `GET /api/v1/games/{game_uuid}/config` — game settings + tenant overrides
- `GET /api/v1/games/{game_uuid}/teams` — team roster

These endpoints are authenticated via client credentials (server-to-server OAuth2 flow).

### 5. SSO Admin Pages for Chinga Fantasy

New pages in the SSO React/Inertia admin under a "Chinga Fantasy" section (visible when the game is enabled for a tenant).

#### Phase 1 (Essential for launch)

**Team Management** (`/admin/games/fantasy/teams`)
- CRUD for teams: name, logo, country/league, active status
- Bulk import/export
- Global (not tenant-scoped) — all tenants share the same team pool

**Game Settings** (`/admin/games/fantasy/settings`)
- Per-tenant configuration:
  - `MIN_BET_AMOUNT`, `MAX_BET_AMOUNT`
  - `DISPLAY_TEAMS` (number of teams shown per round)
  - Round phase durations (betting, results, dialog seconds)
  - `MIN_JACKPOT_AMOUNT` (threshold for distribution)
  - Jackpot percentage (% of each bet that goes to pool)

**Round Monitor** (`/admin/games/fantasy/rounds`)
- Live view of current/recent rounds per tenant
- Round details: teams, odds, results, bet count, total wagered, total paid out
- Read-only (rounds are managed by the game server)

#### Phase 2 (Post-launch)

- Jackpot pool management (manual adjustments, force distribution)
- Betting simulation/testing tools
- Player leaderboards and round analytics
- Bank account / pool deposit management (if still needed)

### 6. Fantasy Game Server Changes

#### Remove
- `app/controllers/AuthController.js` — login, register, JWT refresh
- `app/middlewares/checkAuth.js` — local JWT validation
- `app/middlewares/tenantResolver.js` — local tenant resolution
- `app/utils/tenantScoping.js` — local tenant query builder
- `app/models/User.js` — local user model (user data comes from SSO)
- `app/models/UserStatement.js` — local wallet (replaced by SSO wallet)
- `app/models/Tenant.js` — local tenant model
- `app/models/Site.js` — local site model (use SSO venues)
- `app/models/BankAccount.js`, `BankTransaction.js`, `PoolDeposit.js` — financial management moves to SSO or is deferred
- `app/models/OwnerProfit.js`, `OwnerWithdrawal.js`, `ProfitTransaction.js` — revenue handled by SSO
- `app/routes/web.js` — EJS admin routes (admin moves to SSO)
- All EJS views

#### Modify
- `app/models/Bet.js` — Replace `user_id` with `user_uuid` (SSO user UUID). Remove credit balance check (SSO debit handles this). Add `session_token` field.
- `app/models/Round.js` — Replace `tenant_id`/`site_id` with `tenant_uuid`. Fetch team config from SSO API instead of local DB.
- `app/models/RoundTeam.js` — Reference teams by SSO team ID/UUID
- `app/models/Team.js` — Becomes a local cache of SSO team data, or removed entirely if fetched on-demand
- `app/models/Winning.js` — Replace `user_id` with `user_uuid`. Winning payouts call SSO credit.
- `app/models/JackpotTransaction.js` — Keep for pool tracking, but payouts go through SSO credit
- `app/models/Settings.js` — Replace with config fetched from SSO API
- `app/services/timerService.js` — Fetch round config (phase durations) from SSO-provided settings
- `app/routes/api.js` — Remove auth routes, add SSO token validation middleware, update tenant scoping

#### Add
- `app/middlewares/ssoAuth.js` — Validate SSO access tokens via JWKS
- `app/middlewares/ssoTenant.js` — Extract tenant UUID from validated token
- `app/services/ssoClient.js` — HTTP client for SSO Game Session API (debit, credit, balance, session start/end) and config API (teams, settings)
- `app/services/sessionManager.js` — Manage mapping between SSO game sessions and Fantasy rounds/players

### 7. Fantasy Frontend Changes

#### Remove
- `context/AuthContext.tsx` — Replace with SSO OAuth2 context
- `pages/auth.tsx` — Login/register page (SSO handles this)
- `services/creditService.ts` — Local credit fetching (use SSO balance)

#### Modify
- `context/TenantContext.tsx` — Tenant resolution stays similar (subdomain-based), but tenant data comes from SSO
- `lib/queryClient.ts` — `apiRequest` sends SSO access token instead of local JWT. Add SSO token refresh logic.
- `services/betService.ts` — Bet placement calls go to Fantasy game server (which handles SSO debit internally)
- `services/socketService.ts` — Socket connection includes SSO access token for authentication
- `App.tsx` — Add OAuth2 callback route, protected route wrapper

#### Add
- `lib/ssoAuth.ts` — OAuth2 PKCE flow: authorize URL construction, token exchange, token refresh, logout
- `context/SSOAuthContext.tsx` — Auth state management with SSO tokens
- `pages/oauth-callback.tsx` — Handle OAuth2 redirect back from SSO

### 8. SSO Platform Changes

#### New Models/Migrations
- `fantasy_teams` table: `id`, `uuid`, `name`, `short_name`, `logo_url`, `country`, `league`, `is_active`, timestamps
- `FantasyTeam` model (not tenant-scoped — global resource)

#### New Controllers
- `Admin/Games/FantasyTeamController.php` — CRUD for teams
- `Admin/Games/FantasySettingsController.php` — Per-tenant game settings management
- `Admin/Games/FantasyRoundController.php` — Round monitoring (reads from Fantasy API)

#### New API Endpoints (Internal, for Fantasy game server)
- `GET /api/v1/games/{game_uuid}/teams` — List active teams
- `GET /api/v1/games/{game_uuid}/config` — Game config with tenant overrides
- Authenticated via client credentials OAuth2 flow

#### New Admin Routes/Pages
- `/admin/games/fantasy/teams` — Team management
- `/admin/games/fantasy/settings` — Game settings per tenant
- `/admin/games/fantasy/rounds` — Round monitor

#### OAuth Client Setup
- Create an OAuth client for the Fantasy frontend (public client, PKCE flow)
- Create an OAuth client for the Fantasy game server (confidential client, client credentials flow)
- Register "Chinga Fantasy" as a Game record in the `games` table

## SSO Game Session API Adaptations

The existing Game Session API was designed for per-action games (slots). For Fantasy's round-based gameplay, note:

- **Session duration**: A Fantasy session may span multiple rounds. The 30-minute inactivity timeout works naturally — active play keeps the session alive.
- **Debit reference uniqueness**: Each bet gets a unique reference (`bet_{uuid}`), preventing double-debits via the existing idempotency check.
- **Multiple credits per session**: A player can win multiple rounds in one session. Each win is a separate credit call with a unique reference.

No changes needed to the existing Game Session API — it already supports this usage pattern.

## Error Handling

- **SSO unavailable during bet**: Fantasy game server rejects the bet, notifies player. No bet is recorded.
- **SSO unavailable during payout**: Fantasy records the pending payout and retries. Add a `payout_status` column to `winnings` table (pending, paid, failed).
- **Token expiry mid-game**: Frontend handles refresh token flow transparently. Game server returns 401, frontend refreshes and retries.
- **Double-debit protection**: SSO's reference-based idempotency prevents duplicate charges.
- **Session timeout**: If SSO session times out during a round, game server starts a new session for the next bet.

## Testing Strategy

- **Unit tests**: SSO team/settings CRUD, Fantasy SSO client service
- **Integration tests**: Full bet→debit→resolve→credit flow against SSO staging
- **E2E tests**: OAuth2 login → place bet → win → verify balance in SSO wallet
- **Load tests**: Socket.IO + SSO API calls under concurrent player load
