# Chinga Games SSO — Cleanup, Wallet & Unified Game API Design

**Date:** 2026-03-16
**Status:** Draft
**Approach:** Clean First, Build Second (Approach A)

---

## Context

Chinga Games SSO is a multi-tenant OAuth2/OIDC identity platform for the Namibian gaming industry. Betting shop resellers (tenants) resell games to end users. Each tenant can have multiple venue locations and an online platform. In-store users authenticate via voucher codes; online users authenticate via email/password through OAuth2.

The codebase has accumulated features beyond the MVP scope (KYC, responsible gambling, SMS MFA, phone verification, login notifications, device detection, form configuration). These need to be removed. The system also lacks a wallet for online users and a unified game integration API.

## Vision

- One SSO authenticates all games via OAuth2
- Credits are centrally managed — games never hold balances
- Each betting shop (tenant) is independent: own players, own credits, own venues
- Two player types: online (wallet) and in-store (voucher code) — same debit/credit mechanics
- Games interact with a single API regardless of player type
- Revenue is split 30% Chinga / 70% tenant, calculated via scheduled jobs

---

## Phase 1: Cleanup

Remove all features not required for MVP. This reduces the codebase by ~40%.

### Features to Remove

#### KYC System
- `app/Models/KycDocument.php`
- `app/Services/KycService.php`
- `app/Http/Controllers/Api/KycController.php`
- `app/Http/Controllers/Admin/KycReviewController.php`
- `app/Http/Controllers/Settings/KycController.php`
- `app/Http/Middleware/CheckKycLevel.php`
- `resources/js/Pages/settings/kyc.tsx`
- `resources/js/Pages/admin/kyc.tsx`
- KYC-related routes in `routes/api.php`, `routes/admin.php` (including KYC review routes and `reports/kyc`), `routes/settings.php`
- Migration for `kyc_documents` table
- **Keep:** `kyc_level` column on `users` table (dormant, costs nothing)

#### Responsible Gambling System
- `app/Models/ResponsibleGamblingSetting.php`
- `app/Models/SelfExclusion.php`
- `app/Services/ResponsibleGamblingService.php`
- `app/Http/Controllers/Api/ResponsibleGamblingController.php`
- `app/Http/Controllers/Settings/ResponsibleGamblingController.php`
- `app/Http/Middleware/CheckSelfExclusion.php`
- `resources/js/Pages/settings/responsible-gambling.tsx`
- Responsible gambling routes in `routes/api.php`, `routes/admin.php` (including `reports/responsible-gambling`), `routes/settings.php`
- Migrations for `responsible_gambling_settings` and `self_exclusions` tables
- Remove `self_excluded` from User status enum (unreachable after removal)
- Remove `isSelfExcluded()` check in `FortifyServiceProvider.configureAuthentication()`

#### SMS MFA
- `app/Services/SmsMfaService.php`
- `app/Http/Controllers/Auth/SmsMfaController.php`
- SMS MFA routes in `routes/settings.php`
- `sms_mfa_phone`, `sms_mfa_enabled`, `preferred_mfa_method` columns on `users` table

#### Phone Verification
- `app/Models/PhoneVerification.php`
- `app/Services/PhoneVerificationService.php`
- `app/Http/Controllers/Auth/PhoneVerificationController.php`
- Phone verification routes in `routes/api.php`
- Migration for `phone_verifications` table

#### Login Notifications & Device Detection
- `app/Models/LoginNotification.php`
- `app/Services/LoginNotificationService.php`
- `app/Services/DeviceDetectionService.php`
- `app/Notifications/NewDeviceLogin.php`
- `app/Notifications/NewLocationLogin.php`
- Migration for `login_notifications` table
- Remove `processLoginNotification` method and all `DeviceDetectionService`/`LoginNotificationService` calls in `FortifyServiceProvider`

#### Form Configuration / Personalization
- `app/Models/FormConfiguration.php`
- `app/Models/SavedFilter.php`
- `app/Services/FormConfigService.php`
- `app/Http/Controllers/FormConfigController.php`
- `config/acumatica-ui.php`
- `routes/form-config.php`
- Migrations for `form_configurations` and `saved_filters` tables

