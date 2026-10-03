<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Services\GameSettingsWriter;
use App\Support\SettingsSchema;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function store(Request $request, GameSettingsWriter $writer): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash', Rule::unique('games')],
            'description' => ['nullable', 'string'],
            'type' => ['required', Rule::in(['slots', 'table', 'instant', 'other'])],
            'status' => [Rule::in(['active', 'inactive', 'development'])],
            'version' => ['nullable', 'string', 'max:50'],
            'thumbnail_url' => ['nullable', 'url', 'max:500'],
            'backend_url' => ['nullable', 'url', 'max:500'],
            'launch_url' => ['nullable', 'url', 'max:500'],
            'settings' => ['nullable', 'array'],
            'settings_schema' => ['nullable', 'array', $this->settingsSchemaRule()],
        ]);

        // A game registered with settings gets them through the writer, so they
        // meet their schema and the Kulipi Kuna rules and the audit starts here.
        $settings = $validated['settings'] ?? null;
        unset($validated['settings']);
        $next = $settings === null ? null : GameSettingsWriter::validateGlobal(new SettingsSchema($validated['settings_schema'] ?? null), $settings, 'settings.');

        $game = DB::transaction(function () use ($validated, $next, $writer, $request) {
            $game = Game::create($validated);
            if ($next !== null) {
                $writer->writeGlobal($game, $next, $request->user(), 'settings.');
            }

            return $game;
        });

        return response()->json(['data' => $game], 201);
    }

    public function show(Game $game): JsonResponse
    {
        $game->loadCount('tenants');
        $game->load(['tenants:id,uuid,name,slug,status']);

        return response()->json(['data' => $game]);
    }

    public function update(Request $request, Game $game, GameSettingsWriter $writer): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => [Rule::in(['slots', 'table', 'instant', 'other'])],
            'status' => [Rule::in(['active', 'inactive', 'development'])],
            'version' => ['nullable', 'string', 'max:50'],
            'thumbnail_url' => ['nullable', 'url', 'max:500'],
            'backend_url' => ['nullable', 'url', 'max:500'],
            'launch_url' => ['nullable', 'url', 'max:500'],
            'settings' => ['nullable', 'array'],
            'settings_schema' => ['nullable', 'array', $this->settingsSchemaRule()],
        ]);

        // Settings go through the same path as the admin console: schema
        // validation, the Kulipi Kuna rules and the audit, all in one
        // transaction with the other fields (K4 final review, finding 2).
        $hasSettings = array_key_exists('settings', $validated);
        $settings = $validated['settings'] ?? [];
        unset($validated['settings']);
        $schema = new SettingsSchema(array_key_exists('settings_schema', $validated) ? $validated['settings_schema'] : $game->settings_schema);
        $next = $hasSettings ? GameSettingsWriter::validateGlobal($schema, $settings, 'settings.') : null;

        DB::transaction(function () use ($game, $validated, $next, $writer, $request) {
            $game->update($validated);
            if ($next !== null) {
                $writer->writeGlobal($game, $next, $request->user(), 'settings.');
            }
        });

        return response()->json(['data' => $game->fresh()]);
    }

    /** settings_schema must stay inside the subset the settings form can render. */
    private function settingsSchemaRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! is_array($value)) {
                return;
            }
            try {
                SettingsSchema::assertValid($value);
            } catch (\InvalidArgumentException $e) {
                $fail($e->getMessage());
            }
        };
    }
}
