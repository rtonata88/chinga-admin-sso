# Game-Tenant Assignment & Transaction Game Tracking Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add game assignment management UI to the tenant show page, and surface game names on wallet/voucher transactions in both admin and player views.

**Architecture:** No data model changes — the `tenant_games` pivot table, `game_session_id` foreign keys, and all backend API endpoints already exist. This is primarily frontend work (tenant show page game management) plus backend join additions (transaction queries) and frontend columns/labels.

**Tech Stack:** Laravel 11, React, Inertia.js, PrimeReact, Tailwind CSS, Acumatica UI components

**Spec:** `docs/superpowers/specs/2026-03-18-game-tenant-assignment-and-transaction-tracking-design.md`

---

## File Structure

| File | Action | Responsibility |
|------|--------|----------------|
| `resources/js/pages/platform/tenants/show.tsx` | Modify | Add interactive game assignment management |
| `app/Http/Controllers/Admin/WalletTransactionController.php` | Modify | Join game_sessions + games tables, add game filter |
| `app/Http/Controllers/UserDashboardController.php` | Modify | Eager-load gameSession.game on recent transactions |
| `resources/js/pages/admin/wallet-transactions.tsx` | Modify | Add Game column + game filter dropdown |
| `resources/js/pages/dashboard.tsx` | Modify | Show game name in recent transactions |

---

### Task 1: Game Assignment UI on Tenant Show Page

**Files:**
- Modify: `resources/js/pages/platform/tenants/show.tsx`

**Context:** The tenant show page currently has a read-only "Assigned Games" section (lines 288-306) showing a plain `<ul>` list. Replace this with an interactive section using a DataTable and a "Manage Games" dialog. The backend API is fully functional:
- `GET /api/v1/platform/tenants/{uuid}/games` returns `{ assigned: [...], available: [...] }`
- `POST /api/v1/platform/tenants/{uuid}/games` syncs assignments (payload: `{ games: [{ uuid, enabled }] }`) — this is a **full replacement**, games not in the payload get detached
- `PUT /api/v1/platform/tenants/{uuid}/games/{gameUuid}` toggles enabled/settings on a single assignment

**Reference files for UI patterns:** `resources/js/pages/platform/users/index.tsx` (DataTable in fieldset, Dialog with checkboxes, Toast notifications, StatusBadge usage)

- [ ] **Step 1: Add game-related state and interfaces**

Add to the top of the component, after existing interfaces:

```tsx
interface AssignedGame {
    uuid: string;
    name: string;
    slug: string;
    type: string;
    status: string;
    pivot: { enabled: boolean; custom_settings: any };
}

interface AvailableGame {
    uuid: string;
    name: string;
    slug: string;
    type: string;
    status: string;
}
```

Add state variables inside the component after existing state:

```tsx
const toast = useRef<Toast>(null);
const [assignedGames, setAssignedGames] = useState<AssignedGame[]>([]);
const [gamesLoading, setGamesLoading] = useState(true);

// Manage games dialog
const [manageGamesOpen, setManageGamesOpen] = useState(false);
const [allGames, setAllGames] = useState<{ assigned: AssignedGame[]; available: AvailableGame[] }>({ assigned: [], available: [] });
const [selectedGameUuids, setSelectedGameUuids] = useState<string[]>([]);
const [dialogLoading, setDialogLoading] = useState(false);
const [syncing, setSyncing] = useState(false);
```

Add imports: `Toast` from `primereact/toast`, `InputSwitch` from `primereact/inputswitch`, `Checkbox` from `primereact/checkbox`, `useRef` alongside existing `useState`.

- [ ] **Step 2: Add fetch and action functions**

Add after existing `fetchVenues` function:

```tsx
const fetchAssignedGames = async () => {
    setGamesLoading(true);
    try {
        const response = await fetch(`/api/v1/platform/tenants/${uuid}/games`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const data = await response.json();
        setAssignedGames(data.assigned || []);
    } catch (error) {
        console.error('Failed to fetch games:', error);
    } finally {
        setGamesLoading(false);
    }
};

const openManageGamesDialog = async () => {
    setManageGamesOpen(true);
    setDialogLoading(true);
    try {
        const response = await fetch(`/api/v1/platform/tenants/${uuid}/games`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const data = await response.json();
        setAllGames({ assigned: data.assigned || [], available: data.available || [] });
        setSelectedGameUuids((data.assigned || []).map((g: AssignedGame) => g.uuid));
    } catch (error) {
        console.error('Failed to fetch games:', error);
        toast.current?.show({ severity: 'error', summary: 'Error', detail: 'Failed to load games.' });
    } finally {
        setDialogLoading(false);
    }
};

const handleSyncGames = async () => {
    setSyncing(true);
    try {
        const response = await fetch(`/api/v1/platform/tenants/${uuid}/games`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
            },
            body: JSON.stringify({
                games: selectedGameUuids.map((gameUuid) => {
                    const existing = allGames.assigned.find((g) => g.uuid === gameUuid);
                    return { uuid: gameUuid, enabled: existing ? existing.pivot.enabled : true };
                }),
            }),
        });
        if (response.ok) {
            setManageGamesOpen(false);
            fetchAssignedGames();
            toast.current?.show({ severity: 'success', summary: 'Success', detail: 'Game assignments updated.' });
        } else {
            const data = await response.json();
            toast.current?.show({ severity: 'error', summary: 'Error', detail: data.message || 'Failed to update games.' });
        }
    } catch (error) {
        toast.current?.show({ severity: 'error', summary: 'Error', detail: 'Failed to update games.' });
    } finally {
        setSyncing(false);
    }
};

const handleToggleEnabled = async (game: AssignedGame) => {
    const newEnabled = !game.pivot.enabled;
    try {
        const response = await fetch(`/api/v1/platform/tenants/${uuid}/games/${game.uuid}`, {
            method: 'PUT',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': getCsrfToken(),
            },
            body: JSON.stringify({ enabled: newEnabled }),
        });
        if (response.ok) {
            setAssignedGames((prev) =>
                prev.map((g) => g.uuid === game.uuid ? { ...g, pivot: { ...g.pivot, enabled: newEnabled } } : g)
            );
        } else {
            toast.current?.show({ severity: 'error', summary: 'Error', detail: 'Failed to toggle game.' });
        }
    } catch (error) {
        toast.current?.show({ severity: 'error', summary: 'Error', detail: 'Failed to toggle game.' });
    }
};
```

Add `fetchAssignedGames()` call in the existing `useEffect` alongside `fetchVenues()`.

- [ ] **Step 3: Replace the read-only Assigned Games section with interactive DataTable**

Replace the entire "Assigned Games" fieldset (lines 288-306 in the current file) with:

```tsx
{/* Assigned Games */}
<div className="acu-fieldset" style={{ '--fieldset-color': 'var(--acu-fieldset-gold)' } as React.CSSProperties}>
    <div className="acu-fieldset-header">
        <div className="acu-fieldset-title">
            <i className="pi pi-th-large" />
            <span>Assigned Games</span>
            <span className="text-xs font-normal text-[var(--acu-text-light)] ml-1">
                ({assignedGames.length})
            </span>
        </div>
        <Button
            label="Manage Games"
            icon="pi pi-cog"
            size="small"
            onClick={openManageGamesDialog}
        />
    </div>
    <div className="acu-fieldset-body p-0">
        <DataTable
            value={assignedGames}
            loading={gamesLoading}
            size="small"
            showGridlines={false}
            emptyMessage="No games assigned — click Manage Games to add some"
            dataKey="uuid"
        >
            <Column
                header="Game"
                body={(row: AssignedGame) => (
                    <div>
                        <div className="font-medium text-sm text-[var(--acu-text)]">{row.name}</div>
                        <div className="text-xs text-[var(--acu-text-light)]">{row.type}</div>
                    </div>
                )}
            />
            <Column
                header="Status"
                body={(row: AssignedGame) => (
                    <StatusBadge status={row.status === 'active' ? 'active' : 'inactive'} label={row.status} />
                )}
                style={{ width: '7rem' }}
            />
            <Column
                header="Enabled"
                body={(row: AssignedGame) => (
                    <InputSwitch
                        checked={row.pivot.enabled}
                        onChange={() => handleToggleEnabled(row)}
                    />
                )}
                style={{ width: '6rem' }}
            />
        </DataTable>
    </div>
</div>
```

