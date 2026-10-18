<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Models\OperatorCall;
use App\Models\OperatorIntegration;
use App\Models\OperatorMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\SecurityAuditService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The platform admin's view of a tenant's operator integration (the same settings as
 * `php artisan operators:setup`). A secret is returned once, by create and rotate, and
 * never by any read: the operator gets it over a secure channel and we only keep it
 * encrypted.
 */
class OperatorIntegrationController extends Controller
{
    /** Outcomes of an inbound call we turned away before doing anything. */
    private const REFUSED_OUTCOMES = ['unknown_operator', 'bad_signature', 'operator_inactive', 'ip_not_allowed'];

    private const ATTENTION_STATUSES = ['retrying', 'abandoned'];

    public function __construct(
        protected SecurityAuditService $auditService
    ) {}

    public function show(Tenant $tenant): JsonResponse
    {
        $integration = $this->integrationFor($tenant);

        return response()->json(['data' => $integration ? $this->present($integration) : null]);
    }

    public function store(Request $request, Tenant $tenant): JsonResponse
    {
        if ($this->integrationFor($tenant) !== null) {
            return response()->json(['message' => 'This tenant already has an operator integration.'], 409);
        }
        $validated = $request->validate($this->rules());
        $this->ensureWalletUrl($validated['wallet_mode'] ?? OperatorIntegration::MODE_HOSTED, $validated['wallet_base_url'] ?? null);

        $secret = Str::random(48);
        $integration = OperatorIntegration::create([
            'tenant_id' => $tenant->id,
            'operator_key' => (string) Str::uuid(),
            'secret' => $secret,
            'wallet_mode' => $validated['wallet_mode'] ?? OperatorIntegration::MODE_HOSTED,
            'wallet_base_url' => $validated['wallet_base_url'] ?? null,
            'allowed_ips' => $this->listOrNull($validated['allowed_ips'] ?? null),
            'embed_origins' => $this->listOrNull($validated['embed_origins'] ?? null),
            'status' => $validated['status'] ?? 'active',
        ]);

        $this->audit($request, 'platform.operator_integration.create', $tenant, $integration, null, $this->auditable($integration));

        return response()->json([
            'data' => $this->present($integration),
            'secret' => $secret,
            'message' => 'Integration created. The secret is shown once.',
        ], 201);
    }

    public function update(Request $request, Tenant $tenant): JsonResponse
    {
        $integration = $this->integrationFor($tenant);
        if ($integration === null) {
            return response()->json(['message' => 'This tenant has no operator integration.'], 404);
        }
        $validated = $request->validate($this->rules());
        $before = $this->auditable($integration);

        if (isset($validated['wallet_mode']) && $validated['wallet_mode'] !== $integration->wallet_mode) {
            $funded = $integration->fundedPlayerWallets();
            if ($funded > 0) {
                throw ValidationException::withMessages([
                    'wallet_mode' => "{$funded} operator player wallet(s) still hold a {$integration->wallet_mode} balance. Settle them before changing the mode.",
                ]);
            }
        }

        foreach (['wallet_mode', 'wallet_base_url', 'status'] as $field) {
            if (array_key_exists($field, $validated)) {
                $integration->{$field} = $validated[$field];
            }
        }
        foreach (['allowed_ips', 'embed_origins'] as $field) {
            if (array_key_exists($field, $validated)) {
                $integration->{$field} = $this->listOrNull($validated[$field]);
            }
        }
        $this->ensureWalletUrl($integration->wallet_mode, $integration->wallet_base_url);
        $integration->save();

        $this->audit($request, 'platform.operator_integration.update', $tenant, $integration, $before, $this->auditable($integration));

        return response()->json(['data' => $this->present($integration->fresh())]);
    }

