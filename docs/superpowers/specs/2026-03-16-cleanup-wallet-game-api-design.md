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
- `resources/js/Pages/settings/kyc.tsx`
- `resources/js/Pages/admin/kyc.tsx`
- KYC-related routes in `routes/api.php`, `routes/admin.php`, `routes/settings.php`
- Migration for `kyc_documents` table
- **Keep:** `kyc_level` column on `users` table (dormant, costs nothing)

#### Responsible Gambling System
- `app/Models/ResponsibleGamblingSetting.php`
- `app/Models/SelfExclusion.php`
- `app/Services/ResponsibleGamblingService.php`
- `app/Http/Controllers/Api/ResponsibleGamblingController.php`
- `app/Http/Controllers/Settings/ResponsibleGamblingController.php`
- `resources/js/Pages/settings/responsible-gambling.tsx`
- Responsible gambling routes in `routes/api.php`, `routes/settings.php`
- Migrations for `responsible_gambling_settings` and `self_exclusions` tables
- `CheckSelfExclusion` middleware

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
- References in `FortifyServiceProvider` login pipeline

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
- Voucher codes, voucher sessions (refactored in Phase 3), voucher transactions
- Games, tenant_games pivot
- Revenue records
- Security audit logs, login attempts, account lockout
- User sessions (SessionManagementService)
- OAuth2/OIDC (Passport)
- All tenant admin + platform admin UI and APIs

### Cleanup Rules
- Drop migrations for removed tables (create new migration that drops tables if they exist)
- Remove all route registrations for deleted features
- Remove middleware registrations for deleted middleware
- Clean up User model: remove relationships, methods, and casts for deleted features
- Clean up Tenant model: remove relationships to deleted models
- Clean up Inertia shared data (HandleInertiaRequests) if it references deleted features
- Clean up FortifyServiceProvider login pipeline (remove device detection, login notification calls)
- Remove unused imports throughout

---

## Phase 2: Wallet System

### `wallets` Table

```
id              bigint PK auto-increment
uuid            uuid unique
tenant_id       FK -> tenants(id), indexed
user_id         FK -> users(id)
balance         decimal(14,2) default 0
currency        varchar(3) -- e.g. NAD
status          enum('active','frozen','closed') default 'active'
total_deposited decimal(14,2) default 0
total_withdrawn decimal(14,2) default 0
total_won       decimal(14,2) default 0
total_lost      decimal(14,2) default 0
created_at      timestamp
updated_at      timestamp
```

**Constraints:**
- Composite unique: `(tenant_id, user_id)` — one wallet per user per tenant
- Uses `BelongsToTenant` trait
- `balance` must be >= 0 (enforced in service, not DB constraint)

### `wallet_transactions` Table

```
id              bigint PK auto-increment
uuid            uuid unique
wallet_id       FK -> wallets(id), indexed
game_session_id FK -> game_sessions(id), nullable, indexed
type            enum('deposit','withdrawal','bet','win','adjustment')
amount          decimal(14,2)
balance_before  decimal(14,2)
balance_after   decimal(14,2)
reference       varchar(255) nullable
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

    // Relationships:
    // - user() -> BelongsTo(User)
    // - transactions() -> HasMany(WalletTransaction)
    // - gameSessions() -> MorphMany(GameSession, 'source')

    // Methods:
    // - isActive(): bool
    // - hasSufficientBalance(amount): bool
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

    // Relationships:
    // - wallet() -> BelongsTo(Wallet)
    // - gameSession() -> BelongsTo(GameSession)
    // - performedBy() -> BelongsTo(User)

    // Methods:
    // - isCredit(): bool -- deposit, win, positive adjustment
    // - isDebit(): bool -- withdrawal, bet, negative adjustment
}
```

### WalletService

All operations use DB transactions with `SELECT FOR UPDATE` row locking on the wallet row to prevent race conditions.

