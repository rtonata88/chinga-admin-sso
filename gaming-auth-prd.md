# Product Requirements Document (PRD)
# Centralized Authentication System for Online Gaming Platform

---

## Executive Summary

This system provides **unified authentication** for a multi-game online betting/gambling platform with **two distinct user models**:

| Feature | Online Users | Venue Users (Bar/Pub) |
|---------|--------------|----------------------|
| **Registration** | Full signup (email, password) | None required |
| **Authentication** | OAuth2 SSO + MFA | 6-9 digit voucher code |
| **Identity** | Persistent account | Anonymous code |
| **Credits** | Wallet (deposit/withdraw) | Code balance (load/cashout) |
| **KYC** | Required for higher limits | Not required |
| **Scope** | All online games | Single venue |

### Key Flows

```
ONLINE USER FLOW:
┌──────────┐    OAuth2    ┌────────────┐    Token    ┌──────────┐
│  User    │ ──────────→  │ Auth Server │ ─────────→ │  Game 1  │
│          │ ←────────── │            │            │  Game 2  │
└──────────┘   SSO Login  └────────────┘            │  Game N  │
                                                    └──────────┘

VENUE USER FLOW:
┌──────────┐   Creates   ┌────────────┐   Enters    ┌──────────┐
│  Staff   │ ─────────→  │   Code     │ ─────────→  │  Game    │
│          │   Code      │  ABC123    │   Code      │ Terminal │
└──────────┘             └────────────┘             └──────────┘
     │                         ↑                         │
     │    Load Credits         │     Debit/Credit        │
     └─────────────────────────┴─────────────────────────┘
```

### Technology Stack
- **Backend:** Laravel 11 + Laravel Passport (OAuth2)
- **Database:** MySQL 8 + Redis
- **Currency:** NAD (Namibian Dollar) default
- **Timeline:** 16 weeks

---

## Project Overview

### Project Name
**GameAuth Central** - Unified Authentication System for Online Betting/Gambling Games

### Project Summary
Build a centralized authentication and identity management system that provides Single Sign-On (SSO) capabilities across multiple online betting and gambling game applications. Users should be able to register once and access all gaming platforms with the same credentials.

### Technical Stack
- **Backend Framework:** Laravel 11.x
- **Authentication Protocol:** OAuth2 / OpenID Connect (via Laravel Passport)
- **Database:** MySQL 8.x
- **Cache/Session:** Redis
- **API Format:** RESTful JSON API
- **Frontend (Admin):** Laravel Blade with Tailwind CSS (or Livewire)
- **Client SDKs:** PHP (Laravel), JavaScript, Flutter/Dart

---

## Goals and Objectives

### Primary Goals
1. Provide a single authentication point for all gaming applications
2. Enable seamless user experience across multiple game platforms
3. Centralize user management, KYC, and responsible gambling controls
4. Ensure security compliance for financial/gambling platforms
5. Support scalability for adding new games without authentication rework

### Success Metrics
- Single registration enables access to all connected game applications
- Authentication response time < 200ms
- 99.9% uptime for authentication services
- Zero security breaches related to authentication

---

## Functional Requirements

### FR-1: User Types & Authentication Models

The system supports two distinct user types with different authentication flows:

```
┌─────────────────────────────────────────────────────────────────┐
│                    AUTHENTICATION MODELS                        │
├─────────────────────────────────────────────────────────────────┤
│                                                                 │
│  ONLINE USERS                    VENUE USERS                    │
│  ────────────                    ───────────                    │
│  • Full registration             • No registration required     │
│  • Email/username login          • Code-based login (6-9 digit) │
│  • Persistent account            • Code = temporary account     │
│  • KYC verification              • No KYC required              │
│  • Full responsible gambling     • Venue-level limits           │
│  • Cross-game SSO                • Single venue/game access     │
│                                                                 │
│  VENUE STAFF                                                    │
│  ───────────                                                    │
│  • Staff registration            • Assigned to venue(s)         │
│  • Can create player codes       • Can load credits on codes    │
│  • Can view code balances        • Can deactivate codes         │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

---

### FR-1A: Online User Registration & Authentication

#### FR-1A.1: User Registration
- Email and password registration with strong password requirements
- Phone number registration with OTP verification
- Email verification flow with secure tokens
- Username selection with uniqueness validation
- Date of birth collection (required for age verification)
- Country/region selection for jurisdiction compliance
- Terms of service and privacy policy acceptance tracking

#### FR-1A.2: User Login
- Email/username + password authentication
- Phone number + OTP authentication
- Remember me functionality with secure long-lived tokens
- Account lockout after 5 failed attempts (30-minute lockout)
- Login notification emails for new devices/locations

#### FR-1A.3: Multi-Factor Authentication (MFA)
- TOTP-based authenticator app support (Google Authenticator, Authy)
- SMS OTP as backup method
- Email OTP as secondary backup
- MFA enforcement options (optional, required, required for withdrawals)
- Recovery codes generation (one-time use)

#### FR-1A.4: Password Management
- Secure password reset via email link (expires in 1 hour)
- Password change (requires current password)
- Password history (prevent reuse of last 5 passwords)
- Breach detection integration (HaveIBeenPwned API)

#### FR-1A.5: Social Authentication (Optional Phase 2)
- Google OAuth integration
- Facebook OAuth integration
- Apple Sign-In integration
- Account linking for existing users

---

### FR-1B: Venue Code-Based Authentication (Bar/Pub Gaming)

This authentication model supports anonymous players at physical venues (bars, pubs, casinos) who access games via voucher codes created by venue staff.

#### FR-1B.1: Voucher Code System Overview
```
┌──────────────────────────────────────────────────────────────────────┐
│                     VOUCHER CODE FLOW                                │
├──────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  1. STAFF CREATES CODE                                               │
│     └─→ System generates unique 6-9 digit code                       │
│     └─→ Code is linked to venue                                      │
│     └─→ Optional: Staff loads initial credits                        │
│                                                                      │
│  2. PLAYER RECEIVES CODE                                             │
│     └─→ Bartender gives code (printed/verbal/display)                │
│     └─→ Player can add more credits via staff                        │
│                                                                      │
│  3. PLAYER USES CODE                                                 │
│     └─→ Enters code on game terminal/kiosk                           │
│     └─→ System validates code + venue + balance                      │
│     └─→ Player gains access to play                                  │
│                                                                      │
│  4. GAMEPLAY                                                         │
│     └─→ Credits deducted/added based on play                         │
│     └─→ Session can timeout after inactivity                         │
│                                                                      │
│  5. CASH OUT                                                         │
│     └─→ Player returns to staff with code                            │
│     └─→ Staff verifies balance and pays out                          │
│     └─→ Code can be deactivated or reused                            │
│                                                                      │
└──────────────────────────────────────────────────────────────────────┘
```

#### FR-1B.2: Voucher Code Properties
- **Code Format:** 6-9 alphanumeric characters (configurable per venue)
- **Code Generation:** Cryptographically random, no sequential patterns
- **Uniqueness:** Globally unique across all venues
- **Case Sensitivity:** Case-insensitive for user convenience
- **Character Set:** Excludes ambiguous characters (0/O, 1/I/L)

#### FR-1B.3: Voucher Code Lifecycle States
```
CREATED → ACTIVE → [IN_USE] → CASHED_OUT
                 ↓
              EXPIRED
                 ↓
             DEACTIVATED
