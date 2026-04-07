# Wallet Transactions Admin Page — Design Spec

## Overview

A global admin page to view all player transactions (wallet + voucher) with filtering, search, and pagination. Plus a "View Transactions" link on the existing Wallets page that navigates to this page pre-filtered for a specific wallet.

## Pages

### 1. Global Wallet Transactions Page (`/admin/wallet-transactions`)

**Sidebar:** New "Wallet Transactions" item.

**Stats bar at top (filter-aware — updates with active filters):**
- Total transactions count
- Total deposits (sum)
- Total withdrawals (sum)
- Total bets/losses (sum)
- Total wins (sum)

**DataTable columns:**
| Column | Source |
|--------|--------|
| Date/Time | `created_at` |
| Player/Source | Wallet txns: user name + email. Voucher txns: masked voucher code + venue name |
| Type | Badge — see Type Mapping below |
| Amount | Green for credits, red for debits |
| Balance Before → After | `balance_before` / `balance_after` |
| Reference | `reference` field |
| Performed By | `performed_by` (wallet) or `performed_by_staff_id` (voucher) |
| Source | "Wallet" or "Voucher" badge |

**Type mapping (unified display):**
- Wallet: `deposit`, `withdrawal`, `bet`, `win`, `adjustment`
- Voucher: `load`, `loss`, `cashout`, `adjustment`, `transfer_in`, `transfer_out`, `win`
- All types shown as-is with color-coded badges (green for credits, red for debits)

**Filters:**
- Search: player name/email (wallet txns only) or voucher code
- Type: multi-select dropdown (all types from both systems)
- Date range: start/end date pickers
- Source: wallet / voucher / all (default: all)

**Pagination:** 25 per page, server-side.

**Sort:** By date descending (default).

### 2. Wallets Page Change

Add a "View Transactions" link on each wallet row that navigates to `/admin/wallet-transactions?wallet={uuid}`.

## Backend

### New Controller: `Admin/WalletTransactionController`

**Endpoint:** `GET /api/v1/admin/wallet-transactions`

**Query params:**
- `search` — filter by player name/email or voucher code
- `type` — filter by transaction type(s)
- `source` — `wallet`, `voucher`, or `all` (default: `all`)
- `date_from`, `date_to` — date range
- `wallet` — wallet UUID (for pre-filtered view from Wallets page)
- `per_page` — pagination size (default 25)
- `page` — page number

**Logic:**
1. Build wallet_transactions query with joins to `wallets` → `users`, apply filters BEFORE union
2. Build voucher_transactions query with joins to `voucher_codes` → `venues`, apply filters BEFORE union
3. If `source` is `wallet` or `voucher`, only run that query; otherwise UNION both
4. If `wallet` param provided, only query wallet_transactions for that wallet
5. Sort by `created_at` DESC
6. Paginate
7. Compute stats from the same filtered queries (separate count/sum queries for performance)

**Key schema differences to handle:**
- Voucher transactions use `session_id` → `voucher_sessions`, NOT `game_session_id` → `game_sessions`
- Voucher transactions have `loss` type; wallet transactions have `bet` type (semantically similar)
- Voucher transactions have no user — display voucher code + venue instead

**Response shape (matches existing API pattern):**
```json
{
  "success": true,
  "data": [
    {
      "uuid": "...",
      "source_type": "wallet",
      "player_name": "James Katjimune",
      "player_email": "james@example.com",
      "voucher_code": null,
      "venue_name": null,
      "type": "deposit",
      "amount": "100.00",
      "balance_before": "0.00",
      "balance_after": "100.00",
      "reference": "DEP-001",
      "performed_by_name": "Admin User",
      "created_at": "2026-03-18T10:00:00Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 50,
    "per_page": 25,
    "total": 1234
  },
  "stats": {
    "total_transactions": 1234,
    "total_deposits": "50000.00",
    "total_withdrawals": "12000.00",
    "total_bets": "30000.00",
    "total_wins": "25000.00"
  }
}
```

### Routes

- Web: `GET /admin/wallet-transactions` → `DashboardController@walletTransactions` (renders Inertia page)
- API: `GET /api/v1/admin/wallet-transactions` → `WalletTransactionController@index`

### Middleware

Same as other admin routes: `auth`, `EnsureTenantAdmin`.

## Frontend

### New Page: `resources/js/Pages/Admin/wallet-transactions.tsx`

Follow existing patterns from `wallets.tsx`:
- Acumatica UI layout with PrimeReact DataTable
- Stats cards at top
- Filter bar with search input, dropdowns, date pickers
- Paginated DataTable
- Badge-colored transaction types

### Sidebar Update

Add "Wallet Transactions" item to the admin sidebar navigation.

## Multi-tenancy

Both queries are tenant-scoped:
- `wallet_transactions` via `wallets.tenant_id`
- `voucher_transactions` via `voucher_codes.tenant_id`

Platform admins (null tenant) see all transactions.