```php
class WalletService
{
    // Create wallet for user (called during registration or first game session)
    createWallet(User $user, string $currency = 'NAD'): Wallet

    // Staff loads credits onto user's wallet
    deposit(Wallet $wallet, float $amount, ?User $performedBy, ?string $reference): WalletTransaction

    // Staff cashes out from user's wallet
    withdraw(Wallet $wallet, float $amount, ?User $performedBy, ?string $reference): WalletTransaction

    // Game bet — called by GameSessionService
    debit(Wallet $wallet, float $amount, GameSession $session, ?string $reference): WalletTransaction

    // Game win — called by GameSessionService
    credit(Wallet $wallet, float $amount, GameSession $session, ?string $reference): WalletTransaction

    // Get current balance
    getBalance(Wallet $wallet): float
```

**Validation rules:**
- `deposit`: amount > 0, wallet must be active
- `withdraw`: amount > 0, wallet must be active, sufficient balance
- `debit`: amount > 0, wallet must be active, sufficient balance
- `credit`: amount > 0, wallet must be active

### User Model Changes

Add to User model:
```php
// Relationship
wallet(): HasOne(Wallet)

// Helper
getOrCreateWallet(string $currency = 'NAD'): Wallet
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
session_token   varchar(255) unique, indexed
tenant_id       FK -> tenants(id), indexed
game_id         FK -> games(id), indexed
source_type     varchar(255) -- 'wallet' or 'voucher_code' (polymorphic)
source_id       bigint -- wallet.id or voucher_code.id (polymorphic)
terminal_id     FK -> venue_terminals(id), nullable
ip_address      varchar(45) nullable
balance_start   decimal(14,2)
balance_end     decimal(14,2) nullable
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

### GameSession Model

```php
class GameSession extends Model
{
    use HasFactory;

    // Route key: uuid
    // Auto-generate UUID and session_token on creation

    // Relationships:
    // - source() -> MorphTo (Wallet or VoucherCode)
    // - game() -> BelongsTo(Game)
    // - terminal() -> BelongsTo(VenueTerminal)
    // - walletTransactions() -> HasMany(WalletTransaction)
    // - voucherTransactions() -> HasMany(VoucherTransaction)

    // Methods:
    // - isActive(): bool
    // - end(reason, balanceEnd): void
    // - getNetResultAttribute(): float (balance_end - balance_start)
    // - getDurationMinutesAttribute(): float
}
```

### GameSessionService

```php
class GameSessionService
{
    // Start session for an online user (via OAuth token)
    startWalletSession(User $user, Game $game, ?string $ipAddress): GameSession

    // Start session for a voucher code (via terminal)
    startVoucherSession(VoucherCode $code, Game $game, VenueTerminal $terminal, ?string $ipAddress): GameSession

    // End session
    endSession(string $sessionToken, string $reason): GameSession

    // Debit (bet) — delegates to WalletService or VoucherCodeService
    debit(string $sessionToken, float $amount, ?string $reference): array

    // Credit (win) — delegates to WalletService or VoucherCodeService
    credit(string $sessionToken, float $amount, ?string $reference): array

    // Get balance — delegates based on source_type
    getBalance(string $sessionToken): array

    // Get session info
    getSessionInfo(string $sessionToken): GameSession