- [ ] **Step 4: Add the Manage Games dialog**

Add before the closing `</UserLayout>` tag (alongside the existing Add Venue Dialog):

```tsx
{/* Manage Games Dialog */}
<Dialog
    header="Manage Game Assignments"
    visible={manageGamesOpen}
    style={{ width: '32rem' }}
    onHide={() => setManageGamesOpen(false)}
    modal
    draggable={false}
    footer={
        <div className="flex justify-end gap-2">
            <Button
                label="Cancel"
                icon="pi pi-times"
                severity="secondary"
                outlined
                onClick={() => setManageGamesOpen(false)}
            />
            <Button
                label={syncing ? 'Saving...' : 'Save Assignments'}
                icon="pi pi-check"
                onClick={handleSyncGames}
                disabled={syncing}
                loading={syncing}
            />
        </div>
    }
>
    {dialogLoading ? (
        <div className="flex justify-center py-6">
            <i className="pi pi-spin pi-spinner text-2xl" style={{ color: 'var(--acu-primary)' }} />
        </div>
    ) : (
        <div className="space-y-2">
            <p className="text-sm text-[var(--acu-text-light)] mb-3">
                Select games to make available for {tenant.name}. Unchecked games will be removed.
            </p>
            {[...allGames.assigned, ...allGames.available].map((game) => (
                <div
                    key={game.uuid}
                    className="flex items-start gap-3 p-3 rounded-lg transition-colors hover:bg-[var(--acu-surface-hover)]"
                >
                    <Checkbox
                        inputId={`game-${game.uuid}`}
                        checked={selectedGameUuids.includes(game.uuid)}
                        onChange={(e) => {
                            if (e.checked) {
                                setSelectedGameUuids([...selectedGameUuids, game.uuid]);
                            } else {
                                setSelectedGameUuids(selectedGameUuids.filter((u) => u !== game.uuid));
                            }
                        }}
                    />
                    <label htmlFor={`game-${game.uuid}`} className="cursor-pointer flex-1">
                        <div className="text-sm font-medium text-[var(--acu-text)]">{game.name}</div>
                        <div className="text-xs text-[var(--acu-text-light)]">{game.type} — {game.slug}</div>
                    </label>
                </div>
            ))}
            {allGames.assigned.length === 0 && allGames.available.length === 0 && (
                <p className="text-sm text-[var(--acu-text-light)] text-center py-4">No games available in the platform.</p>
            )}
        </div>
    )}
</Dialog>
```

- [ ] **Step 5: Add Toast ref to JSX**

Add `<Toast ref={toast} />` right after `<Head title={tenant.name} />`.

- [ ] **Step 6: Remove the old `enabled_games` from the Tenant interface**

The `enabled_games` field on the `Tenant` interface is no longer used (replaced by `assignedGames` state fetched separately). Remove it from the interface:

```tsx
// Remove this line from the Tenant interface:
enabled_games: Array<{ uuid: string; name: string; type: string }>;
```

- [ ] **Step 7: Verify and commit**

Run: `npx tsc --noEmit 2>&1 | grep -i "tenants/show"` — expected: no errors

```bash
git add resources/js/pages/platform/tenants/show.tsx
git commit -m "feat: add interactive game assignment management to tenant show page"
```

---

### Task 2: Add Game Name to Admin Wallet Transactions (Backend)

**Files:**
- Modify: `app/Http/Controllers/Admin/WalletTransactionController.php`

**Context:** The controller at lines 32-53 builds a wallet transactions query using raw DB joins. Lines 106-126 build a voucher transactions query. These are combined with UNION ALL at line 160. Both queries must have identical column counts/positions. The `game_session_id` FK exists on both tables, linking through `game_sessions.game_id` to `games.name`.

- [ ] **Step 1: Add game joins and game_name select to wallet transactions query**

In the `index()` method, after the existing `leftJoin('users as performer', ...)` (line 36), add:

```php
->leftJoin('game_sessions', 'wallet_transactions.game_session_id', '=', 'game_sessions.id')
->leftJoin('games', 'game_sessions.game_id', '=', 'games.id')
```

