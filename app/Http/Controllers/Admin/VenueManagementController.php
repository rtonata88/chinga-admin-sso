<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
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

class VenueManagementController extends Controller
{
    public function __construct(
        protected SecurityAuditService $auditService
    ) {}

    /**
     * List all venues.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Venue::withCount(['staff', 'terminals', 'voucherCodes']);

        // Search
        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        // Sort
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_dir', 'desc');
        $query->orderBy($sortBy, $sortDir);

        $venues = $query->paginate($request->input('per_page', 25));

        return response()->json([
            'success' => true,
            'data' => $venues->items(),
            'meta' => [
                'current_page' => $venues->currentPage(),
                'last_page' => $venues->lastPage(),
                'per_page' => $venues->perPage(),
                'total' => $venues->total(),
            ],
        ]);
    }

    /**
     * Create a new venue.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:100', 'unique:venues'],
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
            ...$validated,
            'status' => 'active',
        ]);

        $this->auditService->log(
            'admin.venue.create',
            $request->user(),
            $request->user(),
            ['new_values' => ['venue_uuid' => $venue->uuid]]
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
    public function show(string $uuid): JsonResponse
    {
        $venue = Venue::where('uuid', $uuid)
            ->withCount(['staff', 'terminals', 'voucherCodes'])
            ->firstOrFail();

        // Get aggregate stats
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
     * Update a venue.
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $venue = Venue::where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:100', Rule::unique('venues')->ignore($venue->id)],
            'business_name' => ['nullable', 'string', 'max:255'],
            'license_number' => ['nullable', 'string', 'max:100'],
            'address_line_1' => ['sometimes', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'city' => ['sometimes', 'string', 'max:100'],
            'region' => ['nullable', 'string', 'max:100'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'country_code' => ['sometimes', 'string', 'size:2'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'timezone' => ['nullable', 'string', 'max:50'],
            'currency' => ['nullable', 'string', 'size:3'],
            'settings' => ['nullable', 'array'],
        ]);

        $oldValues = $venue->only(array_keys($validated));
        $venue->update($validated);

        $this->auditService->log(
            'admin.venue.update',
            $request->user(),
            $request->user(),
            ['old_values' => $oldValues, 'new_values' => $validated]
        );

        return response()->json([
            'success' => true,
            'message' => 'Venue updated successfully.',
            'data' => $venue->fresh(),
        ]);
    }

    /**
     * Suspend a venue.
     */
    public function suspend(Request $request, string $uuid): JsonResponse
    {
        $venue = Venue::where('uuid', $uuid)->firstOrFail();

        $venue->update(['status' => 'suspended']);

        $this->auditService->log(
            'admin.venue.suspend',
            $request->user(),
            $request->user()
        );

        return response()->json([
            'success' => true,
            'message' => 'Venue suspended successfully.',
        ]);
    }

    /**
     * Activate a venue.
     */
    public function activate(Request $request, string $uuid): JsonResponse
    {
        $venue = Venue::where('uuid', $uuid)->firstOrFail();

        $venue->update(['status' => 'active']);

        $this->auditService->log(
            'admin.venue.activate',
            $request->user(),
            $request->user()
        );

        return response()->json([
            'success' => true,
            'message' => 'Venue activated successfully.',
        ]);
    }

    /**
     * Soft delete a venue.
     */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $venue = Venue::where('uuid', $uuid)->firstOrFail();

        // Check for active codes with balance
        $activeBalance = VoucherCode::where('venue_id', $venue->id)
            ->whereIn('status', ['active', 'in_use'])
            ->where('balance', '>', 0)
            ->exists();