### What Stays
- Multi-tenancy (Tenant, BelongsToTenant, TenantScope, ResolveTenant middleware)
- RBAC (Role, Permission, HasRoles, user_roles)
- User model (email/password auth, TOTP 2FA via Fortify)
- Venues, VenueStaff, VenueTerminals, VenueShifts
- Voucher codes, voucher transactions (refactored in Phase 3)
- Games, tenant_games pivot
- Revenue records
- Security audit logs, login attempts, account lockout
- User sessions (SessionManagementService)
- OAuth2/OIDC (Passport)
- All tenant admin + platform admin UI and APIs
- `EnsurePlatformAdmin`, `EnsureTenantAdmin`, `IsAdmin`, `IsSuperAdmin` middleware (RBAC-based)

### Cleanup Rules
- Drop migrations for removed tables (create new migration that drops tables if they exist)
- Remove all route registrations for deleted features (including imports at top of route files)
- Remove middleware registrations for deleted middleware (`CheckKycLevel`, `CheckSelfExclusion`)
- Clean up User model: remove relationships, methods, and casts for deleted features
- Clean up Tenant model: remove relationships to deleted models (`kycDocuments`, `selfExclusions`)
- Clean up Inertia shared data (HandleInertiaRequests) if it references deleted features
- Clean up FortifyServiceProvider login pipeline: remove `processLoginNotification`, device detection, login notification, self-exclusion check
- Remove unused imports throughout
- Clean up admin report routes that reference removed features (`reports/kyc`, `reports/responsible-gambling`)

---

## Phase 2: Wallet System

### Monetary Value Convention

All monetary amounts use `string` type in PHP method signatures and `decimal(15,2)` in the database. This avoids IEEE 754 floating-point imprecision which is unacceptable for a gambling platform. Internally, use `bcmath` functions (`bcadd`, `bcsub`, `bccomp`) for arithmetic. Eloquent models cast decimal columns with `decimal:2`.

This convention applies to WalletService, GameSessionService, VoucherCodeService (retrofit), VoucherCode model methods (`hasSufficientBalance`, etc.), and any code handling money. The existing VoucherCodeService and VoucherCode model use `float` throughout — both must be retrofitted to `string`/`bcmath` as part of Phase 3.

### `wallets` Table

```
id              bigint PK auto-increment
uuid            uuid unique
tenant_id       FK -> tenants(id), indexed
user_id         FK -> users(id)
balance         decimal(15,2) default 0
currency        varchar(3) -- inherits from tenant, stored for denormalization
status          enum('active','frozen','closed') default 'active'
total_deposited decimal(15,2) default 0
total_withdrawn decimal(15,2) default 0
total_won       decimal(15,2) default 0
total_lost      decimal(15,2) default 0
created_at      timestamp
updated_at      timestamp
```

**Constraints:**
- Composite unique: `(tenant_id, user_id)` — one wallet per user per tenant
- Uses `BelongsToTenant` trait
- `balance` must be >= 0 (enforced in service, not DB constraint)
- `currency` is copied from `tenants.currency` at wallet creation time (denormalized for query convenience; tenant currency is authoritative)

### `wallet_transactions` Table

```
id              bigint PK auto-increment
uuid            uuid unique
wallet_id       FK -> wallets(id), indexed
game_session_id FK -> game_sessions(id), nullable, indexed
type            enum('deposit','withdrawal','bet','win','adjustment')
amount          decimal(15,2)
balance_before  decimal(15,2)
balance_after   decimal(15,2)
reference       varchar(255) nullable, indexed
description     varchar(255) nullable
performed_by    FK -> users(id), nullable -- staff who loaded/cashed out
metadata        json nullable
created_at      timestamp
updated_at      timestamp
```

### Wallet Model

```php
class Wallet extends Model
{
    use HasFactory, BelongsToTenant;

    // Route key: uuid
    // Status enum: active, frozen, closed
    // Auto-generate UUID on creation
    // Cast balance, total_* columns as decimal:2

    // Relationships:
    // - user() -> BelongsTo(User)
    // - transactions() -> HasMany(WalletTransaction)
    // - gameSessions() -> MorphMany(GameSession, 'source')

    // Methods:
    // - isActive(): bool
    // - hasSufficientBalance(string $amount): bool -- uses bccomp
    // - hasBalance(): bool
}
```

### WalletTransaction Model

