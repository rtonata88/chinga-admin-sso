# Chinga Fantasy Integration — Plan C: SSO Admin Pages

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build game-specific admin pages in the SSO for managing Chinga Fantasy — team management, per-tenant game settings, and a round monitor.

**Architecture:** Static game config (teams, settings) lives in SSO MySQL and is managed via SSO's React/Inertia admin. The Fantasy game server fetches config from new SSO API endpoints. Runtime game state (rounds, bets) stays in Fantasy's PostgreSQL and is displayed via API calls from the SSO admin.

**Tech Stack:** Laravel, Inertia.js, React, PrimeReact, Acumatica UI, Tailwind CSS

**Spec:** `docs/superpowers/specs/2026-04-05-chinga-fantasy-integration-design.md`

**Depends on:** Plan A (Foundation) — SSO auth working. Can be developed in parallel with Plan B.

**Project:** `/Users/richard/Projects/chinga-games-sso`

---

## File Structure

### SSO (Laravel) — New/Modified Files
| Action | File | Purpose |
|--------|------|---------|
| Create | `database/migrations/xxxx_create_fantasy_teams_table.php` | Teams table |
| Create | `app/Models/FantasyTeam.php` | Team model |
| Create | `app/Http/Controllers/Admin/Games/FantasyTeamController.php` | Team CRUD |
| Create | `app/Http/Controllers/Admin/Games/FantasySettingsController.php` | Per-tenant game settings |
| Create | `app/Http/Controllers/Admin/Games/FantasyRoundController.php` | Round monitor (reads from Fantasy API) |
| Create | `app/Http/Controllers/Api/GameConfigController.php` | Internal API for game server to fetch config |
| Create | `resources/js/pages/admin/games/fantasy/teams.tsx` | Team management page |
| Create | `resources/js/pages/admin/games/fantasy/settings.tsx` | Game settings page |
| Create | `resources/js/pages/admin/games/fantasy/rounds.tsx` | Round monitor page |
| Modify | `routes/admin.php` (or equivalent) | Add admin routes |
| Modify | `routes/api.php` | Add internal config API routes |

---

## Task 1: Create fantasy_teams Table and Model

**Files:**
- Create: `database/migrations/2026_04_05_000001_create_fantasy_teams_table.php`
- Create: `app/Models/FantasyTeam.php`

- [ ] **Step 1: Create migration**

```bash
cd /Users/richard/Projects/chinga-games-sso
php artisan make:migration create_fantasy_teams_table
```

