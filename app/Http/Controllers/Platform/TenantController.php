<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Tenant\TenantOnboardingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

class TenantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Tenant::query();

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('contact_email', 'like', "%{$search}%");
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $tenants = $query->withCount(['users', 'venues'])
            ->orderBy($request->input('sort', 'created_at'), $request->input('direction', 'desc'))
            ->paginate($request->input('per_page', 25));

        return response()->json($tenants);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'alpha_dash', Rule::unique('tenants')],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:255'],
            'license_number' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['required', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:20'],
            'logo_url' => ['nullable', 'url', 'max:500'],
            'domain' => ['nullable', 'string', 'max:255', Rule::unique('tenants')],
            'country_code' => ['string', 'size:2'],
            'currency' => ['string', 'size:3'],
            'timezone' => ['string', 'max:50'],
            'revenue_share_pct' => ['numeric', 'min:0', 'max:100'],
            'contract_starts_at' => ['nullable', 'date'],
            'contract_ends_at' => ['nullable', 'date', 'after:contract_starts_at'],
            'settings' => ['nullable', 'array'],
            'admin_email' => ['nullable', 'email'],
            'admin_name' => ['nullable', 'string', 'max:255'],
            'game_ids' => ['nullable', 'array'],
            'game_ids.*' => ['exists:games,uuid'],
        ]);

        $onboarding = app(TenantOnboardingService::class);
        $result = $onboarding->onboard($validated);

        return response()->json([
            'data' => $result,
            'message' => 'Tenant created successfully.',
        ], 201);
    }

    public function show(Tenant $tenant): JsonResponse
    {
        $tenant->loadCount(['users', 'venues', 'voucherCodes']);
        $tenant->load('enabledGames');

        return response()->json(['data' => $tenant]);
    }

    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'legal_name' => ['nullable', 'string', 'max:255'],
            'registration_number' => ['nullable', 'string', 'max:255'],
            'license_number' => ['nullable', 'string', 'max:255'],
            'contact_email' => ['sometimes', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:20'],
            'logo_url' => ['nullable', 'url', 'max:500'],
            'domain' => ['nullable', 'string', 'max:255', Rule::unique('tenants')->ignore($tenant->id)],
            'country_code' => ['string', 'size:2'],
            'currency' => ['string', 'size:3'],
            'timezone' => ['string', 'max:50'],
            'revenue_share_pct' => ['numeric', 'min:0', 'max:100'],
            'contract_starts_at' => ['nullable', 'date'],
            'contract_ends_at' => ['nullable', 'date'],
            'settings' => ['nullable', 'array'],
        ]);

        $tenant->update($validated);

        return response()->json(['data' => $tenant->fresh()]);
    }

    public function suspend(Tenant $tenant): JsonResponse
    {
        $tenant->update(['status' => 'suspended']);

        return response()->json(['data' => $tenant->fresh(), 'message' => 'Tenant suspended.']);
    }

    public function activate(Tenant $tenant): JsonResponse
    {
        $tenant->update(['status' => 'active']);

        return response()->json(['data' => $tenant->fresh(), 'message' => 'Tenant activated.']);
    }

    public function terminate(Tenant $tenant): JsonResponse
    {
        $tenant->update(['status' => 'terminated']);

        return response()->json(['data' => $tenant->fresh(), 'message' => 'Tenant terminated.']);
    }

    public function oauthClients(Tenant $tenant): JsonResponse
    {
        $clients = Passport::client()->where('tenant_id', $tenant->id)->get();

        return response()->json([
            'data' => $clients->map(fn ($c) => [
                'id' => $c->id,
                'name' => $c->name,
                'redirect' => $c->redirect,
                'revoked' => $c->revoked,
                'created_at' => $c->created_at,
            ]),
        ]);
    }

    public function createOAuthClient(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'redirect' => ['required', 'url'],
        ]);

        $clients = app(ClientRepository::class);
        $client = $clients->createAuthorizationCodeGrantClient(
            $validated['name'],
            [$validated['redirect']],
        );

        $client->tenant_id = $tenant->id;
        $client->save();

        return response()->json([
            'data' => [
                'id' => $client->id,
                'name' => $client->name,
                'secret' => $client->plainSecret,
                'redirect' => $client->redirect,
            ],
        ], 201);
    }
}