        if ($activeBalance) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot delete venue with active voucher codes that have balance.',
            ], 422);
        }

        $venue->delete();

        $this->auditService->log(
            'admin.venue.delete',
            $request->user(),
            $request->user()
        );

        return response()->json([
            'success' => true,
            'message' => 'Venue deleted successfully.',
        ]);
    }

    /**
     * List venue staff.
     */
    public function staff(string $uuid): JsonResponse
    {
        $venue = Venue::where('uuid', $uuid)->firstOrFail();

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
    public function addStaff(Request $request, string $uuid): JsonResponse
    {
        $venue = Venue::where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'username' => ['required', 'string', 'max:50'],
            'password' => ['required', 'string', 'min:8'],
            'display_name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20'],
            'role' => ['required', Rule::in(['owner', 'manager', 'staff', 'cashier'])],
            'pin' => ['nullable', 'string', 'size:4'],
        ]);

        // Check username uniqueness within venue
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
    public function terminals(string $uuid): JsonResponse
    {
        $venue = Venue::where('uuid', $uuid)->firstOrFail();

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
    public function addTerminal(Request $request, string $uuid): JsonResponse
    {
        $venue = Venue::where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'terminal_code' => ['required', 'string', 'max:50'],
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::in(['kiosk', 'tablet', 'terminal', 'pos'])],
        ]);

        // Check terminal code uniqueness within venue
        $exists = VenueTerminal::where('venue_id', $venue->id)
            ->where('terminal_code', $validated['terminal_code'])
            ->exists();

        if ($exists) {
            return response()->json([
                'success' => false,
                'message' => 'Terminal code already exists in this venue.',
            ], 422);
        }

        // Generate API key
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
                'api_key' => $apiKey, // Only shown once
            ],
        ], 201);
    }

    /**
     * Generate voucher codes for a venue.
     */
    public function generateCodes(Request $request, string $uuid): JsonResponse
    {
        $venue = Venue::where('uuid', $uuid)->firstOrFail();

        $validated = $request->validate([
            'count' => ['required', 'integer', 'min:1', 'max:100'],
            'initial_balance' => ['required', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'expires_at' => ['nullable', 'date', 'after:today'],
            'prefix' => ['nullable', 'string', 'max:10', 'alpha_num'],
        ]);

        $codes = [];
        $currency = $validated['currency'] ?? $venue->currency ?? 'NAD';
        $prefix = $validated['prefix'] ?? strtoupper(substr($venue->slug, 0, 3));
        $expiresAt = isset($validated['expires_at']) ? \Carbon\Carbon::parse($validated['expires_at']) : null;

        for ($i = 0; $i < $validated['count']; $i++) {
            // Generate unique code: PREFIX-XXXX-XXXX-XXXX
            do {
                $code = $prefix . '-' .
                    strtoupper(Str::random(4)) . '-' .
                    strtoupper(Str::random(4)) . '-' .
                    strtoupper(Str::random(4));
            } while (VoucherCode::where('code', $code)->exists());

            $voucherCode = VoucherCode::create([
                'uuid' => Str::uuid(),
                'venue_id' => $venue->id,
                'code' => $code,
                'balance' => $validated['initial_balance'],
                'currency' => $currency,
                'total_loaded' => $validated['initial_balance'],
                'total_cashed_out' => 0,
                'status' => 'active',
                'expires_at' => $expiresAt,
                'created_by_admin_id' => $request->user()->id,
            ]);

            $codes[] = [
                'uuid' => $voucherCode->uuid,
                'code' => $voucherCode->code,
                'balance' => $voucherCode->balance,
                'currency' => $voucherCode->currency,
                'expires_at' => $voucherCode->expires_at?->toIso8601String(),
            ];
        }

        $this->auditService->log(
            'admin.venue.codes.generate',
            $request->user(),
            $request->user(),
            [
                'new_values' => [
                    'venue_uuid' => $venue->uuid,
                    'count' => $validated['count'],
                    'initial_balance' => $validated['initial_balance'],
                    'currency' => $currency,
                ],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => "Generated {$validated['count']} voucher codes successfully.",
            'data' => $codes,
        ], 201);
    }

    /**
     * Get voucher codes for a venue.
     */
    public function venueCodes(Request $request, string $uuid): JsonResponse
    {
        $venue = Venue::where('uuid', $uuid)->firstOrFail();

        $query = VoucherCode::where('venue_id', $venue->id);

        // Filter by status
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        // Filter by balance
        if ($request->has('has_balance')) {
            if ($request->boolean('has_balance')) {
                $query->where('balance', '>', 0);
            } else {
                $query->where('balance', '<=', 0);
            }
        }

        // Search by code
        if ($code = $request->input('code')) {
            $query->where('code', 'like', "%{$code}%");
        }

        $codes = $query->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 25));

        return response()->json([
            'success' => true,
            'data' => $codes->map(fn ($c) => [
                'uuid' => $c->uuid,
                'code' => $c->code,
                'balance' => $c->balance,
                'currency' => $c->currency,
                'status' => $c->status,
                'total_loaded' => $c->total_loaded,
                'total_cashed_out' => $c->total_cashed_out,
                'last_activity_at' => $c->last_activity_at?->toIso8601String(),
                'expires_at' => $c->expires_at?->toIso8601String(),
                'created_at' => $c->created_at->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $codes->currentPage(),
                'last_page' => $codes->lastPage(),
                'per_page' => $codes->perPage(),
                'total' => $codes->total(),
            ],
        ]);
    }

    /**
     * Void/deactivate a voucher code.
     */
    public function voidCode(Request $request, string $uuid, string $codeUuid): JsonResponse
    {
        $venue = Venue::where('uuid', $uuid)->firstOrFail();
        $code = VoucherCode::where('uuid', $codeUuid)
            ->where('venue_id', $venue->id)
            ->firstOrFail();

        if ($code->status === 'voided') {
            return response()->json([
                'success' => false,
                'message' => 'Code is already voided.',
            ], 422);
        }

        $oldBalance = $code->balance;
        $code->update([
            'status' => 'voided',
            'balance' => 0,
        ]);

        $this->auditService->log(
            'admin.venue.codes.void',
            $request->user(),
            $request->user(),
            [
                'old_values' => ['status' => 'active', 'balance' => $oldBalance],
                'new_values' => ['status' => 'voided', 'balance' => 0],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Voucher code voided successfully.',
        ]);
    }

    /**
     * Add balance to a voucher code.
     */
    public function addBalance(Request $request, string $uuid, string $codeUuid): JsonResponse
    {
        $venue = Venue::where('uuid', $uuid)->firstOrFail();
        $code = VoucherCode::where('uuid', $codeUuid)
            ->where('venue_id', $venue->id)
            ->firstOrFail();

        if (!in_array($code->status, ['active', 'in_use'])) {
            return response()->json([
                'success' => false,
                'message' => 'Can only add balance to active codes.',
            ], 422);
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $oldBalance = $code->balance;
        $code->update([
            'balance' => $code->balance + $validated['amount'],
            'total_loaded' => $code->total_loaded + $validated['amount'],
            'last_activity_at' => now(),
        ]);

        $this->auditService->log(
            'admin.venue.codes.add-balance',
            $request->user(),
            $request->user(),
            [
                'old_values' => ['balance' => $oldBalance],
                'new_values' => ['balance' => $code->balance],
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Balance added successfully.',
            'data' => [
                'old_balance' => $oldBalance,
                'new_balance' => $code->balance,
            ],
        ]);
    }

    /**
     * Search voucher codes across all venues.
     */
    public function searchCodes(Request $request): JsonResponse
    {
        $query = VoucherCode::with('venue:id,uuid,name');

        // Search by code
        if ($code = $request->input('code')) {
            $query->where('code', 'like', "%{$code}%");
        }

        // Filter by venue
        if ($venueUuid = $request->input('venue_uuid')) {
            $venue = Venue::where('uuid', $venueUuid)->first();
            if ($venue) {
                $query->where('venue_id', $venue->id);
            }
        }

        // Filter by status
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }

        // Filter by balance
        if ($request->has('has_balance')) {
            if ($request->boolean('has_balance')) {
                $query->where('balance', '>', 0);
            } else {
                $query->where('balance', '<=', 0);
            }
        }

        $codes = $query->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 25));

        return response()->json([
            'success' => true,
            'data' => $codes->map(fn ($c) => [
                'uuid' => $c->uuid,
                'code' => $c->code,
                'venue' => [
                    'uuid' => $c->venue->uuid,
                    'name' => $c->venue->name,
                ],
                'balance' => $c->balance,
                'currency' => $c->currency,
                'status' => $c->status,
                'total_loaded' => $c->total_loaded,
                'total_cashed_out' => $c->total_cashed_out,
                'last_activity_at' => $c->last_activity_at?->toIso8601String(),
                'expires_at' => $c->expires_at?->toIso8601String(),
                'created_at' => $c->created_at->toIso8601String(),
            ]),
            'meta' => [
                'current_page' => $codes->currentPage(),
                'last_page' => $codes->lastPage(),
                'per_page' => $codes->perPage(),
                'total' => $codes->total(),
            ],
        ]);
    }

    /**
     * Get venue statistics.
     */
    public function stats(): JsonResponse
    {
        $stats = [
            'total_venues' => Venue::count(),
            'active_venues' => Venue::where('status', 'active')->count(),
            'suspended_venues' => Venue::where('status', 'suspended')->count(),
            'total_staff' => VenueStaff::count(),
            'total_terminals' => VenueTerminal::count(),
            'active_terminals' => VenueTerminal::where('status', 'active')->count(),
            'voucher_codes' => [
                'total' => VoucherCode::count(),
                'active' => VoucherCode::whereIn('status', ['active', 'in_use'])->count(),
                'total_balance' => VoucherCode::whereIn('status', ['active', 'in_use'])->sum('balance'),
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $stats,
        ]);
    }
}