```

| State | Description |
|-------|-------------|
| CREATED | Code generated but no credits loaded yet |
| ACTIVE | Credits loaded, ready for use |
| IN_USE | Currently being used in a game session |
| CASHED_OUT | Player cashed out, code can be reactivated |
| EXPIRED | Exceeded validity period (configurable) |
| DEACTIVATED | Manually disabled by staff/admin |

#### FR-1B.4: Voucher Code Features
- **Balance Management:**
  - Load credits onto code (by staff)
  - Check balance (by staff or player via game)
  - Deduct credits (during gameplay)
  - Add winnings (during gameplay)
  - Cash out (by staff)

- **Security Features:**
  - PIN protection (optional 4-digit PIN)
  - Venue binding (code only works at assigned venue)
  - Device binding (optional - code locked to specific terminal)
  - Maximum balance limit (configurable per venue)
  - Daily transaction limits

- **Expiration Rules:**
  - Configurable validity period (e.g., 24 hours, 7 days, 30 days)
  - Auto-expire after inactivity period
  - Grace period for expired codes with balance
  - Admin can extend expiration

#### FR-1B.5: Voucher Code Authentication Flow
```
POST /api/v1/venue/auth/code

Request:
{
  "code": "ABC123XYZ",
  "venue_id": "venue_uuid",
  "terminal_id": "terminal_001",  // optional
  "pin": "1234"                   // if PIN-protected
}

Response (Success):
{
  "success": true,
  "session_token": "eyJ...",
  "code_info": {
    "code": "ABC***XYZ",          // masked
    "balance": 150.00,
    "currency": "NAD",
    "expires_at": "2024-12-31T23:59:59Z",
    "has_pin": true,
    "venue_name": "The Brew Pub"
  }
}
```

---

### FR-1C: Venue & Staff Management

#### FR-1C.1: Venue Registration
- Venue name and business details
- Physical address and geolocation
- Contact information
- Operating hours
- License/permit numbers
- Assigned games/terminals
- Venue-specific settings (code format, limits, expiration rules)

#### FR-1C.2: Venue Staff Management
- Staff registration (by venue admin or system admin)
- Role assignment:
  ```
  VENUE_OWNER   - Full venue control, can manage other staff
  VENUE_MANAGER - Can create codes, manage credits, view reports
  VENUE_STAFF   - Can create codes and load credits only
  VENUE_CASHIER - Can cash out codes and view balances only
  ```
- Staff authentication (username/password or PIN)
- Staff activity logging
- Shift management (optional)

#### FR-1C.3: Staff Code Operations
- **Create Code:**
  - Generate new voucher code
  - Set initial balance (optional)
  - Set expiration (or use venue default)
  - Set PIN (optional)
  - Print code receipt (integration)

- **Load Credits:**
  - Add credits to existing code
  - Record payment method (cash, card, etc.)
  - Generate receipt

- **Check Balance:**
  - View code balance and status
  - View transaction history for code

- **Cash Out:**
  - Process payout for code balance
  - Deduct balance from code
  - Mark code as cashed out
  - Generate payout receipt

- **Deactivate Code:**
  - Disable code (lost/stolen)
  - Transfer balance to new code (optional)

#### FR-1C.4: Venue Terminal Management
- Register game terminals/kiosks
- Assign terminals to venues
- Terminal authentication (API key per terminal)
- Terminal status monitoring
- Remote terminal configuration

---

### FR-1D: Unified User Model

Both online users and venue codes share a common transaction and gaming history model:

```
                    ┌─────────────────┐
                    │  PLAYER ENTITY  │
                    │   (Abstract)    │
                    └────────┬────────┘
                             │
              ┌──────────────┴──────────────┐
              │                             │
     ┌────────▼────────┐         ┌──────────▼──────────┐
     │   ONLINE USER   │         │   VOUCHER CODE      │
     │  (Registered)   │         │   (Anonymous)       │
     ├─────────────────┤         ├─────────────────────┤
     │ • User ID       │         │ • Code ID           │
     │ • Email         │         │ • Code String       │
     │ • Username      │         │ • Venue ID          │
     │ • Wallet        │         │ • Balance           │
     │ • KYC Status    │         │ • PIN (optional)    │
     │ • Full Profile  │         │ • Expiry            │
     └────────┬────────┘         └──────────┬──────────┘
              │                             │
              └──────────────┬──────────────┘
                             │
                    ┌────────▼────────┐
                    │ GAMING SESSION  │
                    │ TRANSACTIONS    │
                    │ HISTORY         │
                    └─────────────────┘
