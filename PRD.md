# Product Requirements Document (PRD)
# Chinga Games SSO — Centralized Authentication & Identity Platform

**Version:** 2.0
**Last Updated:** 2026-03-01
**Status:** In Development

---

## 1. Executive Summary

Chinga Games SSO is a centralized authentication, identity management, and player operations platform for a multi-game online betting and gambling ecosystem operating primarily in Namibia and Southern Africa. The system serves as the single source of truth for player identity across all connected gaming applications.

The platform supports **two distinct user models** that reflect the operational reality of the market:

| Dimension | Online Users | Venue Users (Bar/Pub) |
|---|---|---|
| **Registration** | Full signup (email, password, phone, DOB) | None — anonymous via voucher code |
| **Authentication** | OAuth2/OIDC SSO + MFA | Voucher code entered on terminal |
| **Identity** | Persistent account with KYC | Anonymous, code = identity |
| **Credits** | Wallet (via connected games) | Code balance (load/cashout via staff) |
| **KYC** | Required for higher limits (Levels 0-3) | Not required |
| **Scope** | All connected online games | Single venue only |
| **Responsible Gambling** | Full controls (limits, self-exclusion) | Venue-level limits |

### System Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                        CHINGA GAMES SSO                             │
│                                                                     │
│  ┌──────────────┐  ┌──────────────┐  ┌───────────────────────────┐ │
│  │  OAuth2/OIDC │  │  User Portal │  │  Admin Back-Office        │ │
│  │  Server      │  │  (React SPA) │  │  (React + PrimeReact)     │ │
│  │  (Passport)  │  │  (Inertia)   │  │  (Inertia)               │ │
│  └──────┬───────┘  └──────┬───────┘  └──────────┬────────────────┘ │
│         │                 │                      │                  │
│  ┌──────┴─────────────────┴──────────────────────┴───────────────┐ │
│  │                    Laravel 12 Backend                          │ │
│  │  Auth · KYC · Responsible Gambling · Venue Ops · Audit        │ │
│  └──────┬────────────────────────────────────────┬───────────────┘ │
│         │                                        │                  │
│  ┌──────┴───────┐                      ┌─────────┴──────────────┐  │
│  │  Venue Staff │                      │  Terminal API          │  │
│  │  API         │                      │  (Game Machines)       │  │
│  │  (Sanctum)   │                      │  (API Key Auth)        │  │
│  └──────────────┘                      └────────────────────────┘  │
└─────────────────────────────────────────────────────────────────────┘
         │                    │                       │
    ┌────┴────┐         ┌────┴────┐            ┌─────┴──────┐
    │ Game 1  │         │ Game 2  │            │ Game N     │
    │ (OAuth) │         │ (OAuth) │            │ (Terminal) │
    └─────────┘         └─────────┘            └────────────┘
