<?php

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

        return Inertia::render('platform/games/fantasy/teams', [
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
