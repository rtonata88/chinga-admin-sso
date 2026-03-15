<?php

namespace App\Http\Controllers\OAuth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