In the `->select([...])` array (lines 37-53), add after the `'wallet_transactions.created_at'` line:

```php
'games.name as game_name',
```

- [ ] **Step 2: Add game joins and game_name select to voucher transactions query**

In the voucher query section, after the existing `leftJoin('venue_staff', ...)` (line 109), add:

```php
->leftJoin('game_sessions', 'voucher_transactions.game_session_id', '=', 'game_sessions.id')
->leftJoin('games', 'game_sessions.game_id', '=', 'games.id')
```

In the voucher `->select([...])` array (lines 110-126), add after the `'voucher_transactions.created_at'` line:

```php
'games.name as game_name',
```

Both select lists must have `game_name` at the same position (last column) for the UNION ALL to work.

- [ ] **Step 3: Add game filter parameter**

At the top of `index()`, after `$walletUuid = $request->input('wallet');` (line 22), add:

```php
$gameFilter = $request->input('game');
```

After the date range filters for the wallet query (after line 101), add:

```php
if ($gameFilter && $walletQuery) {
    $walletQuery->where('game_sessions.game_id', $gameFilter);
}
```

After the date range filters for the voucher query (after line 155), add:

```php
if ($gameFilter && $voucherQuery) {
    $voucherQuery->where('game_sessions.game_id', $gameFilter);
}
```

Pass `$gameFilter` to `computeStats()` — update the call at line 179:

```php
$stats = $this->computeStats($source, $search, $type, $dateFrom, $dateTo, $walletUuid, $tenantId, $gameFilter);
```

- [ ] **Step 4: Update computeStats to support game filter**

Update the method signature (line 194):

```php
private function computeStats(
    string $source,
    ?string $search,
    mixed $type,
    ?string $dateFrom,
    ?string $dateTo,
    ?string $walletUuid,
    ?int $tenantId,
    ?string $gameFilter = null
): array {
```

In the wallet stats query section (after `->join('users', ...)` around line 208), add:

```php
if ($gameFilter) {
    $q->join('game_sessions', 'wallet_transactions.game_session_id', '=', 'game_sessions.id')
      ->where('game_sessions.game_id', $gameFilter);
}
```

In the voucher stats query section (after `->join('voucher_codes', ...)` around line 251), add:

```php
if ($gameFilter) {
    $q->join('game_sessions', 'voucher_transactions.game_session_id', '=', 'game_sessions.id')
      ->where('game_sessions.game_id', $gameFilter);
}
```

- [ ] **Step 5: Add distinct games list to the response**

After the stats computation (line 179), add a query to get the list of games for the filter dropdown:

```php
$games = DB::table('games')
    ->select('games.id', 'games.name')
    ->orderBy('games.name')
    ->get();
```

Add `'games' => $games` to the JSON response at line 181.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Admin/WalletTransactionController.php
git commit -m "feat: add game_name to transaction queries and game filter support"
```

---

### Task 3: Add Game Column and Filter to Admin Transactions Frontend

**Files:**
- Modify: `resources/js/pages/admin/wallet-transactions.tsx`

- [ ] **Step 1: Add game_name to Transaction interface and game state**

Add to `Transaction` interface (after `currency: string;` at line 30):

```tsx
game_name: string | null;
```

Add game filter state after existing filter states (after line 134):

```tsx
const [gameFilter, setGameFilter] = useState<number | ''>('');
const [gameOptions, setGameOptions] = useState<{ label: string; value: number }[]>([]);
```

- [ ] **Step 2: Update fetchTransactions to include game filter and populate game list**

In `fetchTransactions`, after the `walletUuid` param line (line 152), add:

```tsx
if (gameFilter !== '') params.append('game', gameFilter.toString());
```

After `if (data.stats) setStats(data.stats);` (line 162), add:

```tsx
if (data.games) {
    setGameOptions([
        { label: 'All Games', value: '' as any },
        ...data.games.map((g: { id: number; name: string }) => ({ label: g.name, value: g.id })),
    ]);
}
```

Add `gameFilter` to the `useEffect` dependency array at line 174.

- [ ] **Step 3: Add Game filter dropdown to the filters section**

After the Source `<Dropdown>` (after line 345), add:

```tsx
<Dropdown
    value={gameFilter}
    onChange={(e) => {
        setGameFilter(e.value);
        setPage(1);
    }}
    options={gameOptions}
    placeholder="Game"
    className="w-48"