Migration contents:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fantasy_teams', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->string('short_name', 10)->nullable();
            $table->string('logo_url')->nullable();
            $table->string('country')->nullable();
            $table->string('league')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fantasy_teams');
    }
};
```

- [ ] **Step 2: Create model**

```php
<?php
// app/Models/FantasyTeam.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class FantasyTeam extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'name',
        'short_name',
        'logo_url',
        'country',
        'league',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (FantasyTeam $team) {
            if (empty($team->uuid)) {
                $team->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
```

- [ ] **Step 3: Run migration**

```bash
php artisan migrate
```

Expected: `fantasy_teams` table created.

- [ ] **Step 4: Commit**

```bash
git add database/migrations/*create_fantasy_teams* app/Models/FantasyTeam.php
git commit -m "feat: add fantasy_teams table and model"
```

---

## Task 2: Team Management Controller

**Files:**
- Create: `app/Http/Controllers/Admin/Games/FantasyTeamController.php`

- [ ] **Step 1: Create controller**

```php
<?php
// app/Http/Controllers/Admin/Games/FantasyTeamController.php

namespace App\Http\Controllers\Admin\Games;

use App\Http\Controllers\Controller;
use App\Models\FantasyTeam;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FantasyTeamController extends Controller
{
    public function index(Request $request)
    {
        $query = FantasyTeam::query();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('country', 'like', "%{$search}%")
                  ->orWhere('league', 'like', "%{$search}%");
            });
        }

        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        $teams = $query->orderBy('name')->paginate(25);

        return Inertia::render('admin/games/fantasy/teams', [
            'teams' => $teams,
            'filters' => $request->only(['search', 'active']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:10'],
            'logo_url' => ['nullable', 'url', 'max:500'],
            'country' => ['nullable', 'string', 'max:100'],
            'league' => ['nullable', 'string', 'max:100'],
            'is_active' => ['boolean'],
        ]);

        FantasyTeam::create($validated);

        return redirect()->back()->with('success', 'Team created.');
    }

    public function update(Request $request, FantasyTeam $team)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:10'],
            'logo_url' => ['nullable', 'url', 'max:500'],
            'country' => ['nullable', 'string', 'max:100'],
            'league' => ['nullable', 'string', 'max:100'],
            'is_active' => ['boolean'],
        ]);

        $team->update($validated);

        return redirect()->back()->with('success', 'Team updated.');
    }

    public function destroy(FantasyTeam $team)
    {
        $team->delete();

        return redirect()->back()->with('success', 'Team deleted.');
    }

    public function bulkToggle(Request $request)
    {
        $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'exists:fantasy_teams,id'],
            'is_active' => ['required', 'boolean'],
        ]);

        FantasyTeam::whereIn('id', $request->input('ids'))
            ->update(['is_active' => $request->boolean('is_active')]);

        return redirect()->back()->with('success', 'Teams updated.');
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Http/Controllers/Admin/Games/FantasyTeamController.php
git commit -m "feat: add Fantasy team management controller"
```

---

## Task 3: Game Settings Controller

**Files:**
- Create: `app/Http/Controllers/Admin/Games/FantasySettingsController.php`

- [ ] **Step 1: Create controller**

Game settings are stored in the `games.settings` JSON column (global defaults) and `tenant_games.custom_settings` JSON column (per-tenant overrides).

```php
<?php
// app/Http/Controllers/Admin/Games/FantasySettingsController.php

namespace App\Http\Controllers\Admin\Games;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Inertia\Inertia;

class FantasySettingsController extends Controller
{
    private function getFantasyGame(): Game
    {
        return Game::where('slug', 'chinga-fantasy')->firstOrFail();
    }

    public function index(Request $request)
    {
        $game = $this->getFantasyGame();

        // Get all tenants with their Fantasy-specific settings
        $tenants = $game->tenants()
            ->select('tenants.id', 'tenants.uuid', 'tenants.name', 'tenants.slug')
            ->get()
            ->map(fn ($tenant) => [
                'uuid' => $tenant->uuid,
                'name' => $tenant->name,
                'slug' => $tenant->slug,
                'enabled' => $tenant->pivot->enabled,
                'custom_settings' => $tenant->pivot->custom_settings ?? [],
            ]);

        return Inertia::render('admin/games/fantasy/settings', [
            'game' => [
                'uuid' => $game->uuid,
                'name' => $game->name,
                'settings' => $game->settings ?? [],
            ],
            'tenants' => $tenants,
        ]);
    }

    public function updateGlobalSettings(Request $request)
    {
        $game = $this->getFantasyGame();

        $validated = $request->validate([
            'min_bet_amount' => ['required', 'numeric', 'min:1'],
            'max_bet_amount' => ['required', 'numeric', 'min:1'],
            'display_teams' => ['required', 'integer', 'min:4', 'max:50'],
            'round_betting_seconds' => ['required', 'integer', 'min:10', 'max:300'],
            'round_results_seconds' => ['required', 'integer', 'min:5', 'max:120'],
            'round_dialog_seconds' => ['required', 'integer', 'min:5', 'max:120'],
            'min_jackpot_amount' => ['required', 'numeric', 'min:0'],
            'jackpot_percentage' => ['required', 'integer', 'min:0', 'max:100'],
        ]);

        $game->update(['settings' => $validated]);

        return redirect()->back()->with('success', 'Global settings updated.');
    }

    public function updateTenantSettings(Request $request, string $tenantUuid)
    {
        $game = $this->getFantasyGame();
        $tenant = Tenant::where('uuid', $tenantUuid)->firstOrFail();

        $validated = $request->validate([
            'enabled' => ['boolean'],
            'custom_settings' => ['nullable', 'array'],
            'custom_settings.min_bet_amount' => ['nullable', 'numeric', 'min:1'],
            'custom_settings.max_bet_amount' => ['nullable', 'numeric', 'min:1'],
            'custom_settings.display_teams' => ['nullable', 'integer', 'min:4', 'max:50'],
            'custom_settings.round_betting_seconds' => ['nullable', 'integer', 'min:10'],
            'custom_settings.round_results_seconds' => ['nullable', 'integer', 'min:5'],
            'custom_settings.round_dialog_seconds' => ['nullable', 'integer', 'min:5'],
            'custom_settings.min_jackpot_amount' => ['nullable', 'numeric', 'min:0'],
            'custom_settings.jackpot_percentage' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $game->tenants()->updateExistingPivot($tenant->id, [
            'enabled' => $validated['enabled'] ?? true,
            'custom_settings' => json_encode($validated['custom_settings'] ?? []),
        ]);

        return redirect()->back()->with('success', "Settings updated for {$tenant->name}.");
    }
}
```

- [ ] **Step 2: Commit**

```bash
git add app/Http/Controllers/Admin/Games/FantasySettingsController.php
git commit -m "feat: add Fantasy game settings controller with per-tenant overrides"
```

---

## Task 4: Internal Config API for Game Server

The Fantasy game server needs to fetch team roster and settings from the SSO.

**Files:**
- Create: `app/Http/Controllers/Api/GameConfigController.php`
- Modify: `routes/api.php`

- [ ] **Step 1: Create config API controller**

```php
<?php
// app/Http/Controllers/Api/GameConfigController.php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FantasyTeam;
use App\Models\Game;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GameConfigController extends Controller
{
    /**
     * Get active teams for a game.
     * Used by game server to fetch team roster.
     */
    public function teams(Request $request, string $gameUuid): JsonResponse
    {
        $game = Game::where('uuid', $gameUuid)->first();
        if (!$game || $game->slug !== 'chinga-fantasy') {
            return response()->json(['message' => 'Game not found.'], 404);
        }

        $teams = FantasyTeam::active()
            ->select('uuid', 'name', 'short_name', 'logo_url', 'country', 'league')
            ->orderBy('name')
            ->get();

        return response()->json(['teams' => $teams]);
    }

    /**
     * Get game configuration with tenant-specific overrides.
     * Used by game server to fetch settings.
     */
    public function config(Request $request, string $gameUuid): JsonResponse
    {
        $game = Game::where('uuid', $gameUuid)->first();
        if (!$game) {
            return response()->json(['message' => 'Game not found.'], 404);
        }

        $globalSettings = $game->settings ?? [];

        // If tenant UUID provided, merge custom settings
        $tenantUuid = $request->input('tenant_uuid');
        $tenantSettings = [];

        if ($tenantUuid) {
            $tenant = Tenant::where('uuid', $tenantUuid)->first();
            if ($tenant) {
                $pivot = $game->tenants()
                    ->where('tenants.id', $tenant->id)
                    ->first();

                if ($pivot) {
                    $tenantSettings = $pivot->pivot->custom_settings ?? [];
                }
            }
        }

        // Tenant settings override global settings
        $mergedSettings = array_merge($globalSettings, array_filter($tenantSettings));

        return response()->json([
            'game_uuid' => $game->uuid,
            'settings' => $mergedSettings,
        ]);
    }
}
```

- [ ] **Step 2: Add routes to api.php**

In `routes/api.php`, add inside the `v1` prefix group, after the existing `auth:api` middleware group:

```php
// Internal API for game servers (client credentials auth)
Route::middleware('auth:api')->group(function () {
    Route::get('games/{gameUuid}/teams', [\App\Http\Controllers\Api\GameConfigController::class, 'teams'])
        ->name('api.games.teams');
    Route::get('games/{gameUuid}/config', [\App\Http\Controllers\Api\GameConfigController::class, 'config'])
        ->name('api.games.config');
});
```

- [ ] **Step 3: Commit**

```bash
git add app/Http/Controllers/Api/GameConfigController.php routes/api.php
git commit -m "feat: add internal config API for game server team/settings fetch"
```

---

## Task 5: Admin Routes

**Files:**
- Modify: Routes file for admin (check existing pattern — likely `routes/admin.php` or part of `routes/web.php`)

- [ ] **Step 1: Find existing admin route pattern**

Check how existing admin routes are defined:
```bash
grep -r "admin/" routes/ --include="*.php" | head -20
```

- [ ] **Step 2: Add Fantasy admin routes**

Following the existing pattern, add:

```php
use App\Http\Controllers\Admin\Games\FantasyTeamController;
use App\Http\Controllers\Admin\Games\FantasySettingsController;

// Fantasy Game Management
Route::prefix('admin/games/fantasy')->middleware(['auth', 'verified'])->group(function () {
    // Teams
    Route::get('/teams', [FantasyTeamController::class, 'index'])->name('admin.games.fantasy.teams');
    Route::post('/teams', [FantasyTeamController::class, 'store'])->name('admin.games.fantasy.teams.store');
    Route::put('/teams/{team}', [FantasyTeamController::class, 'update'])->name('admin.games.fantasy.teams.update');
    Route::delete('/teams/{team}', [FantasyTeamController::class, 'destroy'])->name('admin.games.fantasy.teams.destroy');
    Route::post('/teams/bulk-toggle', [FantasyTeamController::class, 'bulkToggle'])->name('admin.games.fantasy.teams.bulk-toggle');

    // Settings
    Route::get('/settings', [FantasySettingsController::class, 'index'])->name('admin.games.fantasy.settings');
    Route::put('/settings/global', [FantasySettingsController::class, 'updateGlobalSettings'])->name('admin.games.fantasy.settings.global');
    Route::put('/settings/tenant/{tenantUuid}', [FantasySettingsController::class, 'updateTenantSettings'])->name('admin.games.fantasy.settings.tenant');
});
```

- [ ] **Step 3: Commit**

```bash
git add routes/
git commit -m "feat: add admin routes for Fantasy team and settings management"
```

---

## Task 6: Team Management Admin Page

**Files:**
- Create: `resources/js/pages/admin/games/fantasy/teams.tsx`

- [ ] **Step 1: Create the teams page**

Follow the existing Acumatica UI patterns used in other admin pages (check `resources/js/pages/admin/users.tsx` for reference). The page should include:

```tsx
// resources/js/pages/admin/games/fantasy/teams.tsx
import AppLayout from '@/components/acumatica/Layout/AppLayout';
import PageHeader from '@/components/acumatica/Common/PageHeader';
import { DataTable } from 'primereact/datatable';
import { Column } from 'primereact/column';
import { Button } from 'primereact/button';
import { InputText } from 'primereact/inputtext';
import { Dialog } from 'primereact/dialog';
import { Tag } from 'primereact/tag';
import { useState } from 'react';
import { router, usePage } from '@inertiajs/react';

interface Team {
    id: number;
    uuid: string;
    name: string;
    short_name: string | null;
    logo_url: string | null;
    country: string | null;
    league: string | null;
    is_active: boolean;
}

interface Props {
    teams: {
        data: Team[];
        current_page: number;
        last_page: number;
        total: number;
    };
    filters: {
        search?: string;
        active?: string;
    };
}

export default function FantasyTeams({ teams, filters }: Props) {
    const [showDialog, setShowDialog] = useState(false);
    const [editingTeam, setEditingTeam] = useState<Partial<Team> | null>(null);
    const [form, setForm] = useState({
        name: '',
        short_name: '',
        logo_url: '',
        country: '',
        league: '',
        is_active: true,
    });

    function openCreate() {
        setEditingTeam(null);
        setForm({ name: '', short_name: '', logo_url: '', country: '', league: '', is_active: true });
        setShowDialog(true);
    }

    function openEdit(team: Team) {
        setEditingTeam(team);
        setForm({
            name: team.name,
            short_name: team.short_name || '',
            logo_url: team.logo_url || '',
            country: team.country || '',
            league: team.league || '',
            is_active: team.is_active,
        });
        setShowDialog(true);
    }

    function handleSubmit() {
        if (editingTeam) {
            router.put(`/admin/games/fantasy/teams/${editingTeam.uuid}`, form, {
                onSuccess: () => setShowDialog(false),
            });
        } else {
            router.post('/admin/games/fantasy/teams', form, {
                onSuccess: () => setShowDialog(false),
            });
        }
    }

    function handleDelete(team: Team) {
        if (confirm(`Delete team "${team.name}"?`)) {
            router.delete(`/admin/games/fantasy/teams/${team.uuid}`);
        }
    }

    function handleSearch(value: string) {
        router.get('/admin/games/fantasy/teams', { search: value || undefined }, {
            preserveState: true,
            replace: true,
        });
    }

    const statusTemplate = (team: Team) => (
        <Tag value={team.is_active ? 'Active' : 'Inactive'}
             severity={team.is_active ? 'success' : 'danger'} />
    );

    const actionsTemplate = (team: Team) => (
        <div className="flex gap-2">
            <Button icon="pi pi-pencil" size="small" text onClick={() => openEdit(team)} />
            <Button icon="pi pi-trash" size="small" text severity="danger" onClick={() => handleDelete(team)} />
        </div>
    );

    return (
        <AppLayout>
            <PageHeader
                title="Fantasy Teams"
                subtitle={`${teams.total} teams`}
                actions={
                    <Button label="Add Team" icon="pi pi-plus" onClick={openCreate} />
                }
            />

            <div className="p-4">
                <div className="mb-4">
                    <InputText
                        placeholder="Search teams..."
                        defaultValue={filters.search}
                        onChange={(e) => handleSearch(e.target.value)}
                        className="w-full md:w-80"
                    />
                </div>

                <DataTable value={teams.data} paginator={false} stripedRows>
                    <Column field="name" header="Name" sortable />
                    <Column field="short_name" header="Short" />
                    <Column field="country" header="Country" sortable />
                    <Column field="league" header="League" sortable />
                    <Column header="Status" body={statusTemplate} />
                    <Column header="Actions" body={actionsTemplate} style={{ width: '120px' }} />
                </DataTable>
            </div>

            <Dialog
                header={editingTeam ? 'Edit Team' : 'Add Team'}
                visible={showDialog}
                onHide={() => setShowDialog(false)}
                style={{ width: '500px' }}
                footer={
                    <div className="flex justify-end gap-2">
                        <Button label="Cancel" text onClick={() => setShowDialog(false)} />
                        <Button label="Save" onClick={handleSubmit} />
                    </div>
                }
            >
                <div className="flex flex-col gap-4 pt-2">
                    <div>
                        <label className="block text-sm font-medium mb-1">Name *</label>
                        <InputText
                            value={form.name}
                            onChange={(e) => setForm({ ...form, name: e.target.value })}
                            className="w-full"
                        />
                    </div>
                    <div>
                        <label className="block text-sm font-medium mb-1">Short Name</label>
                        <InputText
                            value={form.short_name}
                            onChange={(e) => setForm({ ...form, short_name: e.target.value })}
                            className="w-full"
                            maxLength={10}
                        />
                    </div>
                    <div>
                        <label className="block text-sm font-medium mb-1">Logo URL</label>
                        <InputText
                            value={form.logo_url}
                            onChange={(e) => setForm({ ...form, logo_url: e.target.value })}
                            className="w-full"
                        />
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium mb-1">Country</label>
                            <InputText
                                value={form.country}
                                onChange={(e) => setForm({ ...form, country: e.target.value })}
                                className="w-full"
                            />
                        </div>
                        <div>
                            <label className="block text-sm font-medium mb-1">League</label>
                            <InputText
                                value={form.league}
                                onChange={(e) => setForm({ ...form, league: e.target.value })}
                                className="w-full"
                            />
                        </div>
                    </div>
                </div>
            </Dialog>
        </AppLayout>
    );
}
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/pages/admin/games/fantasy/teams.tsx
git commit -m "feat: add Fantasy team management admin page"
```

---

## Task 7: Game Settings Admin Page

**Files:**
- Create: `resources/js/pages/admin/games/fantasy/settings.tsx`

- [ ] **Step 1: Create the settings page**

```tsx
// resources/js/pages/admin/games/fantasy/settings.tsx
import AppLayout from '@/components/acumatica/Layout/AppLayout';
import PageHeader from '@/components/acumatica/Common/PageHeader';
import { InputNumber } from 'primereact/inputnumber';
import { Button } from 'primereact/button';
import { Card } from 'primereact/card';
import { InputSwitch } from 'primereact/inputswitch';
import { Accordion, AccordionTab } from 'primereact/accordion';
import { useState } from 'react';
import { router } from '@inertiajs/react';

interface GameSettings {
    min_bet_amount: number;
    max_bet_amount: number;
    display_teams: number;
    round_betting_seconds: number;
    round_results_seconds: number;
    round_dialog_seconds: number;
    min_jackpot_amount: number;
    jackpot_percentage: number;
}

interface TenantConfig {
    uuid: string;
    name: string;
    slug: string;
    enabled: boolean;
    custom_settings: Partial<GameSettings>;
}

interface Props {
    game: {
        uuid: string;
        name: string;
        settings: GameSettings;
    };
    tenants: TenantConfig[];
}

function SettingsForm({
    settings,
    onSave,
    label,
}: {
    settings: GameSettings;
    onSave: (s: GameSettings) => void;
    label: string;
}) {
    const [form, setForm] = useState<GameSettings>(settings);

    return (
        <div className="flex flex-col gap-4">
            <div className="grid grid-cols-2 gap-4">
                <div>
                    <label className="block text-sm font-medium mb-1">Min Bet Amount</label>
                    <InputNumber value={form.min_bet_amount} onValueChange={(e) => setForm({ ...form, min_bet_amount: e.value ?? 1 })} min={1} mode="currency" currency="NAD" />
                </div>
                <div>
                    <label className="block text-sm font-medium mb-1">Max Bet Amount</label>
                    <InputNumber value={form.max_bet_amount} onValueChange={(e) => setForm({ ...form, max_bet_amount: e.value ?? 1 })} min={1} mode="currency" currency="NAD" />
                </div>
            </div>
            <div>
                <label className="block text-sm font-medium mb-1">Display Teams (per round)</label>
                <InputNumber value={form.display_teams} onValueChange={(e) => setForm({ ...form, display_teams: e.value ?? 4 })} min={4} max={50} />
            </div>
            <div className="grid grid-cols-3 gap-4">
                <div>
                    <label className="block text-sm font-medium mb-1">Betting Phase (sec)</label>
                    <InputNumber value={form.round_betting_seconds} onValueChange={(e) => setForm({ ...form, round_betting_seconds: e.value ?? 30 })} min={10} max={300} />
                </div>
                <div>
                    <label className="block text-sm font-medium mb-1">Results Phase (sec)</label>
                    <InputNumber value={form.round_results_seconds} onValueChange={(e) => setForm({ ...form, round_results_seconds: e.value ?? 30 })} min={5} max={120} />
                </div>
                <div>
                    <label className="block text-sm font-medium mb-1">Dialog Phase (sec)</label>
                    <InputNumber value={form.round_dialog_seconds} onValueChange={(e) => setForm({ ...form, round_dialog_seconds: e.value ?? 30 })} min={5} max={120} />
                </div>
            </div>
            <div className="grid grid-cols-2 gap-4">
                <div>
                    <label className="block text-sm font-medium mb-1">Min Jackpot Amount</label>
                    <InputNumber value={form.min_jackpot_amount} onValueChange={(e) => setForm({ ...form, min_jackpot_amount: e.value ?? 0 })} min={0} mode="currency" currency="NAD" />
                </div>
                <div>
                    <label className="block text-sm font-medium mb-1">Jackpot % (of each bet)</label>
                    <InputNumber value={form.jackpot_percentage} onValueChange={(e) => setForm({ ...form, jackpot_percentage: e.value ?? 0 })} min={0} max={100} suffix="%" />
                </div>
            </div>
            <div className="flex justify-end">
                <Button label={`Save ${label}`} onClick={() => onSave(form)} />
            </div>
        </div>
    );
}

export default function FantasySettings({ game, tenants }: Props) {
    function saveGlobal(settings: GameSettings) {
        router.put('/admin/games/fantasy/settings/global', settings);
    }

    function saveTenant(tenantUuid: string, enabled: boolean, customSettings: Partial<GameSettings>) {
        router.put(`/admin/games/fantasy/settings/tenant/${tenantUuid}`, {
            enabled,
            custom_settings: customSettings,
        });
    }

    return (
        <AppLayout>
            <PageHeader
                title="Fantasy Settings"
                subtitle="Configure game parameters globally and per tenant"
            />

            <div className="p-4 space-y-6">
                <Card title="Global Defaults">
                    <SettingsForm
                        settings={game.settings}
                        onSave={saveGlobal}
                        label="Global Settings"
                    />
                </Card>

                {tenants.length > 0 && (
                    <Card title="Tenant Overrides">
                        <Accordion>
                            {tenants.map((tenant) => (
                                <AccordionTab
                                    key={tenant.uuid}
                                    header={
                                        <div className="flex items-center gap-2">
                                            <span>{tenant.name}</span>
                                            <span className={`text-xs px-2 py-0.5 rounded ${tenant.enabled ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700'}`}>
                                                {tenant.enabled ? 'Enabled' : 'Disabled'}
                                            </span>
                                        </div>
                                    }
                                >
                                    <div className="mb-4 flex items-center gap-2">
                                        <InputSwitch
                                            checked={tenant.enabled}
                                            onChange={(e) => saveTenant(tenant.uuid, e.value, tenant.custom_settings)}
                                        />
                                        <label>Game enabled for this tenant</label>
                                    </div>
                                    <p className="text-sm text-gray-500 mb-4">
                                        Leave fields empty to use global defaults.
                                    </p>
                                    <SettingsForm
                                        settings={{ ...game.settings, ...tenant.custom_settings } as GameSettings}
                                        onSave={(s) => saveTenant(tenant.uuid, tenant.enabled, s)}
                                        label={`${tenant.name} Settings`}
                                    />
                                </AccordionTab>
                            ))}
                        </Accordion>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
```

- [ ] **Step 2: Commit**

```bash
git add resources/js/pages/admin/games/fantasy/settings.tsx
git commit -m "feat: add Fantasy game settings admin page with tenant overrides"
```

---

## Task 8: Round Monitor Admin Page

**Files:**
- Create: `resources/js/pages/admin/games/fantasy/rounds.tsx`
- Create: `app/Http/Controllers/Admin/Games/FantasyRoundController.php`

- [ ] **Step 1: Create round controller**

The round monitor reads from the Fantasy game server's API. The SSO makes HTTP calls to the Fantasy game server.

```php
<?php
// app/Http/Controllers/Admin/Games/FantasyRoundController.php

namespace App\Http\Controllers\Admin\Games;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Inertia;

class FantasyRoundController extends Controller
{
    private function fantasyApiUrl(): string
    {
        return config('services.chinga_fantasy.api_url', 'http://localhost:3001');
    }

    public function index(Request $request)
    {
        // Fetch recent rounds from Fantasy game server
        // The game server will need a new endpoint for this (admin/rounds)
        // For now, render the page with empty data — the endpoint is added in cleanup phase

        return Inertia::render('admin/games/fantasy/rounds', [
            'rounds' => [],
        ]);
    }
}
```

- [ ] **Step 2: Create rounds page**

```tsx
// resources/js/pages/admin/games/fantasy/rounds.tsx
import AppLayout from '@/components/acumatica/Layout/AppLayout';
import PageHeader from '@/components/acumatica/Common/PageHeader';
import { DataTable } from 'primereact/datatable';
import { Column } from 'primereact/column';
import { Tag } from 'primereact/tag';

interface Round {
    id: number;
    tenant_uuid: string;
    created_at: string;
    team_count: number;
    bet_count: number;
    total_wagered: string;
    total_paid: string;
    status: string;
}

interface Props {
    rounds: Round[];
}

export default function FantasyRounds({ rounds }: Props) {
    const statusTemplate = (round: Round) => {
        const severity = round.status === 'active' ? 'info'
            : round.status === 'completed' ? 'success'
            : 'warning';
        return <Tag value={round.status} severity={severity} />;
    };

    const dateTemplate = (round: Round) => (
        <span>{new Date(round.created_at).toLocaleString()}</span>
    );

    const currencyTemplate = (field: string) => (round: Round) => (
        <span>N$ {parseFloat((round as any)[field] || '0').toFixed(2)}</span>
    );

    return (
        <AppLayout>
            <PageHeader
                title="Fantasy Rounds"
                subtitle="Monitor active and recent game rounds"
            />

            <div className="p-4">
                {rounds.length === 0 ? (
                    <div className="text-center py-12 text-gray-500">
                        <p>Round monitoring will be available once the Fantasy game server integration is complete.</p>
                    </div>
                ) : (
                    <DataTable value={rounds} paginator rows={25} stripedRows>
                        <Column field="id" header="Round #" sortable />
                        <Column header="Date" body={dateTemplate} sortable />
                        <Column field="team_count" header="Teams" />
                        <Column field="bet_count" header="Bets" sortable />
                        <Column header="Wagered" body={currencyTemplate('total_wagered')} sortable />
                        <Column header="Paid Out" body={currencyTemplate('total_paid')} sortable />
                        <Column header="Status" body={statusTemplate} />
                    </DataTable>
                )}
            </div>
        </AppLayout>
    );
}
```

- [ ] **Step 3: Add route for rounds**

Add to the Fantasy admin routes (same location as Task 5):

```php
Route::get('/rounds', [FantasyRoundController::class, 'index'])->name('admin.games.fantasy.rounds');
```

- [ ] **Step 4: Add Fantasy API URL to services config**

In `config/services.php`, add:

```php
'chinga_fantasy' => [
    'api_url' => env('CHINGA_FANTASY_API_URL', 'http://localhost:3001'),
],
```

And in `.env`:

```
CHINGA_FANTASY_API_URL=http://localhost:3001
```

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Admin/Games/FantasyRoundController.php resources/js/pages/admin/games/fantasy/rounds.tsx routes/ config/services.php
git commit -m "feat: add Fantasy round monitor admin page (scaffold)"
```

---

## Task 9: Add Fantasy Navigation to Admin Sidebar

**Files:**
- Modify: `resources/js/components/acumatica/Layout/Sidebar.tsx` (or equivalent navigation component)

- [ ] **Step 1: Find the sidebar navigation structure**

Check the existing sidebar to understand how navigation items are defined. Look for patterns like menu arrays or nav items.

- [ ] **Step 2: Add Fantasy game section**

Add a "Games" section (or "Chinga Fantasy" subsection) with links to:
- `/admin/games/fantasy/teams` — "Teams"
- `/admin/games/fantasy/settings` — "Settings"
- `/admin/games/fantasy/rounds` — "Rounds"

Follow the existing navigation pattern (icons, grouping, active state detection).

- [ ] **Step 3: Commit**

```bash
git add resources/js/components/acumatica/Layout/Sidebar.tsx
git commit -m "feat: add Fantasy game management links to admin sidebar"
```

---

## Task 10: Verify Admin Pages

- [ ] **Step 1: Start the SSO dev server**

```bash
cd /Users/richard/Projects/chinga-games-sso
php artisan serve &
npm run dev &
```

- [ ] **Step 2: Navigate to each admin page**

1. Login to SSO admin
2. Navigate to `/admin/games/fantasy/teams` — should load the team management page
3. Try adding a team — should create record in `fantasy_teams` table
4. Navigate to `/admin/games/fantasy/settings` — should show global settings and tenant overrides
5. Navigate to `/admin/games/fantasy/rounds` — should show the scaffold page

- [ ] **Step 3: Verify API endpoints**

```bash
# Get a client credentials token
TOKEN=$(curl -s -X POST http://chinga-games-sso.test/oauth/token \
  -H "Content-Type: application/json" \
  -d '{"grant_type":"client_credentials","client_id":"<server-client-id>","client_secret":"<server-client-secret>","scope":""}' | jq -r .access_token)

# Fetch teams
curl -s http://chinga-games-sso.test/api/v1/games/<game-uuid>/teams \
  -H "Authorization: Bearer $TOKEN" | jq .

# Fetch config
curl -s http://chinga-games-sso.test/api/v1/games/<game-uuid>/config?tenant_uuid=<tenant-uuid> \
  -H "Authorization: Bearer $TOKEN" | jq .
```

Expected: Teams list and config JSON returned successfully.