```

### Technology Stack

| Layer | Technology |
|---|---|
| **Backend** | Laravel 12, PHP 8.2+ |
| **OAuth2/OIDC** | Laravel Passport v13 (RS256 JWT) |
| **Auth Features** | Laravel Fortify v1.30 (login, registration, 2FA, email verification) |
| **Frontend Framework** | React 19 + Inertia.js v2 |
| **User-Facing UI** | shadcn/ui (Radix primitives) + Tailwind CSS 4 |
| **Admin UI** | PrimeReact v10 (DataTable, Dialog, etc.) + Tailwind CSS 4 |
| **Database** | SQLite (dev) / MySQL 8 (production) |
| **Session/Cache** | Database driver (configurable to Redis) |
| **Build** | Vite 7 |
| **Currency** | NAD (Namibian Dollar) — default |

---

## 2. Goals & Objectives

### Primary Goals

1. **Single Sign-On** — One registration grants access to all connected gaming applications via OAuth2/OIDC
2. **Regulatory Compliance** — Centralized KYC, age verification, and responsible gambling controls meeting Namibian gaming regulations
3. **Dual-Model Support** — Serve both online players (registered accounts) and venue players (anonymous voucher codes) through a unified platform
4. **Security** — Enterprise-grade authentication with MFA, brute-force protection, session management, and comprehensive audit logging
5. **Scalability** — Add new games/venues without authentication rework

### Success Metrics

| Metric | Target |
|---|---|
| Auth API response time (p95) | < 200ms |
| Token validation time | < 50ms |
| Platform uptime | 99.9% |
| Concurrent authenticated sessions | 10,000+ |
| Security breaches via auth | Zero |
| Single registration → all games | 100% |

---

## 3. User Types & Roles

### 3.1 Online Users (Players)

Registered players who access games via web/mobile through OAuth2 SSO.

**Capabilities:**
- Self-registration with email, password, phone, date of birth
- Login via email or username + password
- Multi-factor authentication (TOTP app + SMS OTP)
- KYC document submission for higher limits
- Responsible gambling self-service (limits, self-exclusion)
- Session management (view/revoke active sessions)
- OAuth2 client management (view connected apps)
- Account settings and profile management

### 3.2 Venue Users (Anonymous Players)

Patrons at physical venues (bars, pubs, casinos) who play via voucher codes. No registration required.

**Capabilities:**
- Receive voucher code from venue staff
- Enter code on game terminal to play
- Optional PIN protection for code
- Balance persists across sessions until cashout or expiry

### 3.3 Venue Staff

Employees at physical venues who manage voucher codes and player credits.

**Roles:**

| Role | Permissions |
|---|---|
| **Owner** | Full venue control, manage staff, all code operations, view reports |
| **Manager** | Create codes, manage credits, view reports, manage staff |
| **Staff** | Create codes, load credits |
| **Cashier** | Cash out codes, view balances |

**Authentication:** Username + password (Sanctum tokens) or 4-digit PIN for quick POS access.

### 3.4 Administrators

Platform operators who manage users, venues, KYC, and system configuration.

**Roles:**

| Role | Access |
|---|---|
| **Admin** | User management, KYC review, venue management, reports, audit logs |
| **Super Admin** | All admin capabilities + system configuration (placeholder for future expansion) |

---

## 4. Functional Requirements

### FR-1: Online User Authentication

#### FR-1.1: Registration
- Email and password with strong password enforcement
- Username (optional, unique)
- Phone number (optional, with OTP verification)
- Date of birth (mandatory, 18+ age gate enforced at registration)
- Country selection (Namibia default, supports broader African and Western countries)
- Terms of service acceptance

#### FR-1.2: Login
- Email OR username + password authentication
- "Remember me" persistent sessions
- Account lockout after **5 failed attempts** → **30-minute lockout**
- Failed login tracking with IP and timestamp
- New device/location detection with security alerts on dashboard
- Self-excluded users receive a friendly block message
- Suspended users receive a contact-support message

#### FR-1.3: Multi-Factor Authentication (MFA)

Two MFA methods supported, user selects preferred method:

| Method | Details |
|---|---|
| **TOTP** | Google Authenticator / Authy compatible. QR code setup with recovery codes. |
| **SMS OTP** | 6-digit code sent to verified phone. 10-minute expiry. Max 5 verification attempts. |

- Users set a `preferred_mfa_method` (totp or sms)
- Recovery codes for TOTP (one-time use, regeneratable)
- MFA can be enabled/disabled from settings
- Confirmation password required to toggle MFA

#### FR-1.4: Password Management
- Secure password reset via email link
- Password change requires current password
- Throttled: max 6 password changes per minute

#### FR-1.5: Phone Verification
- OTP-based phone verification for registration, login, profile updates, and MFA
- 6-digit codes, hashed in database
- 10-minute expiry window
- 5 maximum verification attempts per code
- **SMS delivery**: Currently placeholder (logs in dev) — production integration with Twilio/Vonage TBD

#### FR-1.6: Email Verification
- Standard Laravel email verification flow
- Verification required for full account access (enforced via `verified` middleware)

---

### FR-2: OAuth2 / OpenID Connect Server

#### FR-2.1: Supported Grant Types

| Grant Type | Use Case |
|---|---|
| **Authorization Code** | Web applications (server-rendered games) |
| **Authorization Code + PKCE** | SPAs and mobile apps |
| **Password Grant** | First-party apps (deprecated, available) |
| **Client Credentials** | Server-to-server communication |
| **Refresh Token** | Token renewal |
| **Device Code** | TV/console devices |

#### FR-2.2: Token Configuration

| Token Type | Lifetime |
|---|---|
| Access Token | 1 hour |
| Refresh Token | 30 days |
| Authorization Code | Standard (short-lived) |

#### FR-2.3: OAuth Scopes

| Scope | Data Exposed |
|---|---|
| `openid` | Subject identifier (sub) |
| `profile` | Name, username, date of birth |
| `email` | Email address, verification status |
| `phone` | Phone number, verification status |
| `wallet` | Wallet balance (read-only) |
| `wallet:write` | Wallet transactions |
| `kyc` | KYC level, verification status, account limits |
| `gaming:history` | Gaming session history |
| `admin` | Administrative access |

**Default scopes:** `openid`, `profile`, `email`

#### FR-2.4: OIDC Discovery
- `GET /.well-known/openid-configuration` — Full discovery document
- `GET /.well-known/jwks.json` — JSON Web Key Set (RS256)
- `GET /api/v1/oauth/userinfo` — UserInfo endpoint with scope-filtered claims

#### FR-2.5: Client Management
- Admin creates OAuth clients (game applications)
- Client ID + Secret generation
- Redirect URI configuration
- Scope restriction per client
- Secret regeneration capability
- Users can view their own connected applications

---

### FR-3: KYC (Know Your Customer)

#### FR-3.1: Verification Levels

| Level | Requirements | Limits |
|---|---|---|
| **Level 0** — Unverified | Email only | Cannot play |
| **Level 1** — Basic | Email + Phone + DOB verified | NAD 1,000/day |
| **Level 2** — Enhanced | Level 1 + Identity document approved | NAD 10,000/day |
| **Level 3** — Full | Level 2 + Proof of address + Source of funds | Unlimited |

#### FR-3.2: Supported Document Types

| Document | KYC Level Contribution |
|---|---|
| Passport | Level 2 |
| National ID | Level 2 |
| Driver's License | Level 2 |
| Proof of Address | Level 3 |
| Selfie | Level 2 (identity confirmation) |
| Source of Funds | Level 3 |

#### FR-3.3: Document Upload
- File types: JPEG, PNG, WebP, PDF
- Maximum file size: 10MB
- Storage: Private disk with 5-minute signed URLs for secure access
- Status tracking: Pending → Approved / Rejected
- Rejection includes admin-provided reason
- KYC level automatically upgrades upon document approval
- Users can delete pending (unreviewed) documents

#### FR-3.4: Admin KYC Review
- Queue of pending documents
- Inline approve/reject actions
- Rejection reason capture
- Manual KYC level override capability
- Dashboard stats: pending, approved, rejected counts

---

### FR-4: Responsible Gambling

#### FR-4.1: Deposit Limits

| Limit Type | Behavior |
|---|---|
| Daily deposit limit | Per-day cap |
| Weekly deposit limit | Per-week cap |
| Monthly deposit limit | Per-month cap |

**Rules:**
- Limit **decreases** take effect **immediately**
- Limit **increases** have a mandatory **24-hour cooling-off period**
- Pending limit increases shown to user with countdown
- Users can cancel pending increases

#### FR-4.2: Session Controls

| Control | Options |
|---|---|
| Session time limit | 30min, 1h, 2h, 4h, 8h |
| Reality check interval | 15min, 30min, 1h, 2h |
| Session loss limit | Configurable amount |
| Wager limit | Configurable amount |
| Login time restrictions | Configurable allowed hours |

#### FR-4.3: Self-Exclusion

| Type | Duration | Reversibility |
|---|---|---|
| **Temporary** | 24 hours, 7 days, 30 days, 90 days, 6 months, 1 year | Auto-expires; 24h cooling-off before reactivation |
| **Permanent** | Indefinite | Cannot be reversed by user |

**Effects:**
- User status immediately set to `self_excluded`
- Blocked from all connected games
- Login shows friendly exclusion notice
- Admin can see exclusion history
- Revocation tracking with timestamps

---

### FR-5: Venue & Voucher Code System

#### FR-5.1: Venue Management

**Venue Properties:**
- Name, slug, business name, license number
- Full address (line 1, line 2, city, region, postal code, country)
- Contact info (phone, email)
- Timezone (default: `Africa/Windhoek`)
- Currency (default: `NAD`)
- Status: Active / Suspended
- Configurable settings (JSON): code format, limits, operating hours

**Admin Actions:** Create, view, edit, suspend, activate, soft-delete venues

#### FR-5.2: Venue Staff

- Staff belong to a single venue
- Authenticated via username + password (Sanctum) or 4-digit PIN
- Roles: Owner, Manager, Staff, Cashier
- Status: Active, Suspended, Terminated
- Soft-deletable

#### FR-5.3: Venue Terminals

Game machines/kiosks/tablets at venues.

| Property | Details |
|---|---|
| **Types** | Kiosk, Tablet, Terminal, POS |
| **Authentication** | API key in `X-Terminal-Key` header (SHA256 hashed in DB) |
| **Status** | Active, Inactive, Maintenance |
| **Heartbeat** | Periodic check-in with timestamp tracking |

API key is shown **once** at terminal creation, then stored hashed.

#### FR-5.4: Voucher Code System

**Code Format:** `PREFIX-XXXX-XXXX-XXXX` (configurable prefix, alphanumeric segments)

**Code Lifecycle:**
```
CREATED → ACTIVE → IN_USE → CASHED_OUT
              ↓                    ↓
          EXPIRED            (reactivatable)
              ↓
         DEACTIVATED