    public function rotateSecret(Request $request, Tenant $tenant): JsonResponse
    {
        $integration = $this->integrationFor($tenant);
        if ($integration === null) {
            return response()->json(['message' => 'This tenant has no operator integration.'], 404);
        }

        $secret = Str::random(48);
        $integration->fill(['previous_secret' => $integration->secret, 'secret' => $secret, 'secret_rotated_at' => now()])->save();

        $this->audit($request, 'platform.operator_integration.rotate_secret', $tenant, $integration, null, [
            'secret_rotated_at' => $integration->secret_rotated_at?->toIso8601String(),
        ], 'warning');

        return response()->json([
            'data' => $this->present($integration->fresh()),
            'secret' => $secret,
            'message' => 'Secret rotated. The previous one keeps verifying for '.OperatorIntegration::SECRET_OVERLAP_HOURS.' hours.',
        ]);
    }

    public function calls(Request $request, Tenant $tenant): JsonResponse
    {
        $integration = $this->integrationFor($tenant);
        if ($integration === null) {
            return response()->json(['data' => []]);
        }
        $limit = max(1, min(200, (int) $request->input('limit', 50)));

        $calls = OperatorCall::where('operator_integration_id', $integration->id)
            ->orderByDesc('created_at')->orderByDesc('id')->limit($limit)
            ->get(['id', 'created_at', 'direction', 'endpoint', 'reference', 'http_status', 'outcome', 'duration_ms', 'error']);

        return response()->json(['data' => $calls]);
    }

    public function movements(Request $request, Tenant $tenant): JsonResponse
    {
        $integration = $this->integrationFor($tenant);
        if ($integration === null) {
            return response()->json(['data' => []]);
        }
        $statuses = array_values(array_intersect(
            explode(',', (string) $request->input('status', implode(',', self::ATTENTION_STATUSES))),
            ['pending', 'ok', 'failed', 'retrying', 'abandoned'],
        ));
        $limit = max(1, min(200, (int) $request->input('limit', 50)));

        $movements = OperatorMovement::query()
            ->leftJoin('users', 'users.id', '=', 'operator_movements.user_id')
            ->where('operator_movements.operator_integration_id', $integration->id)
            ->whereIn('operator_movements.status', $statuses)
            ->orderByDesc('operator_movements.updated_at')->orderByDesc('operator_movements.id')
            ->limit($limit)
            ->get([
                'operator_movements.id', 'operator_movements.created_at', 'operator_movements.updated_at',
                'operator_movements.kind', 'operator_movements.reference', 'operator_movements.debit_reference',
                'operator_movements.amount', 'operator_movements.currency', 'operator_movements.status',
                'operator_movements.error_code', 'operator_movements.attempts', 'operator_movements.next_attempt_at',
                'users.external_player_id',
            ]);

        return response()->json(['data' => $movements]);
    }

    private function integrationFor(Tenant $tenant): ?OperatorIntegration
    {
        return OperatorIntegration::where('tenant_id', $tenant->id)->first();
    }

    /** The integration as the console sees it: never a secret. */
    private function present(OperatorIntegration $integration): array
    {
        $overlapEnds = $integration->previous_secret !== null && $integration->secret_rotated_at !== null
            ? $integration->secret_rotated_at->copy()->addHours(OperatorIntegration::SECRET_OVERLAP_HOURS)
            : null;
        $since = now()->subDay();
        $calls = DB::table('operator_calls')
            ->where('operator_integration_id', $integration->id)
            ->where('created_at', '>=', $since)
            ->selectRaw("SUM(CASE WHEN direction = 'in' THEN 1 ELSE 0 END) AS calls_in")
            ->selectRaw("SUM(CASE WHEN direction = 'out' THEN 1 ELSE 0 END) AS calls_out")
            ->selectRaw('SUM(CASE WHEN outcome IN ('.implode(',', array_fill(0, count(self::REFUSED_OUTCOMES), '?')).') THEN 1 ELSE 0 END) AS refused', self::REFUSED_OUTCOMES)
            ->first();

        return [
            'operator_key' => $integration->operator_key,
            'wallet_mode' => $integration->wallet_mode,
            'wallet_base_url' => $integration->wallet_base_url,
            'allowed_ips' => $integration->allowed_ips ?? [],
            'embed_origins' => $integration->embed_origins ?? [],
            'status' => $integration->status,
            'secret_rotated_at' => $integration->secret_rotated_at?->toIso8601String(),
            'previous_secret_valid_until' => $overlapEnds !== null && $overlapEnds->isFuture() ? $overlapEnds->toIso8601String() : null,
            'created_at' => $integration->created_at?->toIso8601String(),
            'summary' => [
                'operator_players' => User::withoutGlobalScopes()->where('tenant_id', $integration->tenant_id)
                    ->whereNotNull('external_player_id')->whereNull('deleted_at')->count(),
                'calls_in_24h' => (int) ($calls->calls_in ?? 0),
                'calls_out_24h' => (int) ($calls->calls_out ?? 0),
                'refused_24h' => (int) ($calls->refused ?? 0),
                'movements_attention' => OperatorMovement::where('operator_integration_id', $integration->id)
                    ->whereIn('status', self::ATTENTION_STATUSES)->count(),
            ],
        ];
    }