```

#### FR-1D.1: Code-to-Account Upgrade (Optional)
- Allow venue code holders to "upgrade" to full account
- Transfer code balance to new account wallet
- Link gaming history from code to account
- One-time upgrade process

### FR-2: OAuth2 / OpenID Connect Server

#### FR-2.1: OAuth2 Authorization Server
- Authorization Code Grant flow (primary for web apps)
- Authorization Code with PKCE (for mobile/SPA apps)
- Client Credentials Grant (for server-to-server)
- Refresh Token rotation with expiry
- Token revocation endpoint

#### FR-2.2: Client Application Management
- Register new client applications (games)
- Generate client ID and client secret
- Configure allowed redirect URIs
- Set token expiration policies per client
- Enable/disable clients
- Scope management per client

#### FR-2.3: Token Management
- Access tokens (JWT format, 15-minute expiry default)
- Refresh tokens (30-day expiry, single use with rotation)
- ID tokens for OpenID Connect
- Token introspection endpoint
- Centralized logout / token revocation

#### FR-2.4: Scopes & Permissions
```
- openid          : Basic OpenID Connect
- profile         : User profile information
- email           : Email address
- phone           : Phone number
- wallet          : Wallet balance (read)
- wallet:write    : Wallet transactions
- kyc             : KYC verification status
- gaming:history  : Gaming history access
- admin           : Administrative access
```

### FR-3: User Profile Management

#### FR-3.1: Profile Information
- Display name / nickname
- Avatar upload (with image processing)
- Contact information (email, phone)
- Address information
- Preferred language
- Timezone setting
- Communication preferences (email, SMS, push notifications)

#### FR-3.2: Account Settings
- Email change (with reverification)
- Phone number change (with OTP verification)
- Password change
- MFA settings
- Active sessions management (view and revoke)
- Connected applications list
- Account deletion request

### FR-4: KYC (Know Your Customer) Integration

#### FR-4.1: Verification Levels
```
Level 0: Unverified (email only) - Limited functionality
Level 1: Basic (email + phone + DOB verified) - Standard limits
Level 2: Enhanced (ID document verified) - Higher limits
Level 3: Full (ID + proof of address + source of funds) - No limits
```

#### FR-4.2: Document Upload
- ID document upload (passport, national ID, driver's license)
- Selfie verification (liveness detection)
- Proof of address upload (utility bill, bank statement)
- Document status tracking (pending, approved, rejected)
- Integration hooks for third-party KYC providers (Jumio, Onfido, etc.)

#### FR-4.3: Age Verification
- Date of birth validation (18+ requirement)
- ID document age verification
- Block underage users from registration completion

### FR-5: Responsible Gambling Controls

#### FR-5.1: Self-Exclusion
- Temporary self-exclusion (24h, 7 days, 30 days, 90 days)
- Permanent self-exclusion
- Cooling-off period before reactivation (temporary)
- No reactivation for permanent exclusion
- Self-exclusion applies across ALL connected games

#### FR-5.2: Deposit Limits
- Daily deposit limit
- Weekly deposit limit
- Monthly deposit limit
- Limit decrease: immediate effect
- Limit increase: 24-hour cooling-off period

#### FR-5.3: Session Limits
- Session duration limits
- Loss limits per session
- Reality check reminders (configurable intervals)

#### FR-5.4: Activity Controls
- Account timeout settings
- Login time restrictions
- Wagering limits

### FR-6: Wallet Integration (Optional - Phase 2)

#### FR-6.1: Centralized Wallet
- Single wallet balance across all games
- Deposit methods integration
- Withdrawal processing
- Transaction history
- Balance API for game clients

### FR-7: Administration Panel

#### FR-7.1: User Management
- Search and filter users
- View user details and activity
- Manual KYC verification
- Account status management (active, suspended, banned)
- Password reset for users
- Impersonation (with audit logging)

#### FR-7.2: Client Application Management
- CRUD operations for OAuth clients
- Credential regeneration
- Usage statistics per client

#### FR-7.3: Reporting & Analytics
- Registration statistics
- Login statistics and patterns
- Failed login attempts
- KYC completion rates
- Self-exclusion statistics

#### FR-7.4: Audit Logging
- All authentication events
- All administrative actions
- API access logs
- Security-relevant events

#### FR-7.5: System Configuration
- Global security settings
- Email template management
- SMS gateway configuration
- Feature flags

---

## Non-Functional Requirements

### NFR-1: Security
- All passwords hashed with bcrypt (cost factor 12)
- All API communication over HTTPS/TLS 1.3
- CSRF protection on all forms
- Rate limiting on all endpoints
- SQL injection prevention (parameterized queries)
- XSS prevention (output encoding)
- Secure session management
- API authentication via Bearer tokens
- IP-based suspicious activity detection
- Compliance with GDPR for EU users

### NFR-2: Performance
- Authentication API response time: < 200ms (p95)
- Token validation: < 50ms
- Support 10,000 concurrent authenticated sessions
- Database query optimization with proper indexing
- Redis caching for session and token data

### NFR-3: Availability
- 99.9% uptime SLA
- Zero-downtime deployments
- Database replication for failover
- Health check endpoints

### NFR-4: Scalability
- Horizontal scaling capability
- Stateless API design (JWT tokens)
- Database connection pooling
- Queue-based processing for non-critical tasks

### NFR-5: Monitoring & Logging
- Structured logging (JSON format)
- Error tracking integration (Sentry)
- Performance monitoring
- Security event alerting
- Audit trail retention (minimum 7 years for gambling compliance)

---

## Database Schema

### Core Tables

```sql
-- Users table
users:
  - id (BIGINT, PK)
  - uuid (UUID, unique)
  - username (VARCHAR(50), unique, nullable)
  - email (VARCHAR(255), unique)
  - email_verified_at (TIMESTAMP, nullable)
  - phone (VARCHAR(20), nullable)
  - phone_verified_at (TIMESTAMP, nullable)
  - password (VARCHAR(255))
  - date_of_birth (DATE)
  - country_code (CHAR(2))
  - display_name (VARCHAR(100), nullable)
  - avatar_url (VARCHAR(500), nullable)
  - kyc_level (TINYINT, default 0)
  - status (ENUM: active, suspended, banned, self_excluded)
  - mfa_enabled (BOOLEAN, default false)
  - mfa_secret (VARCHAR(255), nullable, encrypted)
  - last_login_at (TIMESTAMP, nullable)
  - last_login_ip (VARCHAR(45), nullable)
  - terms_accepted_at (TIMESTAMP)
  - created_at (TIMESTAMP)
  - updated_at (TIMESTAMP)
  - deleted_at (TIMESTAMP, nullable)

-- OAuth Clients (Games/Applications)
oauth_clients:
  - id (BIGINT, PK)
  - uuid (UUID, unique)
  - name (VARCHAR(255))
  - slug (VARCHAR(100), unique)
  - secret (VARCHAR(100)) -- hashed
  - redirect_uris (JSON)
  - allowed_scopes (JSON)
  - is_confidential (BOOLEAN)
  - is_active (BOOLEAN)
  - token_expiry_minutes (INT, default 15)
  - refresh_token_expiry_days (INT, default 30)
  - created_at (TIMESTAMP)
  - updated_at (TIMESTAMP)

-- Access Tokens
oauth_access_tokens:
  - id (VARCHAR(100), PK)
  - user_id (BIGINT, FK, nullable)
  - client_id (BIGINT, FK)
  - scopes (JSON)
  - revoked (BOOLEAN)
  - created_at (TIMESTAMP)
  - updated_at (TIMESTAMP)
  - expires_at (TIMESTAMP)

-- Refresh Tokens
oauth_refresh_tokens:
  - id (VARCHAR(100), PK)
  - access_token_id (VARCHAR(100), FK)
  - revoked (BOOLEAN)
  - expires_at (TIMESTAMP)

-- Authorization Codes
oauth_auth_codes:
  - id (VARCHAR(100), PK)
  - user_id (BIGINT, FK)
  - client_id (BIGINT, FK)
  - scopes (JSON)
  - revoked (BOOLEAN)
  - expires_at (TIMESTAMP)

-- User Sessions
user_sessions:
  - id (BIGINT, PK)
  - user_id (BIGINT, FK)
  - ip_address (VARCHAR(45))
  - user_agent (TEXT)
  - device_type (VARCHAR(50))
  - location (VARCHAR(255), nullable)
  - last_activity_at (TIMESTAMP)
  - created_at (TIMESTAMP)

-- MFA Recovery Codes
mfa_recovery_codes:
  - id (BIGINT, PK)
  - user_id (BIGINT, FK)
  - code (VARCHAR(20)) -- hashed
  - used_at (TIMESTAMP, nullable)
  - created_at (TIMESTAMP)

-- KYC Documents
kyc_documents:
  - id (BIGINT, PK)
  - user_id (BIGINT, FK)
  - document_type (ENUM: passport, national_id, drivers_license, proof_of_address, selfie)
  - file_path (VARCHAR(500))
  - status (ENUM: pending, approved, rejected)
  - rejection_reason (TEXT, nullable)
  - verified_by (BIGINT, FK, nullable)
  - verified_at (TIMESTAMP, nullable)
  - expires_at (DATE, nullable)
  - created_at (TIMESTAMP)
  - updated_at (TIMESTAMP)