/>
```

Add `setGameFilter('');` to the `handleClearFilters` function (after `setSourceFilter('all');` at line 184).

- [ ] **Step 4: Add Game column to the DataTable**

Add a game column template after `typeTemplate` (line 216):

```tsx
const gameTemplate = (row: Transaction) => (
    <span className="text-sm text-[var(--acu-text)]">{row.game_name || '—'}</span>
);
```

In the DataTable JSX, add a new `<Column>` after the Type column (after line 394):

```tsx
<Column header="Game" body={gameTemplate} style={{ width: '9rem' }} />
```

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/admin/wallet-transactions.tsx
git commit -m "feat: add game column and game filter to admin transactions page"
```

---

### Task 4: Add Game Name to Player Dashboard Transactions (Backend)

**Files:**
- Modify: `app/Http/Controllers/UserDashboardController.php`

**Context:** The controller currently fetches recent wallet transactions at lines 47-56 with `$wallet->transactions()->orderBy(...)->limit(5)->get()`. The `WalletTransaction` model has a `gameSession()` BelongsTo relationship, and `GameSession` has a `game()` BelongsTo relationship.

- [ ] **Step 1: Eager-load gameSession.game and include game_name**

In the `$recentTransactions` query (around line 47), change from:

```php
$recentTransactions = $wallet->transactions()
    ->orderBy('created_at', 'desc')
    ->limit(5)
    ->get()
    ->map(fn ($t) => [
        'type' => $t->type,
        'amount' => (float) $t->amount,
        'balance_after' => (float) $t->balance_after,
        'description' => $t->description,
        'created_at' => $t->created_at->toIso8601String(),
    ]);
```

To:

```php
$recentTransactions = $wallet->transactions()
    ->with('gameSession.game')
    ->orderBy('created_at', 'desc')
    ->limit(5)
    ->get()
    ->map(fn ($t) => [
        'type' => $t->type,
        'amount' => (float) $t->amount,
        'balance_after' => (float) $t->balance_after,
        'description' => $t->description,
        'game_name' => $t->gameSession?->game?->name,
        'created_at' => $t->created_at->toIso8601String(),
    ]);
```

- [ ] **Step 2: Commit**

```bash
git add app/Http/Controllers/UserDashboardController.php
git commit -m "feat: include game_name in player dashboard recent transactions"
```

---

### Task 5: Show Game Name in Player Dashboard Frontend

**Files:**
- Modify: `resources/js/pages/dashboard.tsx`

- [ ] **Step 1: Add game_name to WalletTransaction interface**

In the `WalletTransaction` interface (around line 28), add after `description`:

```tsx
game_name: string | null;
```

- [ ] **Step 2: Show game name in the recent transactions list**

In the transaction rendering (inside the `.map()` for `wallet.recent_transactions`), update the type display div. Change:

```tsx
<div className="text-sm font-medium text-[var(--acu-text)] capitalize">
    {tx.type}
</div>
<div className="text-xs text-[var(--acu-text-light)]">
    {tx.description || formatRelativeTime(tx.created_at)}
</div>
```

To:

```tsx
<div className="text-sm font-medium text-[var(--acu-text)] capitalize">
    {tx.type}
    {tx.game_name && (
        <span className="font-normal text-xs text-[var(--acu-text-light)] ml-1.5">
            {tx.game_name}
        </span>
    )}
</div>
<div className="text-xs text-[var(--acu-text-light)]">
    {tx.description || formatRelativeTime(tx.created_at)}
</div>
```

This shows the game name inline after the type label (e.g., "Bet Lucky Slots"), keeping the description/time on the second line.

- [ ] **Step 3: Verify and commit**

Run: `npx tsc --noEmit 2>&1 | grep -i "dashboard"` — expected: no errors

```bash
git add resources/js/pages/dashboard.tsx
git commit -m "feat: show game name in player dashboard recent transactions"
```
