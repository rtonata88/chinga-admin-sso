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
- DataTable columns: Game Name (with type subtitle), Status (StatusBadge), Enabled (toggle via `PUT /api/v1/platform/tenants/{tenantUuid}/games/{gameUuid}`), Remove button
- "Manage Games" button in fieldset header opens a Dialog
- Dialog fetches `GET /api/v1/platform/tenants/{tenantUuid}/games` which returns `{ assigned, available }` arrays
- **Dialog shows ALL games** — assigned games appear pre-checked, available games unchecked. This is critical because the sync endpoint replaces ALL assignments (games not in the payload get detached).
- On save, send the full list of checked games to `POST /api/v1/platform/tenants/{tenantUuid}/games`
- Request body format: `{ "games": [{ "uuid": "<game-uuid>", "enabled": true }, ...] }`
- After a successful sync, refetch the tenant's game list from the index endpoint to update local state
- Use pessimistic updates for the enabled toggle — wait for the `PUT` response before updating the switch state
- Toast notifications for success/error

**Backend changes:** None. `TenantGameController` already supports `index`, `sync`, and `update`.

**Existing API endpoints used:**
- `GET /api/v1/platform/tenants/{tenantUuid}/games` — list assigned + available
- `POST /api/v1/platform/tenants/{tenantUuid}/games` — bulk sync assignments (full replacement)
- `PUT /api/v1/platform/tenants/{tenantUuid}/games/{gameUuid}` — toggle enabled/update settings

### Feature 2: Game Name on Transactions

**Admin Wallet Transactions page** (`/admin/wallet-transactions`)

Backend (`WalletTransactionController`):
- Wallet transactions query: `leftJoin('game_sessions', 'wallet_transactions.game_session_id', '=', 'game_sessions.id')` then `leftJoin('games', 'game_sessions.game_id', '=', 'games.id')` to include `games.name as game_name`
- Voucher transactions query: same join pattern via `voucher_transactions.game_session_id` → `game_sessions.game_id` → `games.name`
- Add `game_name` to both select lists at the same position (required for UNION ALL column alignment)
- The `computeStats()` method also needs the same joins added if game filtering is applied
- Add new `game` query parameter to filter by game_id: `WHERE game_sessions.game_id = ?`

Frontend:
- Add `game_name?: string | null` to the TypeScript `Transaction` interface
- Add "Game" column to the DataTable after the "Type" column, displaying game name or "—"
- Add a "Game" filter dropdown populated from a distinct list of game names in the response

**Player Dashboard** (`/dashboard`)

Backend (`UserDashboardController`):
- When fetching recent transactions, eager-load `gameSession.game` relationship
- Include `game_name` field in the transaction map: `$t->gameSession?->game?->name`

Frontend:
- Add `game_name?: string | null` to the TypeScript `WalletTransaction` interface
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
- `app/Http/Controllers/Admin/WalletTransactionController.php` — join game_sessions + games tables in both main queries and `computeStats()`, add `game` filter parameter
- `app/Http/Controllers/UserDashboardController.php` — eager-load gameSession.game
- `resources/js/pages/admin/wallet-transactions.tsx` — add `game_name` to TS interface, add Game column + filter
- `resources/js/pages/dashboard.tsx` — add `game_name` to TS interface, show game name in recent transactions

## Out of Scope

- Game assignment from the game show page (reverse direction) — the game show page already lists assigned tenants as read-only, which is sufficient
- Custom settings per tenant-game assignment — the pivot supports `custom_settings` JSON but there's no immediate need for a UI
- Game-specific revenue reports — `TenantRevenueRecord` already supports per-game breakdowns but that's a separate feature