-- Responsible Gambling Settings
responsible_gambling_settings:
  - id (BIGINT, PK)
  - user_id (BIGINT, FK, unique)
  - daily_deposit_limit (DECIMAL(15,2), nullable)
  - weekly_deposit_limit (DECIMAL(15,2), nullable)
  - monthly_deposit_limit (DECIMAL(15,2), nullable)
  - session_time_limit_minutes (INT, nullable)
  - session_loss_limit (DECIMAL(15,2), nullable)
  - reality_check_interval_minutes (INT, nullable)
  - created_at (TIMESTAMP)
  - updated_at (TIMESTAMP)

-- Self Exclusions
self_exclusions:
  - id (BIGINT, PK)
  - user_id (BIGINT, FK)
  - type (ENUM: temporary, permanent)
  - reason (TEXT, nullable)
  - starts_at (TIMESTAMP)
  - ends_at (TIMESTAMP, nullable)
  - revoked_at (TIMESTAMP, nullable)
  - created_at (TIMESTAMP)

-- Audit Logs
audit_logs:
  - id (BIGINT, PK)
  - user_id (BIGINT, FK, nullable)
  - admin_id (BIGINT, FK, nullable)
  - action (VARCHAR(100))
  - entity_type (VARCHAR(100))
  - entity_id (BIGINT, nullable)
  - old_values (JSON, nullable)
  - new_values (JSON, nullable)
  - ip_address (VARCHAR(45))
  - user_agent (TEXT)
  - created_at (TIMESTAMP)

-- Login Attempts (for rate limiting and security)
login_attempts:
  - id (BIGINT, PK)
  - email (VARCHAR(255))
  - ip_address (VARCHAR(45))
  - success (BOOLEAN)
  - failure_reason (VARCHAR(100), nullable)
  - created_at (TIMESTAMP)

-- Password Reset Tokens
password_resets:
  - id (BIGINT, PK)
  - email (VARCHAR(255))
  - token (VARCHAR(255)) -- hashed
  - created_at (TIMESTAMP)
  - expires_at (TIMESTAMP)
  - used_at (TIMESTAMP, nullable)
```

### Venue & Voucher Code Tables

```sql
-- Venues (Bars, Pubs, etc.)
venues:
  - id (BIGINT, PK)
  - uuid (UUID, unique)
  - name (VARCHAR(255))
  - slug (VARCHAR(100), unique)
  - business_name (VARCHAR(255), nullable)
  - license_number (VARCHAR(100), nullable)
  - address_line_1 (VARCHAR(255))
  - address_line_2 (VARCHAR(255), nullable)
  - city (VARCHAR(100))
  - region (VARCHAR(100), nullable)
  - postal_code (VARCHAR(20), nullable)
  - country_code (CHAR(2))
  - latitude (DECIMAL(10,8), nullable)
  - longitude (DECIMAL(11,8), nullable)
  - phone (VARCHAR(20), nullable)
  - email (VARCHAR(255), nullable)
  - timezone (VARCHAR(50), default 'Africa/Windhoek')
  - currency (CHAR(3), default 'NAD')
  - status (ENUM: active, suspended, closed)
  - settings (JSON) -- venue-specific config
  - created_at (TIMESTAMP)
  - updated_at (TIMESTAMP)
  - deleted_at (TIMESTAMP, nullable)

-- Venue Settings (JSON structure reference)
-- {
--   "code_format": {
--     "length": 6,           // 6-9 digits
--     "type": "alphanumeric" // numeric, alphanumeric, alpha
--   },
--   "code_defaults": {
--     "expiry_hours": 24,
--     "max_balance": 10000.00,
--     "require_pin": false
--   },
--   "limits": {
--     "daily_load_limit": 50000.00,
--     "single_load_max": 5000.00
--   },
--   "operating_hours": {
--     "mon": {"open": "10:00", "close": "02:00"},
--     ...
--   }
-- }

-- Venue Staff Members
venue_staff:
  - id (BIGINT, PK)
  - uuid (UUID, unique)
  - venue_id (BIGINT, FK)
  - username (VARCHAR(50))
  - email (VARCHAR(255), nullable)
  - phone (VARCHAR(20), nullable)
  - password (VARCHAR(255))
  - pin (VARCHAR(255), nullable) -- hashed, for quick POS access
  - display_name (VARCHAR(100))
  - role (ENUM: owner, manager, staff, cashier)
  - status (ENUM: active, suspended, terminated)
  - last_login_at (TIMESTAMP, nullable)
  - created_at (TIMESTAMP)
  - updated_at (TIMESTAMP)
  - deleted_at (TIMESTAMP, nullable)
  - UNIQUE(venue_id, username)

-- Venue Terminals (Game machines, kiosks)
venue_terminals:
  - id (BIGINT, PK)
  - uuid (UUID, unique)
  - venue_id (BIGINT, FK)
  - terminal_code (VARCHAR(50)) -- e.g., "TERM-001"
  - name (VARCHAR(100))
  - type (ENUM: kiosk, tablet, terminal, pos)
  - api_key (VARCHAR(255)) -- hashed
  - status (ENUM: active, inactive, maintenance)
  - last_heartbeat_at (TIMESTAMP, nullable)
  - ip_address (VARCHAR(45), nullable)
  - settings (JSON, nullable)
  - created_at (TIMESTAMP)
  - updated_at (TIMESTAMP)
  - UNIQUE(venue_id, terminal_code)

-- Voucher Codes (Anonymous player accounts for venues)
voucher_codes:
  - id (BIGINT, PK)
  - uuid (UUID, unique)
  - venue_id (BIGINT, FK)
  - code (VARCHAR(20), unique) -- the actual code players use
  - pin (VARCHAR(255), nullable) -- hashed 4-digit PIN
  - balance (DECIMAL(15,2), default 0.00)
  - currency (CHAR(3), default 'NAD')
  - status (ENUM: created, active, in_use, cashed_out, expired, deactivated)
  - created_by_staff_id (BIGINT, FK)
  - current_terminal_id (BIGINT, FK, nullable) -- if in_use
  - current_session_id (BIGINT, FK, nullable)
  - total_loaded (DECIMAL(15,2), default 0.00) -- lifetime loads
  - total_won (DECIMAL(15,2), default 0.00)
  - total_lost (DECIMAL(15,2), default 0.00)
  - total_cashed_out (DECIMAL(15,2), default 0.00)
  - last_activity_at (TIMESTAMP, nullable)
  - expires_at (TIMESTAMP, nullable)
  - created_at (TIMESTAMP)
  - updated_at (TIMESTAMP)

-- Voucher Code Sessions (tracks when code is in use)
voucher_sessions:
  - id (BIGINT, PK)
  - uuid (UUID, unique)
  - voucher_code_id (BIGINT, FK)
  - terminal_id (BIGINT, FK, nullable)
  - game_client_id (BIGINT, FK, nullable) -- which game
  - session_token (VARCHAR(255), unique)
  - ip_address (VARCHAR(45), nullable)
  - balance_start (DECIMAL(15,2))
  - balance_end (DECIMAL(15,2), nullable)
  - started_at (TIMESTAMP)
  - ended_at (TIMESTAMP, nullable)
  - end_reason (ENUM: logout, timeout, cashed_out, forced, nullable)

