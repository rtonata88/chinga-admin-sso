<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use App\Models\Game;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

class OAuthClientController extends Controller
{
    public function __construct(
        protected ClientRepository $clients
    ) {}

    /**
     * List all OAuth clients for the current user/tenant.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $tenant = app('current_tenant');

        if ($user->isPlatformAdmin() && ! $tenant) {
            // Platform admin without tenant context: see all
            $clients = Passport::client()->all();
        } elseif ($tenant) {
            // Tenant context: see tenant clients
            $clients = Passport::client()->where('tenant_id', $tenant->id)->get();
        } else {
            $clients = $user->clients;
        }

        return response()->json([
            'data' => $clients->map(fn ($client) => $this->formatClient($client)),
        ]);
    }

    /**
     * Create a new OAuth client.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'redirect' => ['required', 'url'],
            'confidential' => ['boolean'],
        ]);

        $client = $this->clients->create(
            $request->user()->id,
            $validated['name'],
            $validated['redirect'],
            null,
            false,
            false,
            $validated['confidential'] ?? true
        );

        // Set tenant_id on the client
        $tenant = app('current_tenant');
        if ($tenant) {
            $client->tenant_id = $tenant->id;
            $client->save();
        }

        return response()->json([
            'data' => [
                'id' => $client->id,
                'name' => $client->name,
                'secret' => $client->plainSecret, // Only returned on creation
                'redirect' => $client->redirect,
                'created_at' => $client->created_at,
            ],
        ], 201);
    }

    /**
     * Get a specific OAuth client.
     */
    public function show(Request $request, string $id): JsonResponse
    {
        $client = Passport::client()->findOrFail($id);

        // Check ownership or admin
        if ($client->user_id !== $request->user()->id && !$request->user()->tokenCan('admin')) {
            abort(403, 'Unauthorized');
        }

        return response()->json([
            'data' => $this->formatClient($client),
        ]);
    }

    /**
     * Update an OAuth client.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $client = Passport::client()->findOrFail($id);

        // Check ownership or admin
        if ($client->user_id !== $request->user()->id && !$request->user()->tokenCan('admin')) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'redirect' => ['sometimes', 'url'],
        ]);

        $client->update($validated);

        return response()->json([
            'data' => $this->formatClient($client),
        ]);
    }

    /**
     * Delete an OAuth client.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $client = Passport::client()->findOrFail($id);

        // Check ownership or admin
        if ($client->user_id !== $request->user()->id && !$request->user()->tokenCan('admin')) {
            abort(403, 'Unauthorized');
        }

        $client->delete();

        return response()->json(null, 204);
    }

    /**
     * Regenerate client secret.
     */
    public function regenerateSecret(Request $request, string $id): JsonResponse
    {
        $client = Passport::client()->findOrFail($id);

        // Check ownership or admin
        if ($client->user_id !== $request->user()->id && !$request->user()->tokenCan('admin')) {
            abort(403, 'Unauthorized');
        }

        $client = $this->clients->regenerateSecret($client);

        return response()->json([
            'data' => [
                'id' => $client->id,
                'secret' => $client->plainSecret,
            ],
        ]);
    }

    /**
     * List the games this OAuth client is authorized for.
     */
    public function games(Request $request, string $id): JsonResponse
    {
        $client = Passport::client()->findOrFail($id);
        $this->authorizeClient($request, $client);

        $games = Game::whereIn('id', DB::table('oauth_client_games')
            ->where('oauth_client_id', $client->id)
            ->pluck('game_id'))
            ->get(['uuid', 'name', 'slug']);

        return response()->json(['data' => $games]);
    }

    /**
     * Set the games this OAuth client is authorized for. Replaces any
     * existing bindings. Pass an array of game UUIDs.
     */
    public function setGames(Request $request, string $id): JsonResponse
    {
        $client = Passport::client()->findOrFail($id);
        $this->authorizeClient($request, $client);

        $validated = $request->validate([
            'game_uuids' => ['present', 'array'],
            'game_uuids.*' => ['string'],
        ]);

        $gameIds = Game::whereIn('uuid', $validated['game_uuids'])->pluck('id');

        if ($gameIds->count() !== count($validated['game_uuids'])) {
            return response()->json([
                'message' => 'One or more game UUIDs are invalid.',
            ], 422);
        }

        DB::transaction(function () use ($client, $gameIds) {
            DB::table('oauth_client_games')
                ->where('oauth_client_id', $client->id)
                ->delete();

            foreach ($gameIds as $gameId) {
                DB::table('oauth_client_games')->insert([
                    'oauth_client_id' => $client->id,
                    'game_id' => $gameId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        return $this->games($request, $id);
    }

    private function authorizeClient(Request $request, $client): void
    {
        if ($client->user_id !== $request->user()->id && !$request->user()->tokenCan('admin')) {
            abort(403, 'Unauthorized');
        }
    }

    /**
     * Format client for response.
     */
    protected function formatClient($client): array
    {
        return [
            'id' => $client->id,
            'name' => $client->name,
            'redirect' => $client->redirect,
            'personal_access_client' => $client->personal_access_client,
            'password_client' => $client->password_client,
            'revoked' => $client->revoked,
            'created_at' => $client->created_at,
            'updated_at' => $client->updated_at,
        ];
    }
}