    /** The settings an admin may change, for the audit log (no secrets). */
    private function auditable(OperatorIntegration $integration): array
    {
        return [
            'operator_key' => $integration->operator_key,
            'wallet_mode' => $integration->wallet_mode,
            'wallet_base_url' => $integration->wallet_base_url,
            'allowed_ips' => $integration->allowed_ips,
            'embed_origins' => $integration->embed_origins,
            'status' => $integration->status,
        ];
    }

    private function audit(Request $request, string $event, Tenant $tenant, OperatorIntegration $integration, ?array $old, array $new, string $severity = 'info'): void
    {
        $this->auditService->log($event, $request->user(), $request->user(), [
            'entity_type' => 'operator_integration',
            'entity_id' => $integration->id,
            'old_values' => $old,
            'new_values' => array_merge(['tenant_uuid' => $tenant->uuid], $new),
            'severity' => $severity,
        ]);
    }

    private function rules(): array
    {
        $local = app()->isLocal();

        return [
            'wallet_mode' => ['sometimes', Rule::in([OperatorIntegration::MODE_HOSTED, OperatorIntegration::MODE_SEAMLESS])],
            'wallet_base_url' => ['sometimes', 'nullable', 'string', 'max:500', 'url',
                function (string $attribute, mixed $value, Closure $fail) use ($local) {
                    if (is_string($value) && ! $local && ! str_starts_with($value, 'https://')) {
                        $fail('The wallet URL must be https.');
                    }
                }],
            'allowed_ips' => ['sometimes', 'nullable', 'array', 'max:50'],
            'allowed_ips.*' => ['string', 'max:64', function (string $attribute, mixed $value, Closure $fail) {
                if (! is_string($value) || ! self::isIpOrCidr($value)) {
                    $fail("Not an IP address or CIDR range: {$value}");
                }
            }],
            'embed_origins' => ['sometimes', 'nullable', 'array', 'max:20'],
            'embed_origins.*' => ['string', 'max:255', function (string $attribute, mixed $value, Closure $fail) use ($local) {
                $pattern = $local ? '#^https?://[a-z0-9.-]+(:\d{1,5})?$#i' : '#^https://[a-z0-9.-]+(:\d{1,5})?$#i';
                if (! is_string($value) || ! preg_match($pattern, $value)) {
                    $fail("Not an https origin: {$value} (scheme and host only, no path or trailing slash).");
                }
            }],
            'status' => ['sometimes', Rule::in(['active', 'suspended'])],
        ];
    }

    private static function isIpOrCidr(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return true;
        }
        $parts = explode('/', $value);
        if (count($parts) !== 2 || ! ctype_digit($parts[1])) {
            return false;
        }
        $max = match (true) {
            filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false => 32,
            filter_var($parts[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false => 128,
            default => -1,
        };

        return $max >= 0 && (int) $parts[1] <= $max;
    }

    private function ensureWalletUrl(string $mode, ?string $url): void
    {
        if ($mode === OperatorIntegration::MODE_SEAMLESS && ! $url) {
            throw ValidationException::withMessages(['wallet_base_url' => 'A seamless integration needs the operator wallet URL.']);
        }
    }

    /** @param  array<int, string>|null  $values */
    private function listOrNull(?array $values): ?array
    {
        $values = array_values(array_unique(array_filter(array_map('trim', $values ?? []), fn ($v) => $v !== '')));

        return $values === [] ? null : $values;
    }
}