-- Voucher Transactions (all money movements)
voucher_transactions:
  - id (BIGINT, PK)
  - uuid (UUID, unique)
  - voucher_code_id (BIGINT, FK)
  - session_id (BIGINT, FK, nullable)
  - type (ENUM: load, win, loss, cashout, adjustment, transfer_in, transfer_out)
  - amount (DECIMAL(15,2)) -- positive for credits, negative for debits
  - balance_before (DECIMAL(15,2))
  - balance_after (DECIMAL(15,2))
  - reference (VARCHAR(100), nullable) -- external ref like game round ID
  - description (TEXT, nullable)
  - performed_by_staff_id (BIGINT, FK, nullable)
  - terminal_id (BIGINT, FK, nullable)
  - metadata (JSON, nullable) -- game-specific data
  - created_at (TIMESTAMP)

-- Staff Cash Drawer / Shift Tracking (optional)
venue_shifts:
  - id (BIGINT, PK)
  - venue_id (BIGINT, FK)
  - staff_id (BIGINT, FK)
  - terminal_id (BIGINT, FK, nullable)
  - opening_balance (DECIMAL(15,2))
  - closing_balance (DECIMAL(15,2), nullable)
  - total_loads (DECIMAL(15,2), default 0.00)
  - total_cashouts (DECIMAL(15,2), default 0.00)
  - started_at (TIMESTAMP)
  - ended_at (TIMESTAMP, nullable)
  - notes (TEXT, nullable)

-- Code Upgrade Requests (when venue user wants full account)
code_upgrade_requests:
  - id (BIGINT, PK)
  - voucher_code_id (BIGINT, FK)
  - user_id (BIGINT, FK) -- the new online account
  - balance_transferred (DECIMAL(15,2))
  - status (ENUM: pending, completed, failed)
  - processed_at (TIMESTAMP, nullable)
  - created_at (TIMESTAMP)

---

## API Endpoints

### Authentication Endpoints

```
POST   /api/v1/auth/register              - User registration
POST   /api/v1/auth/login                 - User login
POST   /api/v1/auth/logout                - User logout
POST   /api/v1/auth/refresh               - Refresh access token
POST   /api/v1/auth/forgot-password       - Request password reset
POST   /api/v1/auth/reset-password        - Reset password with token
POST   /api/v1/auth/verify-email          - Verify email address
POST   /api/v1/auth/resend-verification   - Resend verification email
POST   /api/v1/auth/verify-phone          - Verify phone with OTP
POST   /api/v1/auth/send-phone-otp        - Send OTP to phone
```

### MFA Endpoints

```
POST   /api/v1/auth/mfa/setup             - Initialize MFA setup
POST   /api/v1/auth/mfa/verify-setup      - Confirm MFA setup with code
POST   /api/v1/auth/mfa/verify            - Verify MFA code during login
POST   /api/v1/auth/mfa/disable           - Disable MFA
GET    /api/v1/auth/mfa/recovery-codes    - Get recovery codes
POST   /api/v1/auth/mfa/regenerate-codes  - Generate new recovery codes
POST   /api/v1/auth/mfa/recover           - Login with recovery code
```

### OAuth2 Endpoints

```
GET    /oauth/authorize                   - Authorization endpoint
POST   /oauth/token                       - Token endpoint
POST   /oauth/token/revoke                - Revoke token
POST   /oauth/introspect                  - Token introspection
GET    /oauth/userinfo                    - OpenID Connect userinfo
GET    /.well-known/openid-configuration  - OIDC discovery document
GET    /.well-known/jwks.json             - JSON Web Key Set
```

### User Profile Endpoints

```
GET    /api/v1/user                       - Get current user profile
PUT    /api/v1/user                       - Update profile
POST   /api/v1/user/avatar                - Upload avatar
DELETE /api/v1/user/avatar                - Remove avatar
PUT    /api/v1/user/email                 - Change email
PUT    /api/v1/user/phone                 - Change phone
PUT    /api/v1/user/password              - Change password
GET    /api/v1/user/sessions              - List active sessions
DELETE /api/v1/user/sessions/{id}         - Revoke session
DELETE /api/v1/user/sessions              - Revoke all other sessions
GET    /api/v1/user/connected-apps        - List connected applications
DELETE /api/v1/user/connected-apps/{id}   - Revoke application access
POST   /api/v1/user/delete-account        - Request account deletion
```

### KYC Endpoints

```
GET    /api/v1/kyc/status                 - Get KYC status
POST   /api/v1/kyc/documents              - Upload document
GET    /api/v1/kyc/documents              - List uploaded documents
GET    /api/v1/kyc/documents/{id}         - Get document details
```

### Responsible Gambling Endpoints

```
GET    /api/v1/responsible-gambling       - Get current settings
PUT    /api/v1/responsible-gambling       - Update settings
POST   /api/v1/responsible-gambling/self-exclude  - Self-exclude
GET    /api/v1/responsible-gambling/self-exclude  - Get exclusion status
```

### Admin API Endpoints

```
GET    /api/v1/admin/users                - List users
GET    /api/v1/admin/users/{id}           - Get user details
PUT    /api/v1/admin/users/{id}           - Update user
POST   /api/v1/admin/users/{id}/suspend   - Suspend user
POST   /api/v1/admin/users/{id}/ban       - Ban user
POST   /api/v1/admin/users/{id}/activate  - Activate user
POST   /api/v1/admin/users/{id}/reset-password - Reset user password
POST   /api/v1/admin/users/{id}/verify-kyc     - Verify KYC document

GET    /api/v1/admin/clients              - List OAuth clients
POST   /api/v1/admin/clients              - Create OAuth client
GET    /api/v1/admin/clients/{id}         - Get client details
PUT    /api/v1/admin/clients/{id}         - Update client
DELETE /api/v1/admin/clients/{id}         - Delete client
POST   /api/v1/admin/clients/{id}/regenerate-secret - Regenerate secret

GET    /api/v1/admin/audit-logs           - List audit logs
GET    /api/v1/admin/reports/registrations     - Registration stats
GET    /api/v1/admin/reports/logins            - Login stats
GET    /api/v1/admin/reports/kyc               - KYC stats
```

### Venue Management Endpoints (Admin)

```
GET    /api/v1/admin/venues               - List all venues
POST   /api/v1/admin/venues               - Create venue
GET    /api/v1/admin/venues/{id}          - Get venue details
PUT    /api/v1/admin/venues/{id}          - Update venue
DELETE /api/v1/admin/venues/{id}          - Soft delete venue
POST   /api/v1/admin/venues/{id}/suspend  - Suspend venue
POST   /api/v1/admin/venues/{id}/activate - Activate venue

GET    /api/v1/admin/venues/{id}/staff    - List venue staff
POST   /api/v1/admin/venues/{id}/staff    - Add staff member
GET    /api/v1/admin/venues/{id}/terminals - List terminals
POST   /api/v1/admin/venues/{id}/terminals - Register terminal

GET    /api/v1/admin/venues/{id}/reports  - Venue reports/analytics
GET    /api/v1/admin/voucher-codes        - Search all voucher codes
```