```

| State | Description |
|---|---|
| `created` | Generated, no credits loaded |
| `active` | Credits loaded, ready for use |
| `in_use` | Currently in an active game session |
| `cashed_out` | Balance paid out by staff |
| `expired` | Exceeded validity period |
| `deactivated` | Manually voided by staff/admin |

**Code Features:**
- Optional 4-digit PIN protection
- Venue binding (code only works at assigned venue)
- Configurable expiration
- Extension capability (staff can extend expiry)
- Balance transfer between codes
- Full transaction ledger

#### FR-5.5: Voucher Transactions

| Transaction Type | Description |
|---|---|
| `load` | Staff loads credits onto code |
| `win` | Player wins during gameplay |
| `loss` | Player loses during gameplay |
| `cashout` | Staff cashes out remaining balance |
| `adjustment` | Admin balance correction |
| `transfer_in` | Received from another code |
| `transfer_out` | Sent to another code |

Each transaction records: amount, balance before/after, reference, description, performing staff, terminal, and metadata.

#### FR-5.6: Voucher Sessions

When a code is entered on a terminal:
1. Terminal authenticates via API key
2. Code is validated (exists, active, correct venue, not expired)
3. Session created with unique `session_token`
4. Game uses session token as Bearer auth for balance/debit/credit operations
5. Activity timestamps tracked; session ends on logout, timeout, or cashout

#### FR-5.7: Venue Shifts

Staff shift tracking:
- Opening/closing balance
- Running totals: codes created, loads processed, cashouts processed
- Notes field for shift handoff

---

### FR-6: User Dashboard & Settings

#### FR-6.1: User Dashboard
- Security alerts (new device/location detections)
- Stat cards: Account Status, KYC Level, Security Score %, Active Sessions
- Account verification checklist: email, phone, 2FA, KYC
- KYC documents summary
- Recent sessions table
- Quick action links

#### FR-6.2: Settings Pages

All settings pages are accessible from the user sidebar navigation:

| Page | Sidebar Label | Route | Features |
|---|---|---|---|
| **Profile** | Profile | `/settings/profile` | Edit name, email (with re-verification), account deletion |
| **Password** | Password | `/settings/password` | Change password with current password confirmation |
| **Two-Factor** | Two-Factor Auth | `/settings/two-factor` | TOTP setup (QR code), recovery codes, enable/disable |
| **Sessions** | Sessions | `/settings/sessions` | View all active sessions (device, browser, IP, location), revoke individual or all |
| **Security Log** | Security Log | `/settings/security/log` | Personal security event timeline with severity badges |
| **KYC** | Identity (KYC) | `/settings/kyc` | Level display, progress bar, document upload/delete, limits display |
| **Responsible Gambling** | Responsible Gaming | `/settings/responsible-gambling` | Deposit limits, session controls, self-exclusion management |
| **Appearance** | Appearance | `/settings/appearance` | Light / Dark / System theme toggle |
| **SMS MFA** | *(via Security Log)* | `/settings/security/sms-mfa/*` | SMS OTP setup, enable/disable, switch preferred method |

---

### FR-7: Admin Back-Office

Built with PrimeReact DataTable components featuring:
- Filterable, sortable, paginated data grids
- CSV/XLSX/PDF export capability
- Resizable and reorderable columns
- Two-tier form personalization (system defaults + per-user overrides via Acumatica-style UI)
- Saved filter presets (owned + shared)

#### FR-7.1: Admin Dashboard
- Stat cards: Total Users, Pending KYC, Active Venues, Total Voucher Balance
- Security alerts (> 10 failed logins/day, locked accounts)
- Recent registrations table
- Pending KYC review queue

#### FR-7.2: User Management
- Search/filter by name, email, username
- Filter by status (active, suspended, banned, self_excluded) and KYC level (0-3)
- Paginated list with key details
- Actions: View details, edit, suspend, ban, activate, unlock, reset password

#### FR-7.3: KYC Review
- Pending/Approved/Rejected stats
- Document review queue
- Inline approve/reject with rejection reason dialog
- Manual KYC level assignment

#### FR-7.4: Venue Management
- Venue list with stats (staff, terminals, codes, balance)
- Create venue dialog
- Venue detail page with tabbed sub-resources:
  - **Staff tab:** List, add staff (username, password, display name, role, PIN)
  - **Terminals tab:** List, add terminal (code, name, type), API key shown once on creation
  - **Codes link:** Links to voucher codes filtered by venue
- Suspend/Activate venue

#### FR-7.5: Voucher Code Management
- Cross-venue code search
- Filter by venue, status, code string
- View: code, venue, balance, status, loaded/cashed totals, creation date
- Void individual codes
- Generate codes dialog: venue, count (1-100), initial balance (NAD), optional prefix
- CSV export of generated codes

#### FR-7.6: Reports & Analytics
- **Registrations:** Total count, email verification rate (30-day window)
- **Login Activity:** Total/successful/failed attempts, active sessions in 24h
- **KYC Verification:** Total documents, pending count, completion rate
- **Responsible Gambling:** New exclusions, currently excluded, users with limits
- **Venues:** Venue-level activity metrics

#### FR-7.7: Audit Logs
- System-wide event trail
- Fields: timestamp, user/system, action, description, IP address
- Searchable/filterable by action
- Paginated

---

### FR-8: Form Configuration System (Acumatica-Style)

Two-tier personalization for admin data grids and forms:

| Tier | Description |
|---|---|
| **System Config** | Default fieldset layout, column order, visibility — set by admins |
| **User Config** | Per-user overrides on top of system defaults — saved individually |

**Features:**
- Fieldset definitions with field-level visibility control
- Grid column configuration (order, width, visibility)
- Tab ordering
- Saved filter presets (per-user, shareable)
- Reset user config to system defaults

---

## 5. Security Architecture

### 5.1: Authentication Layers

| Layer | Method | Use Case |
|---|---|---|
| **Web Sessions** | Laravel session + Fortify | User portal, admin portal |
| **OAuth2 Tokens** | Passport (RS256 JWT) | Connected game applications |
| **Sanctum Tokens** | Laravel Sanctum | Venue staff API |
| **API Key** | SHA256 hashed key in header | Game terminals |
| **Session Token** | Per-session bearer token | Active voucher code sessions |

### 5.2: Brute-Force Protection
- Failed login tracking per email + IP
- Account lockout: 5 failures → 30-minute lock
- Rate limiting on sensitive endpoints (password change: 6/min)

### 5.3: Device & Location Security
- New device detection on login
- New location detection on login
- Security alerts surfaced on user dashboard
- Login notifications table for audit

### 5.4: Audit Logging

Comprehensive `security_audit_logs` table tracking:

| Event Category | Examples |
|---|---|
| **Authentication** | login, login_failed, logout, new_device, new_location |
| **MFA** | mfa_enabled, mfa_disabled, mfa_method_changed |
| **Password** | password_changed, password_reset |
| **Account** | account_suspended, account_banned, account_activated, account_unlocked |
| **Self-Exclusion** | self_exclusion_created, self_exclusion_revoked |
| **Admin** | admin_action, kyc_approved, kyc_rejected, kyc_level_set |

Each event includes: user, admin (if applicable), IP address, user agent, severity level (info/warning/critical), and metadata JSON.

### 5.5: Session Management
- Per-user session tracking with device/browser/platform/IP/location
- View all active sessions
- Revoke individual sessions
- "Revoke all other sessions" with password confirmation
- Session expiration tracking

### 5.6: Self-Exclusion Enforcement
- `CheckSelfExclusion` middleware blocks all gameplay
- Applied at route level for relevant endpoints
- JSON 403 for API requests, web redirect for browser requests

### 5.7: KYC Level Enforcement
- `CheckKycLevel` middleware with parameterized level (e.g., `kyc:2`)
- Returns 403 if user's KYC level is insufficient

---

## 6. API Architecture

### 6.1: Route Groups

| Route File | Prefix | Auth | Purpose |
|---|---|---|---|
| `routes/api.php` | `/api` | Passport (`auth:api`) | Public OIDC, Phone OTP, OAuth userinfo, KYC, Responsible Gambling |
| `routes/admin.php` | `/api/v1/admin` | Session + `IsAdmin` | Admin back-office API |
| `routes/venue.php` | `/venue` | Sanctum (`auth:sanctum`) | Venue staff operations |
| `routes/terminal.php` | `/terminal`, `/venue` | API Key + Session Token | Terminal auth, player balance/debit/credit |
| `routes/web.php` | `/` | Session | Inertia pages (user portal, admin portal) |
| `routes/settings.php` | `/settings` | Session | User settings pages |
| `routes/form-config.php` | `/api` | Session | Form personalization API |

### 6.2: Key API Endpoints

#### Public (No Auth)
```
GET  /.well-known/openid-configuration    OIDC discovery
GET  /.well-known/jwks.json               JSON Web Key Set
POST /api/v1/phone/send-otp               Send phone verification OTP
POST /api/v1/phone/verify                 Verify phone OTP
```

#### OAuth-Authenticated (Passport)
```
GET  /api/v1/oauth/userinfo               OIDC UserInfo
GET  /api/v1/user                         Current user profile
POST /api/v1/phone/update                 Update phone number
POST /api/v1/phone/resend                 Resend phone OTP

# OAuth Client Management
GET/POST     /api/v1/oauth/clients        List/Create clients
GET/PUT/DEL  /api/v1/oauth/clients/{id}   Get/Update/Delete client
POST         /api/v1/oauth/clients/{id}/regenerate-secret

# KYC
GET  /api/v1/kyc/status                   Current KYC status
GET  /api/v1/kyc/requirements             Level requirements
GET  /api/v1/kyc/documents                List documents
POST /api/v1/kyc/documents                Upload document
GET  /api/v1/kyc/documents/{uuid}         Get document
DEL  /api/v1/kyc/documents/{uuid}         Delete pending document

# Responsible Gambling
GET  /api/v1/responsible-gambling/         Current settings
GET  /api/v1/responsible-gambling/options  Available options
PUT  /api/v1/responsible-gambling/deposit-limits
PUT  /api/v1/responsible-gambling/session-limits
PUT  /api/v1/responsible-gambling/reality-check
PUT  /api/v1/responsible-gambling/login-restrictions
DEL  /api/v1/responsible-gambling/pending-limits
GET/POST /api/v1/responsible-gambling/self-exclude
GET  /api/v1/responsible-gambling/self-exclude/history
```

#### Admin API
```
GET  /api/v1/admin/dashboard              Dashboard stats
GET  /api/v1/admin/reports/{type}         Reports (registrations, logins, kyc, etc.)
GET  /api/v1/admin/audit-logs             Audit log entries

# Users
GET  /api/v1/admin/users/{uuid}           Get user
PUT  /api/v1/admin/users/{uuid}           Update user
POST /api/v1/admin/users/{uuid}/suspend|ban|activate|unlock|reset-password

# KYC Review
GET  /api/v1/admin/kyc                    List documents
POST /api/v1/admin/kyc/{uuid}/approve     Approve document
POST /api/v1/admin/kyc/{uuid}/reject      Reject document
POST /api/v1/admin/kyc/users/{uuid}/set-level  Override KYC level

# Venues
CRUD /api/v1/admin/venues                 Venue management
POST /api/v1/admin/venues/{uuid}/suspend|activate
GET/POST /api/v1/admin/venues/{uuid}/staff|terminals|codes
POST /api/v1/admin/venues/{uuid}/codes/generate
POST /api/v1/admin/venues/{uuid}/codes/{uuid}/void|add-balance
GET  /api/v1/admin/voucher-codes          Cross-venue code search
```

#### Venue Staff API
```
POST /venue/auth/login                    Staff login (username+password → Sanctum)
POST /venue/auth/pin-login                Staff PIN login
POST /venue/auth/logout                   Staff logout
GET  /venue/profile                       Staff profile
PUT  /venue/profile/password              Change password
POST /venue/profile/pin                   Set/change PIN

# Voucher Code Operations
GET/POST /venue/codes                     List/Create codes
GET  /venue/codes/{code}                  Code details
GET  /venue/codes/{code}/balance          Quick balance check
POST /venue/codes/{code}/load             Load credits
POST /venue/codes/{code}/cashout          Cash out
POST /venue/codes/{code}/deactivate       Deactivate
POST /venue/codes/{code}/transfer         Transfer to another code
POST /venue/codes/{code}/set-pin          Set PIN
POST /venue/codes/{code}/extend           Extend expiration
GET  /venue/codes/{code}/transactions     Transaction history
```

#### Terminal API
```
POST /terminal/auth                       Terminal authenticates (API key)
POST /terminal/heartbeat                  Terminal heartbeat

# Player Session (via voucher code)
POST /venue/auth/code                     Authenticate code → session_token
POST /venue/auth/code/verify-pin          Verify code PIN
POST /venue/auth/code/logout              End session
GET  /venue/auth/code/session             Get session info

# Game Operations (session token auth)
GET  /venue/player/balance                Get player balance
GET  /venue/player/can-play               Check play eligibility
POST /venue/player/debit                  Deduct credits (bet)
POST /venue/player/credit                 Add credits (win)
POST /venue/player/transaction            Generic transaction
GET  /venue/player/transactions           Transaction history
```

---

## 7. Database Schema

### Core Tables

| Table | Purpose |
|---|---|
| `users` | Player accounts (UUID, username, email, phone, DOB, KYC level, status, MFA fields, admin flags, lockout tracking, soft deletes) |
| `password_reset_tokens` | Password reset tokens |
| `sessions` | Laravel sessions (database driver) |

### Authentication & Security

| Table | Purpose |
|---|---|
| `phone_verifications` | OTP codes (hashed) with purpose, expiry, attempt tracking |
| `login_attempts` | Failed/successful login log (email + IP) |
| `login_notifications` | Device fingerprint + location tracking for new device alerts |
| `security_audit_logs` | Comprehensive event log with severity levels |
| `user_sessions` | Session tracking (device, browser, platform, IP, location, expiry) |

### OAuth2

| Table | Purpose |
|---|---|
| `oauth_auth_codes` | Authorization codes |
| `oauth_access_tokens` | Access tokens |
| `oauth_refresh_tokens` | Refresh tokens |
| `oauth_clients` | Registered OAuth clients |
| `oauth_device_codes` | Device authorization codes |

### KYC & Compliance

| Table | Purpose |
|---|---|
| `kyc_documents` | Document uploads with type, status, reviewer, rejection reason |
| `responsible_gambling_settings` | Per-user limits (deposit, session, reality check, wager, login time) with pending increase tracking |
| `self_exclusions` | Temporary/permanent exclusions with revocation tracking |

### Venue Operations

| Table | Purpose |
|---|---|
| `venues` | Gaming venues with address, contact, timezone, currency, settings JSON, soft deletes |
| `venue_staff` | Staff per venue with role, PIN, status, soft deletes |
| `venue_terminals` | Kiosks/terminals with hashed API keys and heartbeat tracking |
| `venue_shifts` | Staff shift tracking (balances, totals) |
| `voucher_codes` | Credit wallets (code, balance, status lifecycle, PIN, expiry, running totals) |
| `voucher_sessions` | Active game sessions linking code to terminal |
| `voucher_transactions` | Full financial ledger (all money movements) |

### Configuration

| Table | Purpose |
|---|---|
| `form_configurations` | System and per-user form layout overrides (Acumatica-style) |
| `saved_filters` | User-saved data grid filter presets |

---

## 8. Non-Functional Requirements

### NFR-1: Security
- Passwords hashed with bcrypt
- All communication over HTTPS/TLS
- CSRF protection on all web forms
- Rate limiting on sensitive endpoints
- SQL injection prevention (Eloquent parameterized queries)
- XSS prevention (React escaping + Laravel output encoding)
- Secure session management (database-backed)
- RS256 JWT tokens (asymmetric keys)
- API keys stored as SHA256 hashes
- OTPs stored as hashes with attempt limits

### NFR-2: Performance
- Auth API < 200ms (p95)
- Token validation < 50ms
- 10,000+ concurrent sessions
- Proper database indexing (UUIDs, foreign keys, unique constraints)
- Configurable Redis caching layer

### NFR-3: Availability
- 99.9% uptime target
- Health check endpoints
- Database replication support (MySQL)
- Queue-based processing for async tasks

### NFR-4: Scalability
- Horizontal scaling via stateless API (JWT tokens)
- Database connection pooling
- Queue workers for background jobs (database/Redis driver)
- Separate route groups for independent scaling

### NFR-5: Compliance
- Audit trail retention (gambling regulation compliance)
- KYC document retention with secure storage
- Self-exclusion enforcement across all connected games
- Age verification at registration
- Responsible gambling controls per regulatory requirements

---

## 9. Integration Points

### 9.1: Connected Games (OAuth2)

Games integrate via standard OAuth2/OIDC:
1. Register as OAuth client in admin
2. Implement Authorization Code + PKCE flow
3. Exchange tokens for user identity
4. Use scopes to access KYC level, wallet, gaming history

Full integration guide: `SSO_INTEGRATION_GUIDE.md`

### 9.2: Venue Game Terminals

Terminals integrate via API key authentication:
1. Register terminal in admin (receive API key once)
2. Terminal authenticates on startup via `X-Terminal-Key` header
3. Player enters voucher code on terminal
4. Terminal receives session token for balance/debit/credit operations

### 9.3: External Services (Pending Integration)

| Service | Status | Purpose |
|---|---|---|
| **SMS Provider** (Twilio/Vonage) | **Not integrated** — dev logs OTP | Phone verification, SMS MFA |
| **AWS S3** | Configured but unused | KYC document storage (production) |
| **Redis** | Configured, not default | Production cache/session/queue driver |
| **Email Provider** | Laravel default (Mailgun/SES TBD) | Email verification, password resets, notifications |
| **Third-party KYC** (Jumio/Onfido) | **Not integrated** | Automated document verification (Phase 2) |
| **Payment Gateway** | **Not in scope** | Wallet/deposit handled by connected games |

---

## 10. Frontend Architecture

### 10.1: User-Facing Pages (shadcn/ui + Tailwind)

**User Sidebar Navigation** (9 items, in order):

| Sidebar Label | Route | Page |
|---|---|---|
| Dashboard | `/dashboard` | User dashboard (stats, alerts, verification checklist) |
| Profile | `/settings/profile` | Profile editing |
| Password | `/settings/password` | Password change |
| Two-Factor Auth | `/settings/two-factor` | TOTP MFA setup |
| Sessions | `/settings/sessions` | Active sessions management |
| Security Log | `/settings/security/log` | Personal security event log |
| Identity (KYC) | `/settings/kyc` | KYC verification & document upload |
| Responsible Gaming | `/settings/responsible-gambling` | Gambling controls & self-exclusion |
| Appearance | `/settings/appearance` | Theme selection |

**Additional pages** (not in sidebar, accessed via sub-navigation):

| Route | Page |
|---|---|
| `/` | Welcome / Landing |
| `/settings/security/sms-mfa/*` | SMS MFA setup (accessed from security settings) |

**Sidebar footer:** Admin users see an "Admin Panel" link to `/admin`.

### 10.2: Auth Pages

| Route | Page |
|---|---|
| `/login` | Email/username + password |
| `/register` | Full registration form |
| `/forgot-password` | Password reset request |
| `/reset-password` | Password reset with token |
| `/verify-email` | Email verification |
| `/confirm-password` | Password confirmation gate |
| `/two-factor-challenge` | TOTP or SMS OTP entry |

### 10.3: Admin Pages (PrimeReact + Tailwind)

**Admin Sidebar Navigation** (7 items, in order):

| Sidebar Label | Route | Page |
|---|---|---|
| Dashboard | `/admin` | Admin dashboard |
| Users | `/admin/users` | User management |
| KYC Review | `/admin/kyc` | KYC document review |
| Venues | `/admin/venues` | Venue list |
| Voucher Codes | `/admin/voucher-codes` | Cross-venue voucher code management |
| Reports | `/admin/reports` | Reports & analytics |
| Audit Logs | `/admin/audit-logs` | System audit log |

**Additional pages** (not in sidebar, accessed via navigation within pages):

| Route | Page |
|---|---|
| `/admin/venues/{uuid}` | Venue detail (staff, terminals, codes) — accessed from venue list |

**Sidebar footer:** "Back to App" link to `/dashboard`.

---

## 11. Implementation Status

### Completed

- [x] Core user authentication (registration, login, logout)
- [x] Email verification
- [x] Password reset
- [x] TOTP MFA with recovery codes
- [x] SMS MFA (OTP generation/verification, delivery placeholder)
- [x] Account lockout (5 failures → 30-min lock)
- [x] OAuth2/OIDC server (Passport, all grant types)
- [x] OIDC discovery + JWKS endpoints
- [x] Custom OAuth scopes with claim mapping
- [x] OAuth client management API
- [x] KYC document upload, storage, and review workflow
- [x] KYC level auto-upgrade on approval
- [x] Responsible gambling: deposit limits with cooling-off
- [x] Responsible gambling: session limits, reality check
- [x] Self-exclusion (temporary + permanent)
- [x] Venue CRUD (create, read, update, suspend, activate)
- [x] Venue staff management (CRUD, roles, PIN login)
- [x] Venue terminal management (registration, API key auth, heartbeat)
- [x] Voucher code system (generation, lifecycle, PIN, balance operations)
- [x] Voucher sessions (code auth → session token → gameplay)
- [x] Voucher transactions (full ledger)
- [x] Code transfer between codes
- [x] User dashboard with security alerts
- [x] User settings pages (profile, password, sessions, KYC, responsible gambling, 2FA, SMS MFA, security log)
- [x] Admin dashboard with stats
- [x] Admin user management
- [x] Admin KYC review
- [x] Admin venue management with detail pages
- [x] Admin voucher code management with generation + CSV export
- [x] Admin reports (registrations, logins, KYC, responsible gambling, venues)
- [x] Admin audit log viewer
- [x] Form configuration system (Acumatica-style two-tier personalization)
- [x] Saved filter presets
- [x] New device/location detection
- [x] Security audit logging
- [x] Session management (view, revoke)
- [x] Dark/light/system theme support

### Pending / Not Yet Implemented

- [ ] SMS delivery integration (Twilio/Vonage) — currently logs OTP in dev
- [ ] Production email provider configuration
- [ ] AWS S3 for KYC document storage (production)
- [ ] Redis as cache/session/queue driver (production)
- [ ] Third-party KYC provider integration (Jumio/Onfido)
- [ ] Social authentication (Google, Facebook, Apple)
- [ ] Wallet integration API (handled by connected games)
- [ ] Code-to-account upgrade flow
- [ ] Client SDKs (PHP, JavaScript, Flutter)
- [ ] Password history (prevent reuse)
- [ ] Breach detection (HaveIBeenPwned)
- [ ] Staff shift management endpoints
- [ ] Venue staff reports endpoints
- [ ] Super admin specific functionality
- [ ] Load testing
- [ ] Security penetration testing
- [ ] Comprehensive test suite

---

## 12. Glossary

| Term | Definition |
|---|---|
| **SSO** | Single Sign-On — one login grants access to all connected applications |
| **OAuth2** | Authorization framework enabling third-party access to user resources |
| **OIDC** | OpenID Connect — identity layer on top of OAuth2 |
| **PKCE** | Proof Key for Code Exchange — OAuth2 extension for public clients |
| **TOTP** | Time-based One-Time Password (Google Authenticator compatible) |
| **KYC** | Know Your Customer — identity verification process |
| **NAD** | Namibian Dollar — default platform currency |
| **MFA** | Multi-Factor Authentication |
| **Voucher Code** | Anonymous credit-based identity for venue players |
| **Terminal** | Game machine/kiosk at a physical venue |
| **Venue** | Physical location (bar, pub, casino) with gaming terminals |
| **Sanctum** | Laravel's SPA/API token authentication package |
| **Passport** | Laravel's OAuth2 server implementation |
| **Fortify** | Laravel's authentication feature scaffolding |
| **PrimeReact** | React UI component library used for admin data grids |
| **Inertia.js** | Server-side routing with client-side rendering bridge |
