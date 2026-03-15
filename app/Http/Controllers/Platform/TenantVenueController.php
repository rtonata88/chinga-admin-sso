<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\Venue;
use App\Models\VenueStaff;
use App\Models\VenueTerminal;
use App\Models\VoucherCode;
use App\Services\Auth\SecurityAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TenantVenueController extends Controller
{
    public function __construct(
        protected SecurityAuditService $auditService
    ) {}

    /**
     * List venues for a tenant.
     */
    public function index(Request $request, Tenant $tenant): JsonResponse
    {
        $query = Venue::where('tenant_id', $tenant->id)
            ->withCount(['staff', 'terminals', 'voucherCodes']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%");
            });
        }

        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        $sortBy = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_dir', 'desc');
        $query->orderBy($sortBy, $sortDir);

        $venues = $query->get();

        return response()->json([
            'success' => true,
            'data' => $venues,
        ]);
    }

    /**
     * Create a venue for a tenant.
     */
    public function store(Request $request, Tenant $tenant): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:100', Rule::unique('venues')->where('tenant_id', $tenant->id)],
            'business_name' => ['nullable', 'string', 'max:255'],
            'license_number' => ['nullable', 'string', 'max:100'],
            'address_line_1' => ['required', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'region' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country_code' => ['required', 'string', 'size:2'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'timezone' => ['nullable', 'string', 'max:50'],
            'currency' => ['nullable', 'string', 'size:3'],
            'settings' => ['nullable', 'array'],
        ]);

        $venue = Venue::create([
            'uuid' => Str::uuid(),
            'tenant_id' => $tenant->id,
            ...$validated,
            'status' => 'active',
        ]);

        $this->auditService->log(
            'platform.venue.create',
            $request->user(),
            $request->user(),
            ['new_values' => ['venue_uuid' => $venue->uuid, 'tenant_uuid' => $tenant->uuid]]
        );

        return response()->json([
            'success' => true,
            'message' => 'Venue created successfully.',
            'data' => $venue,
        ], 201);
    }

    /**
     * Get venue details.
     */
    public function show(Tenant $tenant, string $uuid): JsonResponse
    {
        $venue = Venue::where('uuid', $uuid)
            ->where('tenant_id', $tenant->id)
            ->withCount(['staff', 'terminals', 'voucherCodes'])
            ->firstOrFail();

        $activeCodesBalance = VoucherCode::where('venue_id', $venue->id)
            ->whereIn('status', ['active', 'in_use'])
            ->sum('balance');

        $totalLoaded = VoucherCode::where('venue_id', $venue->id)->sum('total_loaded');
        $totalCashedOut = VoucherCode::where('venue_id', $venue->id)->sum('total_cashed_out');

        return response()->json([
            'success' => true,
            'data' => [
                ...$venue->toArray(),
                'stats' => [
                    'active_codes_balance' => $activeCodesBalance,
                    'total_loaded' => $totalLoaded,
                    'total_cashed_out' => $totalCashedOut,
                ],
            ],
        ]);
    }

    /**
     * Suspend a venue.
     */
    public function suspend(Request $request, Tenant $tenant, string $uuid): JsonResponse
    {
        $venue = Venue::where('uuid', $uuid)
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();

        $venue->update(['status' => 'suspended']);

        $this->auditService->log(
            'platform.venue.suspend',
            $request->user(),
            $request->user(),
            ['new_values' => ['venue_uuid' => $venue->uuid, 'tenant_uuid' => $tenant->uuid]]
        );

        return response()->json([
            'success' => true,
            'message' => 'Venue suspended successfully.',
        ]);
    }

    /**
     * Activate a venue.
     */
    public function activate(Request $request, Tenant $tenant, string $uuid): JsonResponse
    {
        $venue = Venue::where('uuid', $uuid)
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();

        $venue->update(['status' => 'active']);

        $this->auditService->log(
            'platform.venue.activate',
            $request->user(),
            $request->user(),
            ['new_values' => ['venue_uuid' => $venue->uuid, 'tenant_uuid' => $tenant->uuid]]
        );

        return response()->json([
            'success' => true,
            'message' => 'Venue activated successfully.',
        ]);
    }

    /**
     * List venue staff.
     */
    public function staff(Tenant $tenant, string $uuid): JsonResponse
    {
        $venue = Venue::where('uuid', $uuid)
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();

        $staff = VenueStaff::where('venue_id', $venue->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $staff->map(fn ($s) => [
                'uuid' => $s->uuid,
                'username' => $s->username,
                'display_name' => $s->display_name,
                'email' => $s->email,
                'phone' => $s->phone,
                'role' => $s->role,
                'status' => $s->status,
                'last_login_at' => $s->last_login_at?->toIso8601String(),
                'created_at' => $s->created_at->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Add staff to venue.
     */
    public function addStaff(Request $request, Tenant $tenant, string $uuid): JsonResponse
    {
        $venue = Venue::where('uuid', $uuid)
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();

        $validated = $request->validate([
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'min:8'],
            'display_name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'role' => ['required', Rule::in(['owner', 'manager', 'staff', 'cashier'])],
            'pin' => ['nullable', 'string', 'size:4'],
        ]);

        $exists = VenueStaff::where('venue_id', $venue->id)
            ->where('username', $validated['username'])
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Username already exists in this venue.',
            ], 422);
        }

        $staff = VenueStaff::create([
            'uuid' => Str::uuid(),
            'venue_id' => $venue->id,
            'username' => $validated['username'],
            'password' => Hash::make($validated['password']),
            'display_name' => $validated['display_name'],
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'role' => $validated['role'],
            'pin' => isset($validated['pin']) ? Hash::make($validated['pin']) : null,
            'status' => 'active',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Staff member added successfully.',
            'data' => $staff,
        ], 201);
    }

    /**
     * List venue terminals.
     */
    public function terminals(Tenant $tenant, string $uuid): JsonResponse
    {
        $venue = Venue::where('uuid', $uuid)
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();

        $terminals = VenueTerminal::where('venue_id', $venue->id)
            ->orderBy('terminal_code')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $terminals->map(fn ($t) => [
                'uuid' => $t->uuid,
                'terminal_code' => $t->terminal_code,
                'name' => $t->name,
                'type' => $t->type,
                'status' => $t->status,
                'last_heartbeat_at' => $t->last_heartbeat_at?->toIso8601String(),
                'ip_address' => $t->ip_address,
                'created_at' => $t->created_at->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Register a terminal.
     */
    public function addTerminal(Request $request, Tenant $tenant, string $uuid): JsonResponse
    {
        $venue = Venue::where('uuid', $uuid)
            ->where('tenant_id', $tenant->id)
            ->firstOrFail();

        $validated = $request->validate([
            'terminal_code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(['kiosk', 'tablet', 'terminal', 'pos'])],
        ]);

        $exists = VenueTerminal::where('venue_id', $venue->id)
            ->where('terminal_code', $validated['terminal_code'])
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Terminal code already exists in this venue.',
            ], 422);
        }

        $apiKey = Str::random(64);

        $terminal = VenueTerminal::create([
            'uuid' => Str::uuid(),
            'venue_id' => $venue->id,
            'terminal_code' => $validated['terminal_code'],
            'name' => $validated['name'],
            'type' => $validated['type'],
            'api_key' => Hash::make($apiKey),
            'status' => 'active',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Terminal registered successfully.',
            'data' => [
                'terminal' => $terminal,
                'api_key' => $apiKey,
            ],
        ], 201);
    }
}