### Venue Staff Endpoints (Staff Portal)

```
POST   /api/v1/venue/auth/login           - Staff login
POST   /api/v1/venue/auth/logout          - Staff logout
POST   /api/v1/venue/auth/pin-login       - Quick PIN login (same device)
GET    /api/v1/venue/profile              - Get staff profile
PUT    /api/v1/venue/profile/password     - Change password

# Voucher Code Management
POST   /api/v1/venue/codes                - Create new voucher code
GET    /api/v1/venue/codes                - List codes (with filters)
GET    /api/v1/venue/codes/{code}         - Get code details by code string
GET    /api/v1/venue/codes/{code}/balance - Quick balance check
POST   /api/v1/venue/codes/{code}/load    - Load credits onto code
POST   /api/v1/venue/codes/{code}/cashout - Cash out code balance
POST   /api/v1/venue/codes/{code}/deactivate - Deactivate code
POST   /api/v1/venue/codes/{code}/transfer   - Transfer balance to another code
POST   /api/v1/venue/codes/{code}/set-pin    - Set/change PIN
POST   /api/v1/venue/codes/{code}/extend     - Extend expiration
GET    /api/v1/venue/codes/{code}/transactions - Transaction history

# Shift Management
POST   /api/v1/venue/shifts/start         - Start shift
POST   /api/v1/venue/shifts/end           - End shift
GET    /api/v1/venue/shifts/current       - Get current shift
GET    /api/v1/venue/shifts               - List past shifts

# Reports (for managers)
GET    /api/v1/venue/reports/daily        - Daily summary
GET    /api/v1/venue/reports/codes        - Code statistics
GET    /api/v1/venue/reports/transactions - Transaction report
```

### Venue Code Authentication (Game Terminals/Clients)

```
# Terminal Authentication
POST   /api/v1/terminal/auth              - Terminal authenticates with API key
POST   /api/v1/terminal/heartbeat         - Terminal heartbeat/status

# Player Code Authentication (used by games)
POST   /api/v1/venue/auth/code            - Authenticate with voucher code
POST   /api/v1/venue/auth/code/verify-pin - Verify PIN for code
POST   /api/v1/venue/auth/code/logout     - End code session
GET    /api/v1/venue/auth/code/session    - Get current session info

# Game Integration (called by game servers)
GET    /api/v1/venue/player/balance       - Get player balance
POST   /api/v1/venue/player/debit         - Deduct credits (bet/wager)
POST   /api/v1/venue/player/credit        - Add credits (win)
POST   /api/v1/venue/player/transaction   - Generic transaction
GET    /api/v1/venue/player/can-play      - Check if player can play (balance, limits)
```

### Code-to-Account Upgrade

```
POST   /api/v1/venue/codes/{code}/upgrade - Initiate upgrade to full account
GET    /api/v1/venue/codes/{code}/upgrade-status - Check upgrade status
```

---

## Project Structure

```
gameauth/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Auth/
│   │   │   │   ├── RegisterController.php
│   │   │   │   ├── LoginController.php
│   │   │   │   ├── ForgotPasswordController.php
│   │   │   │   ├── ResetPasswordController.php
│   │   │   │   ├── VerificationController.php
│   │   │   │   ├── MfaController.php
│   │   │   │   └── OAuthController.php
│   │   │   ├── Api/
│   │   │   │   ├── UserController.php
│   │   │   │   ├── KycController.php
│   │   │   │   └── ResponsibleGamblingController.php
│   │   │   ├── Admin/
│   │   │   │   ├── UserManagementController.php
│   │   │   │   ├── ClientManagementController.php
│   │   │   │   ├── VenueManagementController.php
│   │   │   │   ├── AuditLogController.php
│   │   │   │   └── ReportController.php
│   │   │   ├── Venue/                          # NEW: Venue Staff Portal
│   │   │   │   ├── AuthController.php          # Staff login/logout
│   │   │   │   ├── VoucherCodeController.php   # Code CRUD operations
│   │   │   │   ├── TransactionController.php   # Load/cashout/history
│   │   │   │   ├── ShiftController.php         # Shift management
│   │   │   │   └── ReportController.php        # Venue reports
│   │   │   └── Terminal/                       # NEW: Game Terminal API
│   │   │       ├── AuthController.php          # Terminal + code auth
│   │   │       └── PlayerController.php        # Balance/debit/credit
│   │   ├── Middleware/
│   │   │   ├── CheckSelfExclusion.php
│   │   │   ├── CheckKycLevel.php
│   │   │   ├── RateLimitAuthentication.php
│   │   │   ├── AuditLogActivity.php
│   │   │   ├── AuthenticateVenueStaff.php      # NEW
│   │   │   ├── AuthenticateTerminal.php        # NEW
│   │   │   └── AuthenticateVoucherCode.php     # NEW
│   │   └── Requests/
│   │       ├── Auth/
│   │       ├── Api/
│   │       └── Venue/                          # NEW
│   │           ├── CreateCodeRequest.php
│   │           ├── LoadCreditsRequest.php
│   │           └── CashoutRequest.php
│   ├── Models/
│   │   ├── User.php
│   │   ├── OAuthClient.php
│   │   ├── UserSession.php
│   │   ├── KycDocument.php
│   │   ├── ResponsibleGamblingSetting.php
│   │   ├── SelfExclusion.php
│   │   ├── AuditLog.php
│   │   ├── LoginAttempt.php
│   │   ├── Venue.php                           # NEW
│   │   ├── VenueStaff.php                      # NEW
│   │   ├── VenueTerminal.php                   # NEW
│   │   ├── VoucherCode.php                     # NEW
│   │   ├── VoucherSession.php                  # NEW
│   │   ├── VoucherTransaction.php              # NEW
│   │   └── VenueShift.php                      # NEW
│   ├── Services/
│   │   ├── AuthService.php
│   │   ├── MfaService.php
│   │   ├── OAuthService.php
│   │   ├── KycService.php
│   │   ├── ResponsibleGamblingService.php
│   │   ├── AuditService.php
│   │   ├── SmsService.php
│   │   ├── EmailService.php
│   │   ├── VoucherCodeService.php              # NEW: Code generation, validation
│   │   ├── VenueWalletService.php              # NEW: Balance operations
│   │   └── TerminalAuthService.php             # NEW: Terminal authentication
│   ├── Events/
│   │   ├── UserRegistered.php
│   │   ├── UserLoggedIn.php
│   │   ├── PasswordReset.php
│   │   ├── MfaEnabled.php
│   │   ├── KycDocumentUploaded.php
│   │   ├── SelfExclusionActivated.php
│   │   ├── VoucherCodeCreated.php              # NEW
│   │   ├── VoucherCodeLoaded.php               # NEW
│   │   ├── VoucherCodeCashedOut.php            # NEW
│   │   └── VoucherSessionStarted.php           # NEW
│   ├── Listeners/
│   ├── Notifications/
│   │   ├── VerifyEmail.php
│   │   ├── ResetPassword.php
│   │   ├── LoginFromNewDevice.php
│   │   └── SelfExclusionConfirmation.php
│   └── Policies/
│       ├── VenuePolicy.php                     # NEW
│       └── VoucherCodePolicy.php               # NEW
├── config/
│   ├── auth.php
│   ├── passport.php
│   ├── kyc.php
│   ├── gambling.php
│   └── venue.php                               # NEW: Venue settings
├── database/
│   ├── migrations/
│   └── seeders/
├── routes/
│   ├── api.php
│   ├── venue.php                               # NEW: Venue staff routes
│   ├── terminal.php                            # NEW: Terminal API routes
│   ├── web.php
│   └── admin.php
├── resources/
│   └── views/
│       ├── auth/
│       ├── admin/
│       └── emails/
└── tests/
    ├── Feature/
    │   ├── Auth/
    │   ├── OAuth/
    │   └── Api/
    └── Unit/
```

