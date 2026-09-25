<?php

namespace App\Http\Controllers\Admin\Games;

use App\Http\Controllers\Controller;
use App\Services\FantasyAdminClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Chinga Fantasy team pool, as the v2 engine holds it (Fantasy v2
 * PRD §9 S1): reads with gaming:read, writes with gaming:write. Teams
 * are fictional with original marks and are deactivated, never deleted,
 * because settled rounds reference them.
 */
class FantasyTeamController extends Controller
{
    private const PER_PAGE = 25;

    public function __construct(protected FantasyAdminClient $client) {}

    public function index(Request $request): Response
    {
        $search = strtolower(trim((string) $request->input('search', '')));
        $active = $request->has('active') && $request->input('active') !== '' ? $request->boolean('active') : null;
        $page = max(1, (int) $request->query('page', 1));
        $error = null;
        $all = [];
        try {
            $all = $this->client->listTeams();
        } catch (\Throwable $e) {
            Log::warning('FantasyTeam index failed', ['error' => $e->getMessage()]);
            $error = 'Could not load the team pool. Is the Chinga Fantasy v2 engine reachable?';
        }
        $filtered = array_values(array_filter($all, function (array $t) use ($search, $active) {
            if ($active !== null && (bool) ($t['active'] ?? false) !== $active) {
                return false;
            }

            return $search === '' || str_contains(strtolower($t['name'] ?? ''), $search) || str_contains(strtolower($t['shortName'] ?? ''), $search);
        }));
        $total = count($filtered);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $lastPage);
        $rows = array_map(fn (array $t) => [
            'id' => $t['id'],
            'name' => $t['name'],
            'short_name' => $t['shortName'] ?? null,
            'colour' => $t['colour'] ?? '#888888',
            'active' => (bool) ($t['active'] ?? false),
        ], array_slice($filtered, ($page - 1) * self::PER_PAGE, self::PER_PAGE));

        return Inertia::render('fantasy/teams', [
            'teams' => ['data' => $rows, 'current_page' => $page, 'last_page' => $lastPage, 'total' => $total],
            'filters' => $request->only(['search', 'active']),
            'error' => $error,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(self::rules());

        return $this->forward(fn () => $this->client->createTeam(self::payload($validated)), 'Team created.');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $validated = $request->validate(self::rules());

        return $this->forward(fn () => $this->client->updateTeam($id, self::payload($validated)), 'Team updated.');
    }

    public function destroy(int $id): RedirectResponse
    {
        return $this->forward(fn () => $this->client->deactivateTeam($id), 'Team deactivated. It will not be dealt into new rounds.');
    }

    public function bulkToggle(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', 'min:1'],
            'active' => ['required', 'boolean'],
        ]);

        return $this->forward(function () use ($validated) {
            foreach ($validated['ids'] as $id) {
                $this->client->updateTeam((int) $id, ['active' => (bool) $validated['active']]);
            }
        }, 'Teams updated.');
    }

    private static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['required', 'string', 'max:6', 'regex:/^[A-Za-z0-9]+$/'],
            'colour' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'active' => ['boolean'],
        ];
    }

    private static function payload(array $v): array
    {
        return [
            'name' => $v['name'],
            'short_name' => strtoupper($v['short_name']),
            'colour' => strtoupper($v['colour']),
            ...(array_key_exists('active', $v) ? ['active' => (bool) $v['active']] : []),
        ];
    }

    private function forward(callable $call, string $success): RedirectResponse
    {
        try {
            $call();
        } catch (\Throwable $e) {
            Log::warning('FantasyTeam write failed', ['error' => $e->getMessage()]);

            return redirect()->back()->with('error', 'The team pool could not be updated: '.$e->getMessage());
        }

        return redirect()->back()->with('success', $success);
    }
}
