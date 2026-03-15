<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Game;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class GameController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Game::query();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        if ($type = $request->input('type')) {
            $query->where('type', $type);
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $games = $query->withCount('tenants')
            ->orderBy($request->input('sort', 'name'))
            ->paginate($request->input('per_page', 25));

        return response()->json($games);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('games')],
            'description' => ['nullable', 'string'],
            'type' => ['required', Rule::in(['slots', 'table', 'instant', 'other'])],
            'status' => [Rule::in(['active', 'inactive', 'development'])],
            'version' => ['nullable', 'string', 'max:50'],
            'thumbnail_url' => ['nullable', 'url', 'max:500'],
            'settings' => ['nullable', 'array'],
        ]);

        $game = Game::create($validated);

        return response()->json(['data' => $game], 201);
    }

    public function show(Game $game): JsonResponse
    {
        $game->loadCount('tenants');
        $game->load(['tenants:id,uuid,name,slug,status']);

        return response()->json(['data' => $game]);
    }

    public function update(Request $request, Game $game): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => [Rule::in(['slots', 'table', 'instant', 'other'])],
            'status' => [Rule::in(['active', 'inactive', 'development'])],
            'version' => ['nullable', 'string', 'max:50'],
            'thumbnail_url' => ['nullable', 'url', 'max:500'],
            'settings' => ['nullable', 'array'],
        ]);

        $game->update($validated);

        return response()->json(['data' => $game->fresh()]);
    }
}
