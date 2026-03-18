# Game-Tenant Assignment UI & Transaction Game Tracking

**Date:** 2026-03-18
**Status:** Draft

## Problem

1. There is no UI to assign or manage games for a tenant. The backend API exists (`TenantGameController`) with sync/update endpoints, but the tenant show page only displays a read-only list.
2. Wallet and voucher transactions don't show which game they came from. The data model supports this (`wallet_transactions.game_session_id` → `game_sessions.game_id` → `games.name`), but the admin transactions page and player dashboard don't surface it.

## Solution

### Feature 1: Game-Tenant Assignment UI

**Location:** Tenant show page (`/platform/tenants/{uuid}`)

**Changes:**
- Replace the read-only "Assigned Games" `<ul>` with an interactive fieldset containing a DataTable
- DataTable columns: Game Name (with type subtitle), Status (StatusBadge), Enabled (toggle via `PUT /api/v1/platform/tenants/{tenant}/games/{game}`), Remove button
- "Manage Games" button in fieldset header opens a Dialog
- Dialog fetches `GET /api/v1/platform/tenants/{tenant}/games` which returns `{ assigned, available }` arrays
- Available games shown as a checkbox list; checking/unchecking and saving calls `POST /api/v1/platform/tenants/{tenant}/games` (sync endpoint)
- Toast notifications for success/error

**Backend changes:** None. `TenantGameController` already supports `index`, `sync`, and `update`.

**Existing API endpoints used:**
- `GET /api/v1/platform/tenants/{tenant}/games` — list assigned + available
- `POST /api/v1/platform/tenants/{tenant}/games` — bulk sync assignments
- `PUT /api/v1/platform/tenants/{tenant}/games/{game}` — toggle enabled/update settings

### Feature 2: Game Name on Transactions

**Admin Wallet Transactions page** (`/admin/wallet-transactions`)

Backend (`WalletTransactionController`):
- Wallet transactions query: `leftJoin('game_sessions', ...)` then `leftJoin('games', ...)` to include `games.name as game_name`
- Voucher transactions query: same join pattern via `voucher_transactions.game_session_id` → `game_sessions.game_id` → `games.name`
- Add `game_name` to both select lists (NULL fallback for transactions without a game session)

Frontend:
- Add "Game" column to the DataTable after the "Type" column, displaying game name or "—"
- Add a "Game" filter dropdown populated from the tenant's enabled games (or all games for platform admins)

**Player Dashboard** (`/dashboard`)

Backend (`UserDashboardController`):
- When fetching recent transactions, eager-load `gameSession.game` relationship
- Include `game_name` field in the transaction map: `$t->gameSession?->game?->name`

Frontend:
- In the recent transactions list, show game name below the transaction type where available
- Format: type on first line (e.g., "Bet"), game name on second line in lighter text (e.g., "Lucky Slots")

## Data Model (existing, no changes)

```
wallet_transactions.game_session_id → game_sessions.id
game_sessions.game_id → games.id
voucher_transactions.game_session_id → game_sessions.id (renamed from session_id)
tenant_games (pivot): tenant_id, game_id, enabled, custom_settings
```

## Files to Modify

### Feature 1
- `resources/js/pages/platform/tenants/show.tsx` — replace read-only games list with interactive management

### Feature 2
- `app/Http/Controllers/Admin/WalletTransactionController.php` — join game_sessions + games tables
- `app/Http/Controllers/UserDashboardController.php` — eager-load gameSession.game
- `resources/js/pages/admin/wallet-transactions.tsx` — add Game column + filter
- `resources/js/pages/dashboard.tsx` — show game name in recent transactions

## Out of Scope

- Game assignment from the game show page (reverse direction) — the game show page already lists assigned tenants as read-only, which is sufficient
- Custom settings per tenant-game assignment — the pivot supports `custom_settings` JSON but there's no immediate need for a UI
- Game-specific revenue reports — `TenantRevenueRecord` already supports per-game breakdowns but that's a separate feature