```php
class WalletTransaction extends Model
{
    use HasFactory;

    // Route key: uuid
    // Type enum: deposit, withdrawal, bet, win, adjustment
    // Auto-generate UUID on creation
    // Cast amount, balance_before, balance_after as decimal:2

    // Relationships:
    // - wallet() -> BelongsTo(Wallet)
    // - gameSession() -> BelongsTo(GameSession)
    // - performedBy() -> BelongsTo(User)

    // Methods:
    // - isCredit(): bool -- deposit, win, positive adjustment
    // - isDebit(): bool -- withdrawal, bet, negative adjustment
}
```

### Wallet Creation

Wallets are created lazily via `User::getOrCreateWallet()` — called when a game session is started for an online user. This avoids creating empty wallets for users who never play. Tenant admins can also see users without wallets and trigger wallet creation via the admin deposit endpoint (depositing creates the wallet if it doesn't exist).

### WalletService

All operations use DB transactions. Inside each transaction, the wallet row is re-fetched with pessimistic locking (`Wallet::lockForUpdate()->find($wallet->id)`) to prevent TOCTOU race conditions. The wallet model passed as a parameter is used only for its ID — the locked re-fetch is the authoritative read.

```php
class WalletService
{
    // Create wallet for user
    createWallet(User $user, string $currency = null): Wallet
    // currency defaults to tenant's currency if null

    // Staff loads credits onto user's wallet
    deposit(Wallet $wallet, string $amount, ?User $performedBy, ?string $reference): WalletTransaction

    // Staff cashes out from user's wallet
    withdraw(Wallet $wallet, string $amount, ?User $performedBy, ?string $reference): WalletTransaction

    // Game bet — called by GameSessionService
    debit(Wallet $wallet, string $amount, GameSession $session, ?string $reference): WalletTransaction

    // Game win — called by GameSessionService
    credit(Wallet $wallet, string $amount, GameSession $session, ?string $reference): WalletTransaction

    // Get current balance (string for precision)
    getBalance(Wallet $wallet): string
```

**Validation rules:**
- `deposit`: amount > 0, wallet must be active
- `withdraw`: amount > 0, wallet must be active, sufficient balance
- `debit`: amount > 0, wallet must be active, sufficient balance
- `credit`: amount > 0, wallet must be active

**Idempotency:** Debit and credit operations reject duplicate `reference` values within the same game session. If a duplicate is detected, the existing transaction is returned instead of creating a new one. This prevents double-debits from game retry logic.

### User Model Changes

Add to User model:
```php
// Relationship
wallet(): HasOne(Wallet)

// Helper — creates wallet on first call, returns existing on subsequent calls
getOrCreateWallet(string $currency = null): Wallet
```

### Wallet Management Endpoints (for tenant admin / staff)

Added to tenant admin API routes:

```
GET    /api/v1/admin/wallets              -- List wallets with search/filter
GET    /api/v1/admin/wallets/{uuid}       -- Wallet details + recent transactions
POST   /api/v1/admin/wallets/{uuid}/deposit   -- Load credits
POST   /api/v1/admin/wallets/{uuid}/withdraw  -- Cash out
POST   /api/v1/admin/wallets/{uuid}/freeze    -- Freeze wallet
POST   /api/v1/admin/wallets/{uuid}/activate  -- Unfreeze wallet
```

---

## Phase 3: Unified Game Session API

### `game_sessions` Table (replaces `voucher_sessions`)

```
id              bigint PK auto-increment
uuid            uuid unique
session_token   varchar(255) unique, indexed -- prefixed: gs_<random64>
tenant_id       FK -> tenants(id), indexed
game_id         FK -> games(id), indexed
source_type     varchar(255) -- 'App\Models\Wallet' or 'App\Models\VoucherCode' (polymorphic)
source_id       bigint -- wallet.id or voucher_code.id (polymorphic)
terminal_id     FK -> venue_terminals(id), nullable
ip_address      varchar(45) nullable
balance_start   decimal(15,2)
balance_end     decimal(15,2) nullable
started_at      timestamp
ended_at        timestamp nullable
end_reason      enum('logout','timeout','cashed_out','forced') nullable
created_at      timestamp
updated_at      timestamp
```

**Indexes:**
- `(source_type, source_id)` — polymorphic lookup
- `session_token` — unique, used for all game API calls
- `(tenant_id, game_id)` — revenue aggregation queries

**Session token format:** Tokens are prefixed with `gs_` followed by 64 random characters (e.g., `gs_a1b2c3...`). The `gs_` prefix allows the `AuthenticateGameSession` middleware to quickly identify game session tokens vs OAuth bearer tokens without database lookups. If the bearer token does not start with `gs_`, the middleware skips and lets Passport handle it.

**Session timeout:** Sessions that have been inactive for 30 minutes are considered timed out. The `AuthenticateGameSession` middleware checks `updated_at` (which is touched on every debit/credit) against a 30-minute threshold. A scheduled command `game-sessions:cleanup` runs hourly to formally end timed-out sessions (set `ended_at`, `end_reason = 'timeout'`, record `balance_end`).

### GameSession Model

```php
class GameSession extends Model
{
    use HasFactory;

    // NOTE: Does NOT use BelongsToTenant trait.
    // Reason: Session lookups are by globally-unique session_token, not tenant-scoped.
    // The tenant_id column exists for revenue aggregation queries, which use
    // explicit where clauses rather than the global scope.
    // The tenant_id is derived from the source (wallet.tenant_id or voucher_code.tenant_id)
    // at session creation time.

    // Route key: uuid
    // Auto-generate UUID on creation
    // Auto-generate session_token with gs_ prefix on creation
    // Cast balance_start, balance_end as decimal:2

    // Relationships:
    // - source() -> MorphTo (Wallet or VoucherCode)
    // - game() -> BelongsTo(Game)
    // - terminal() -> BelongsTo(VenueTerminal)
    // - walletTransactions() -> HasMany(WalletTransaction)
    // - voucherTransactions() -> HasMany(VoucherTransaction)

    // Methods:
    // - isActive(): bool -- ended_at is null AND not timed out (updated_at within 30 min)
    // - end(reason, balanceEnd): void
    // - getNetResultAttribute(): string (balance_end - balance_start, using bcsub)
    // - getDurationMinutesAttribute(): float
}
```

### GameSessionService

The `tenant_id` on game sessions is derived from the source: `$wallet->tenant_id` for wallet sessions, `$voucherCode->tenant_id` for voucher sessions. It is set at creation time, not from the current tenant context.

```php
class GameSessionService
{
    // Start session for an online user (via OAuth token)
    // Validates: game exists, game is active, game is enabled for tenant (tenant_games pivot)
    startWalletSession(User $user, Game $game, ?string $ipAddress): GameSession

    // Start session for a voucher code (via terminal)
    // Validates: game exists, game is active, game is enabled for tenant, code is active, PIN matches
    startVoucherSession(VoucherCode $code, Game $game, VenueTerminal $terminal, ?string $pin, ?string $ipAddress): GameSession

    // End session
    endSession(string $sessionToken, string $reason): GameSession

    // Debit (bet) — delegates to WalletService or VoucherCodeService based on source_type
    debit(string $sessionToken, string $amount, ?string $reference): array

    // Credit (win) — delegates to WalletService or VoucherCodeService based on source_type
    credit(string $sessionToken, string $amount, ?string $reference): array

    // Get balance — delegates based on source_type
    getBalance(string $sessionToken): array

    // Get session info
    getSessionInfo(string $sessionToken): GameSession

    // Get recent transactions for session
    getTransactions(string $sessionToken, int $limit = 20): Collection
```

**VoucherCodeService changes:** The existing `VoucherCodeService.debit()` and `credit()` methods will be updated to accept a `GameSession` parameter instead of using `$voucherCode->current_session_id`. The `session_id` column on `voucher_transactions` will be renamed to `game_session_id` and will reference the `game_sessions` table. The existing `VoucherCodeService` will also be retrofitted with:
- Pessimistic locking (`lockForUpdate`) to match the WalletService pattern
- `bcmath` arithmetic replacing all native PHP float arithmetic (`$a + $b` becomes `bcadd($a, $b, 2)`)
- `string` type for all monetary method parameters and return values

### AuthenticateGameSession Middleware

Replaces `AuthenticateVoucherSession`. Validates the bearer token as a game session token:

1. Extract bearer token from Authorization header
2. Check for `gs_` prefix — if not present, skip (not a game session token)
3. Look up `game_sessions` by `session_token`
4. Verify session is active (not ended, `updated_at` within 30-minute timeout)
5. Verify source (wallet/voucher) is still active
6. If terminal session, verify terminal is still active
7. Touch `updated_at` to extend timeout
8. Bind session to request (`$request->gameSession`)

### Unified Game API Endpoints

All routes prefixed with `/api/v1/game`, named `api.game.*`.

**Session start — two separate routes for clarity:**

```
POST   /api/v1/game/session/start/wallet    -- Start wallet session (OAuth token required)
POST   /api/v1/game/session/start/terminal  -- Start voucher session (terminal key required)
```

Wallet session start (middleware: `auth:api`):
```json
// Request
{ "game_id": "game-uuid" }

// Response
{
    "session_token": "gs_abc123...",
    "balance": "150.00",
    "currency": "NAD",
    "game": { "uuid": "...", "name": "..." }
}
```
User identified from OAuth bearer token. Wallet created lazily if needed.

Voucher session start (middleware: `AuthenticateTerminal`):
```json
// Request
{
    "game_id": "game-uuid",
    "code": "ABC123",
    "pin": "1234"
}

// Response
{
    "session_token": "gs_def456...",
    "balance": "75.00",
    "currency": "NAD",
    "game": { "uuid": "...", "name": "..." }
}
```
Terminal identified from X-Terminal-Key header.

**Gameplay endpoints** (middleware: `AuthenticateGameSession`):
```
POST   /api/v1/game/session/end      -- End session
GET    /api/v1/game/session/info      -- Session details
GET    /api/v1/game/balance           -- Current balance
POST   /api/v1/game/debit             -- Place bet
POST   /api/v1/game/credit            -- Pay winnings
GET    /api/v1/game/transactions      -- Recent transactions
```

Debit request/response:
```json
// Request
{ "amount": "10.00", "reference": "round-12345" }

// Response
{ "success": true, "balance": "140.00", "transaction_id": "txn-uuid" }
```

Credit request/response:
```json
// Request
{ "amount": "25.00", "reference": "round-12345-win" }

// Response
{ "success": true, "balance": "165.00", "transaction_id": "txn-uuid" }
```

Note: Credit has no balance check (winnings always succeed). Debit fails with 402 if insufficient balance.

**Session start validation rules:**
- `game_id` is required, must resolve to an active Game via UUID
- Game must be enabled for the session's tenant (`tenant_games` pivot, `enabled = true`)
- For wallet sessions: user must have an active wallet (or one will be created)
- For voucher sessions: code must be active and not expired, PIN must match (if code has PIN)
- Only one active session per source at a time (prevents double-play)

### Endpoints to Remove

All old terminal player and voucher session endpoints:
- `POST /venue/auth/code` — replaced by `POST /api/v1/game/session/start/terminal`
- `GET /venue/player/balance` — replaced by `GET /api/v1/game/balance`
- `POST /venue/player/debit` — replaced by `POST /api/v1/game/debit`
- `POST /venue/player/credit` — replaced by `POST /api/v1/game/credit`
- `GET /venue/player/transactions` — replaced by `GET /api/v1/game/transactions`
- `POST /venue/player/transaction` — replaced by debit/credit
- `GET /venue/player/can-play` — replaced by balance check in debit
- `POST /venue/auth/code/verify-pin` — handled during session start
- `POST /venue/auth/code/logout` — replaced by `POST /api/v1/game/session/end`
- `GET /venue/auth/code/session` — replaced by `GET /api/v1/game/session/info`

### Endpoints to Keep

- `POST /terminal/auth` — terminal authentication (unchanged)
- `POST /terminal/heartbeat` — terminal health (unchanged)
- All venue staff endpoints (`/venue/auth/*`, `/venue/codes/*`) — operational, not game integration

### Controllers to Remove/Replace

- `app/Http/Controllers/Terminal/PlayerController.php` — removed
- `app/Http/Controllers/Terminal/AuthController.php` — remove voucher session methods, keep terminal auth + heartbeat
- `app/Http/Middleware/AuthenticateVoucherSession.php` — removed (replaced by AuthenticateGameSession)

### New Controllers/Middleware

- `app/Http/Controllers/Api/GameSessionController.php` — handles all `/api/v1/game/*` endpoints
- `app/Http/Middleware/AuthenticateGameSession.php` — new middleware

---

## Phase 4: Revenue Calculation

### Transaction Type Alignment

Before implementing revenue calculation, align transaction type naming across both systems:
- `wallet_transactions` uses `type = 'bet'` and `type = 'win'`
- `voucher_transactions` currently uses `type = 'loss'` for bets and `type = 'win'` for wins

Rename `voucher_transactions.type` value `'loss'` to `'bet'` for consistency. This simplifies the revenue query to a single type name across both tables. Since the system is pre-production, this is a safe migration.

Note: The `total_lost` summary column on `voucher_codes` and `wallets` is kept as-is. "Lost" describes the player's cumulative outcome (money lost through bets), while "bet" describes the transaction action. This is an intentional distinction — the column tracks net result, not action type.

### CalculateRevenueCommand

Artisan command: `php artisan revenue:calculate {--period=daily}`

Scheduled to run daily.

**Logic:**
1. For each active tenant:
2. For each game with activity in the period:
3. Query `game_sessions` for the tenant + game in the period
4. For wallet-backed sessions: sum from `wallet_transactions` where `type = 'bet'` (total bets) and `type = 'win'` (total wins)
5. For voucher-backed sessions: sum from `voucher_transactions` where `type = 'bet'` (total bets) and `type = 'win'` (total wins)
6. Calculate:
   - `total_bets` = sum of all bet amounts
   - `total_wins` = sum of all win amounts
   - `gross_gaming_revenue` = total_bets - total_wins
   - If GGR is negative (players won more than they bet), record as negative. Negative GGR means negative shares. This is normal for short periods and balances out over time. No flooring to zero.
   - `revenue_share_pct` = tenant's `revenue_share_pct` (default 70)
   - `tenant_share` = GGR * (revenue_share_pct / 100)
   - `chinga_share` = GGR - tenant_share
7. Upsert into `tenant_revenue_records` (unique on tenant_id + game_id + period_type + period_start)

**No table changes needed** — `tenant_revenue_records` already has all required columns.

### Session Cleanup Command

Artisan command: `php artisan game-sessions:cleanup`

Scheduled to run hourly.

**Logic:**
1. Find all `game_sessions` where `ended_at IS NULL` and `updated_at < now() - 30 minutes`
2. For each stale session:
   - Get current balance from source (wallet or voucher code)
   - Set `ended_at = now()`, `end_reason = 'timeout'`, `balance_end = current balance`
   - If source is voucher code, update its status back from `in_use` to `active`, and clear `current_terminal_id` and `current_session_id`

### Schedule Registration

In `routes/console.php` or a service provider:
```php
Schedule::command('revenue:calculate --period=daily')->dailyAt('02:00');
Schedule::command('game-sessions:cleanup')->hourly();
```

---

## Migration Strategy

Since no games are connected yet and the system is not in production:

1. **Create a cleanup migration** that:
   - Drops tables: `kyc_documents`, `responsible_gambling_settings`, `self_exclusions`, `phone_verifications`, `login_notifications`, `form_configurations`, `saved_filters`
   - Removes columns from `users`: `sms_mfa_phone`, `sms_mfa_enabled`, `preferred_mfa_method`
   - Keeps `phone` and `phone_verified_at` on `users` as contact info (just removes the verification system)

2. **Create a wallet migration** that:
   - Creates `wallets` table
   - Creates `wallet_transactions` table

3. **Create a game sessions migration** that:
   - Drops `voucher_sessions` table
   - Creates `game_sessions` table
   - On `voucher_codes`: drops FK `current_session_id` referencing `voucher_sessions`, re-adds `current_session_id` as FK to `game_sessions` (nullable)
   - On `voucher_transactions`: drops FK on `session_id`, renames `session_id` to `game_session_id`, adds FK to `game_sessions`
   - Renames `voucher_transactions.type` value `'loss'` to `'bet'`
   - Since no production data exists, these are clean structural changes with no data migration needed

---

## Out of Scope

- Payment gateway integration (future — staff loads credits for now)
- Real-time revenue calculation (scheduled jobs only)
- KYC (removed entirely)
- Responsible gambling (removed entirely)
- SMS MFA / phone verification (removed entirely)
- Game-side implementation (games are separate projects)
- Mobile app (future)