---

## Implementation Phases

### Phase 1: Core Authentication (Weeks 1-3)
- Project setup with Laravel 11
- User registration and login (online users)
- Email verification
- Password reset
- Basic user profile
- Database migrations and models
- Unit and feature tests

### Phase 2: OAuth2 Server (Weeks 4-5)
- Laravel Passport installation and configuration
- OAuth2 authorization code flow
- PKCE support for mobile apps
- Client application management
- Token management and revocation
- OpenID Connect support

### Phase 3: Security Features (Weeks 6-7)
- Multi-factor authentication (TOTP)
- Session management
- Rate limiting
- Login attempt tracking
- Security audit logging
- Account lockout

### Phase 4: Venue & Voucher Code System (Weeks 8-10) ⭐ NEW
- Venue management (CRUD)
- Venue staff management and authentication
- Voucher code generation service
- Code lifecycle management (create, activate, expire, deactivate)
- Balance operations (load, debit, credit, cashout)
- Venue staff portal API
- Terminal registration and authentication
- Code-based player authentication
- Transaction logging and history
- Shift management (optional)

### Phase 5: KYC & Compliance (Weeks 11-12)
- KYC document upload (for online users)
- Verification workflow
- Age verification
- Responsible gambling settings
- Self-exclusion system (applies to both user types)
- Deposit limits (online users)

### Phase 6: Admin Panel (Weeks 13-14)
- Admin dashboard
- User management (online users)
- Client/game management
- Venue management interface
- Voucher code search and management
- KYC review interface
- Audit log viewer
- Reports and analytics (both user types)

### Phase 7: Integration & Testing (Weeks 15-16)
- Client SDK development (OAuth for online, API for venue)
- Terminal SDK / integration guide
- Integration documentation
- Load testing
- Security testing
- Bug fixes and optimization

### Phase 8: Game Integration (Ongoing)
- Individual game integrations
- Wallet/balance synchronization
- Game-specific reporting
- Security testing
- Bug fixes and optimization

---

## Client Integration Examples

### Laravel Client Integration

```php
// config/services.php
'gameauth' => [
    'client_id' => env('GAMEAUTH_CLIENT_ID'),
    'client_secret' => env('GAMEAUTH_CLIENT_SECRET'),
    'redirect' => env('GAMEAUTH_REDIRECT_URI'),
    'host' => env('GAMEAUTH_HOST', 'https://auth.yourdomain.com'),
],

// Custom Socialite Provider
// GameAuthProvider.php
class GameAuthProvider extends AbstractProvider
{
    protected $scopes = ['openid', 'profile', 'email', 'wallet'];
    protected $scopeSeparator = ' ';
    
    protected function getAuthUrl($state)
    {
        return $this->buildAuthUrlFromBase(
            config('services.gameauth.host') . '/oauth/authorize',
            $state
        );
    }
    
    protected function getTokenUrl()
    {
        return config('services.gameauth.host') . '/oauth/token';
    }
    
    protected function getUserByToken($token)
    {
        $response = $this->getHttpClient()->get(
            config('services.gameauth.host') . '/oauth/userinfo',
            ['headers' => ['Authorization' => 'Bearer ' . $token]]
        );
        return json_decode($response->getBody(), true);
    }
}
```

### JavaScript/React Integration

```javascript
// gameauth-client.js
class GameAuthClient {
  constructor(config) {
    this.clientId = config.clientId;
    this.redirectUri = config.redirectUri;
    this.authHost = config.authHost;
  }

  generatePKCE() {
    const verifier = this.generateRandomString(128);
    const challenge = await this.sha256Base64(verifier);
    return { verifier, challenge };
  }

  login() {
    const { verifier, challenge } = this.generatePKCE();
    sessionStorage.setItem('pkce_verifier', verifier);
    
    const params = new URLSearchParams({
      client_id: this.clientId,
      redirect_uri: this.redirectUri,
      response_type: 'code',
      scope: 'openid profile email',
      code_challenge: challenge,
      code_challenge_method: 'S256',
      state: this.generateRandomString(32)
    });
    
    window.location.href = `${this.authHost}/oauth/authorize?${params}`;
  }

  async handleCallback(code) {
    const verifier = sessionStorage.getItem('pkce_verifier');
    const response = await fetch(`${this.authHost}/oauth/token`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        grant_type: 'authorization_code',
        client_id: this.clientId,
        redirect_uri: this.redirectUri,
        code_verifier: verifier,
        code
      })
    });
    return response.json();
  }
}
```

### Venue Terminal / Game Integration (Code-Based)

```javascript
// venue-game-client.js
// For games deployed at physical venues (bars, pubs)

class VenueGameClient {
  constructor(config) {
    this.authHost = config.authHost;
    this.terminalApiKey = config.terminalApiKey;
    this.terminalId = config.terminalId;
    this.venueId = config.venueId;
    this.sessionToken = null;
    this.playerCode = null;
  }

  // Terminal authenticates itself on startup
  async authenticateTerminal() {
    const response = await fetch(`${this.authHost}/api/v1/terminal/auth`, {
      method: 'POST',
      headers: { 
        'Content-Type': 'application/json',
        'X-Terminal-Key': this.terminalApiKey
      },
      body: JSON.stringify({
        terminal_id: this.terminalId,
        venue_id: this.venueId
      })
    });
    const data = await response.json();
    if (data.success) {
      this.terminalToken = data.terminal_token;
    }
    return data;
  }

  // Player enters their voucher code to start playing
  async loginWithCode(code, pin = null) {
    const response = await fetch(`${this.authHost}/api/v1/venue/auth/code`, {
      method: 'POST',
      headers: { 
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${this.terminalToken}`
      },
      body: JSON.stringify({
        code: code,
        venue_id: this.venueId,
        terminal_id: this.terminalId,
        pin: pin  // if code is PIN-protected
      })
    });
    const data = await response.json();
    if (data.success) {
      this.sessionToken = data.session_token;
      this.playerCode = code;
    }
    return data;
  }

  // Get player's current balance
  async getBalance() {
    const response = await fetch(`${this.authHost}/api/v1/venue/player/balance`, {
      headers: { 
        'Authorization': `Bearer ${this.sessionToken}`
      }
    });
    return response.json();
  }

  // Deduct credits for a bet/wager
  async debit(amount, gameRoundId, description = null) {
    const response = await fetch(`${this.authHost}/api/v1/venue/player/debit`, {
      method: 'POST',
      headers: { 
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${this.sessionToken}`
      },
      body: JSON.stringify({
        amount: amount,
        reference: gameRoundId,
        description: description
      })
    });
    return response.json();
  }

  // Add credits for a win
  async credit(amount, gameRoundId, description = null) {
    const response = await fetch(`${this.authHost}/api/v1/venue/player/credit`, {
      method: 'POST',
      headers: { 
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${this.sessionToken}`
      },
      body: JSON.stringify({
        amount: amount,
        reference: gameRoundId,
        description: description
      })
    });
    return response.json();
  }

  // Check if player can place a bet
  async canPlay(amount) {
    const response = await fetch(
      `${this.authHost}/api/v1/venue/player/can-play?amount=${amount}`, 
      {
        headers: { 
          'Authorization': `Bearer ${this.sessionToken}`
        }
      }
    );
    return response.json();
  }

  // End player session (they're done playing)
  async logout() {
    const response = await fetch(`${this.authHost}/api/v1/venue/auth/code/logout`, {
      method: 'POST',
      headers: { 
        'Authorization': `Bearer ${this.sessionToken}`
      }
    });
    this.sessionToken = null;
    this.playerCode = null;
    return response.json();
  }
}