    // Get recent transactions for session
    getTransactions(string $sessionToken, int $limit = 20): Collection
```

### AuthenticateGameSession Middleware

Replaces `AuthenticateVoucherSession`. Validates the bearer token as a game session token:

1. Extract bearer token from Authorization header
2. Look up `game_sessions` by `session_token`
3. Verify session is active (not ended, not timed out)
4. Verify source (wallet/voucher) is still active
5. If terminal session, verify terminal is still active
6. Bind session to request (`$request->gameSession`)

### Unified Game API Endpoints

All routes prefixed with `/api/v1/game`, named `api.game.*`.

**Session management** (authenticated by OAuth token or terminal key):
```
POST   /api/v1/game/session/start    -- Start a game session
```

Request body for online user:
```json
{
    "game_id": "game-uuid"
}
```
(User identified from OAuth bearer token)

Request body for voucher code (terminal):
```json
{
    "game_id": "game-uuid",
    "code": "ABC123",
    "pin": "1234"
}
```
(Terminal identified from X-Terminal-Key header)

Response:
```json
{
    "session_token": "gs_abc123...",
    "balance": 150.00,
    "currency": "NAD",
    "game": { "uuid": "...", "name": "..." }
}
```

**Gameplay endpoints** (authenticated by game session token):
```
POST   /api/v1/game/session/end      -- End session
GET    /api/v1/game/session/info      -- Session details
GET    /api/v1/game/balance           -- Current balance
POST   /api/v1/game/debit             -- Place bet
POST   /api/v1/game/credit            -- Pay winnings
GET    /api/v1/game/transactions      -- Recent transactions
```

Debit request:
```json
{
    "amount": 10.00,
    "reference": "round-12345"
}
```

Debit response:
```json
{
    "success": true,
    "balance": 140.00,
    "transaction_id": "txn-uuid"
}
```

Credit request/response follows same pattern.

### Endpoints to Remove

All old terminal player and voucher session endpoints:
- `POST /venue/auth/code` — replaced by `POST /api/v1/game/session/start`
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

### New Controllers

- `app/Http/Controllers/Api/GameSessionController.php` — handles all `/api/v1/game/*` endpoints
- `app/Http/Middleware/AuthenticateGameSession.php` — new middleware

---

## Phase 4: Revenue Calculation

### CalculateRevenueCommand

Artisan command: `php artisan revenue:calculate {--period=daily}`

Scheduled to run daily at midnight (or tenant's timezone midnight).

**Logic:**
1. For each active tenant:
2. For each game with activity in the period:
3. Query `game_sessions` for the tenant + game in the period
4. For wallet-backed sessions: sum bets and wins from `wallet_transactions` where type = 'bet' or 'win'
5. For voucher-backed sessions: sum bets and wins from `voucher_transactions` where type = 'loss' or 'win'
6. Calculate:
   - `total_bets` = sum of all bet/loss amounts
   - `total_wins` = sum of all win amounts
   - `gross_gaming_revenue` = total_bets - total_wins
   - `revenue_share_pct` = tenant's `revenue_share_pct` (default 70)
   - `tenant_share` = GGR * (revenue_share_pct / 100)
   - `chinga_share` = GGR - tenant_share
7. Upsert into `tenant_revenue_records` (unique on tenant_id + game_id + period_type + period_start)

**No table changes needed** — `tenant_revenue_records` already has all required columns.

### Schedule Registration

In `routes/console.php` or a service provider:
```php
Schedule::command('revenue:calculate --period=daily')->dailyAt('02:00');
```

Run at 02:00 to ensure all timezone-based daily activity has settled.

---

## Migration Strategy

Since no games are connected yet and the system is not in production:

1. **Create a single migration** that:
   - Drops tables: `kyc_documents`, `responsible_gambling_settings`, `self_exclusions`, `phone_verifications`, `login_notifications`, `form_configurations`, `saved_filters`, `voucher_sessions`
   - Removes columns from `users`: `sms_mfa_phone`, `sms_mfa_enabled`, `preferred_mfa_method`, `phone`, `phone_verified_at` (if not needed for basic profile)
   - Creates `wallets` table
   - Creates `wallet_transactions` table
   - Creates `game_sessions` table
   - Updates `voucher_transactions` to reference `game_sessions` instead of `voucher_sessions`

2. **Keep phone column on users** if you want it as a contact field — just remove the verification system.

---

## Out of Scope

- Payment gateway integration (future — staff loads credits for now)
- Real-time revenue calculation (scheduled jobs only)
- KYC (removed entirely)
- Responsible gambling (removed entirely)
- SMS MFA / phone verification (removed entirely)
- Game-side implementation (games are separate projects)
- Mobile app (future)