// Usage Example in a game:
const gameClient = new VenueGameClient({
  authHost: 'https://auth.yourgaming.com',
  terminalApiKey: 'term_key_xxx',
  terminalId: 'TERM-001',
  venueId: 'venue_uuid_xxx'
});

// On terminal startup
await gameClient.authenticateTerminal();

// When player enters code
const loginResult = await gameClient.loginWithCode('ABC123XYZ');
if (loginResult.success) {
  console.log(`Welcome! Balance: ${loginResult.code_info.balance}`);
}

// During gameplay
const betAmount = 10;
const canBet = await gameClient.canPlay(betAmount);
if (canBet.allowed) {
  const debitResult = await gameClient.debit(betAmount, 'round_12345');
  // ... game logic ...
  if (playerWon) {
    await gameClient.credit(winAmount, 'round_12345');
  }
}

// When player is done
await gameClient.logout();
```

---

## Security Checklist

- [ ] All passwords hashed with bcrypt (cost 12+)
- [ ] HTTPS enforced on all endpoints
- [ ] CSRF tokens on all forms
- [ ] Rate limiting on authentication endpoints
- [ ] SQL injection prevention (Eloquent ORM)
- [ ] XSS prevention (Blade escaping)
- [ ] Secure session configuration
- [ ] JWT tokens with appropriate expiry
- [ ] Refresh token rotation
- [ ] Failed login attempt tracking
- [ ] Account lockout mechanism
- [ ] Security headers (CSP, HSTS, etc.)
- [ ] Input validation on all endpoints
- [ ] Audit logging for all sensitive operations
- [ ] Encryption at rest for sensitive data
- [ ] Regular security dependency updates

---

## Testing Requirements

### Unit Tests
- Model validation rules
- Service class methods
- Helper functions
- Token generation/validation
- Voucher code generation algorithm
- Code format validation (excludes ambiguous chars)

### Feature Tests
- Registration flow (online users)
- Login flow (with and without MFA)
- Password reset flow
- OAuth2 authorization flow
- Token refresh flow
- Profile update operations
- KYC upload flow
- Self-exclusion flow
- **Venue Staff Authentication**
- **Voucher Code Creation**
- **Credit Loading/Cashout**
- **Code-based Player Login**
- **Balance Operations (debit/credit)**
- **Code Expiration Handling**
- **Code-to-Account Upgrade**

### Integration Tests
- Database operations
- Cache operations
- Email sending
- SMS sending (mock)
- **Terminal API authentication**
- **Multi-game balance sync**

### Security Tests
- SQL injection attempts
- XSS attempts
- CSRF protection
- Rate limit enforcement
- Token security
- **Voucher code brute force protection**
- **Staff permission enforcement**
- **Terminal API key security**

### Load Tests
- 1000 concurrent logins (online users)
- 5000 concurrent token validations
- **500 concurrent code authentications**
- **1000 concurrent balance checks**
- Database performance under load

---

## Documentation Requirements

- API documentation (OpenAPI/Swagger)
- Client integration guide (OAuth2 for online)
- **Terminal Integration Guide** (for game machines)
- **Venue Staff User Manual**
- Admin user guide
- Security best practices guide
- Deployment guide
- Troubleshooting guide

---

## Monitoring & Alerts

### Metrics to Monitor
- Authentication success/failure rates
- Token generation/validation times
- API response times
- Error rates by endpoint
- Active sessions count
- Failed login patterns
- **Active voucher codes per venue**
- **Code creation rate**
- **Load/cashout volumes**
- **Terminal health/heartbeat**

### Alert Conditions
- Error rate > 1%
- Response time > 500ms (p95)
- Failed login spike (potential brute force)
- Unusual geographic login patterns
- System resource thresholds
- **Unusual code creation patterns**
- **Terminal offline > 5 minutes**
- **Large cashout amounts**

---

## Dependencies

### PHP Packages
```json
{
    "require": {
        "laravel/framework": "^11.0",
        "laravel/passport": "^12.0",
        "laravel/sanctum": "^4.0",
        "pragmarx/google2fa": "^8.0",
        "bacon/bacon-qr-code": "^2.0",
        "intervention/image": "^3.0",
        "predis/predis": "^2.0",
        "spatie/laravel-permission": "^6.0",
        "spatie/laravel-activitylog": "^4.0"
    }
}
```

---

## Environment Configuration

```env
# Application
APP_NAME="GameAuth Central"
APP_ENV=production
APP_URL=https://auth.yourdomain.com

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gameauth
DB_USERNAME=gameauth
DB_PASSWORD=secure_password

# Redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

# Mail
MAIL_MAILER=smtp
MAIL_HOST=smtp.mailgun.org
MAIL_PORT=587
MAIL_USERNAME=
MAIL_PASSWORD=

# SMS Gateway
SMS_PROVIDER=twilio
TWILIO_SID=
TWILIO_TOKEN=
TWILIO_FROM=

# Passport
PASSPORT_PRIVATE_KEY=
PASSPORT_PUBLIC_KEY=

# Security
SESSION_LIFETIME=120
PASSWORD_TIMEOUT=10800
MFA_WINDOW=1
```

---

## Glossary

- **SSO**: Single Sign-On
- **OAuth2**: Open Authorization 2.0 protocol
- **OIDC**: OpenID Connect
- **PKCE**: Proof Key for Code Exchange
- **MFA**: Multi-Factor Authentication
- **TOTP**: Time-based One-Time Password
- **KYC**: Know Your Customer
- **JWT**: JSON Web Token
- **JWKS**: JSON Web Key Set

---

*Document Version: 1.0*
*Last Updated: December 2024*
*Author: Claude (AI Assistant)*
