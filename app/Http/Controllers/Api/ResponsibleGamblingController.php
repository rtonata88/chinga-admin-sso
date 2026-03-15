<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ResponsibleGambling\ResponsibleGamblingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ResponsibleGamblingController extends Controller
{
    public function __construct(
        protected ResponsibleGamblingService $responsibleGamblingService
    ) {}

    /**
     * Get current responsible gambling status.
     */
    public function status(Request $request): JsonResponse
    {
        $status = $this->responsibleGamblingService->getStatus($request->user());

        return response()->json([
            'success' => true,
            'data' => $status,
        ]);
    }

    /**
     * Update deposit limits.
     */
    public function updateDepositLimits(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'daily' => ['nullable', 'numeric', 'min:0'],
            'weekly' => ['nullable', 'numeric', 'min:0'],
            'monthly' => ['nullable', 'numeric', 'min:0'],
        ]);

        // Validate weekly >= daily and monthly >= weekly
        if (isset($validated['daily']) && isset($validated['weekly'])) {
            if ($validated['weekly'] < $validated['daily']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Weekly limit must be greater than or equal to daily limit.',
                ], 422);
            }
        }

        if (isset($validated['weekly']) && isset($validated['monthly'])) {
            if ($validated['monthly'] < $validated['weekly']) {
                return response()->json([
                    'success' => false,
                    'message' => 'Monthly limit must be greater than or equal to weekly limit.',
                ], 422);
            }
        }

        $settings = $this->responsibleGamblingService->updateDepositLimits(
            $request->user(),
            $validated
        );

        return response()->json([
            'success' => true,
            'message' => $settings->hasPendingLimits()
                ? 'Limit increases will take effect after 24 hours.'
                : 'Deposit limits updated successfully.',
            'data' => $this->responsibleGamblingService->getStatus($request->user()),
        ]);
    }

    /**
     * Update session limits.
     */
    public function updateSessionLimits(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'time_limit_minutes' => ['nullable', 'integer', 'min:15', 'max:1440'],
            'loss_limit' => ['nullable', 'numeric', 'min:0'],
        ]);

        $this->responsibleGamblingService->updateSessionLimits(
            $request->user(),
            $validated
        );

        return response()->json([
            'success' => true,
            'message' => 'Session limits updated successfully.',
            'data' => $this->responsibleGamblingService->getStatus($request->user()),
        ]);
    }

    /**
     * Update reality check interval.
     */
    public function updateRealityCheck(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'interval_minutes' => ['nullable', 'integer', Rule::in([15, 30, 60, 120])],
        ]);

        $this->responsibleGamblingService->updateRealityCheck(
            $request->user(),
            $validated['interval_minutes'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'Reality check settings updated successfully.',
            'data' => $this->responsibleGamblingService->getStatus($request->user()),
        ]);
    }

    /**
     * Update login time restrictions.
     */
    public function updateLoginRestrictions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'start' => ['nullable', 'date_format:H:i', 'required_with:end'],
            'end' => ['nullable', 'date_format:H:i', 'required_with:start'],
        ]);

        $this->responsibleGamblingService->updateLoginTimeRestrictions(
            $request->user(),
            $validated['start'] ?? null,
            $validated['end'] ?? null
        );

        return response()->json([
            'success' => true,
            'message' => 'Login time restrictions updated successfully.',
            'data' => $this->responsibleGamblingService->getStatus($request->user()),
        ]);
    }

    /**
     * Cancel pending limit increases.
     */
    public function cancelPendingLimits(Request $request): JsonResponse
    {
        $this->responsibleGamblingService->cancelPendingLimits($request->user());

        return response()->json([
            'success' => true,
            'message' => 'Pending limit increases cancelled.',
            'data' => $this->responsibleGamblingService->getStatus($request->user()),
        ]);
    }

    /**
     * Create a self-exclusion.
     */
    public function selfExclude(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'duration' => ['required', Rule::in(['24h', '7d', '30d', '90d', 'permanent'])],
            'reason' => ['nullable', 'string', 'max:1000'],
            'confirm' => ['required', 'accepted'],
        ]);

        try {
            $exclusion = $this->responsibleGamblingService->createSelfExclusion(
                $request->user(),
                $validated['duration'],
                $validated['reason'] ?? null
            );

            return response()->json([
                'success' => true,
                'message' => 'Self-exclusion has been activated.',
                'data' => [
                    'type' => $exclusion->type,
                    'starts_at' => $exclusion->starts_at->toIso8601String(),
                    'ends_at' => $exclusion->ends_at?->toIso8601String(),
                    'duration_label' => $exclusion->getDurationLabel(),
                ],
            ]);
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Get self-exclusion status.
     */
    public function selfExclusionStatus(Request $request): JsonResponse
    {
        $exclusion = $this->responsibleGamblingService->getActiveExclusion($request->user());

        return response()->json([
            'success' => true,
            'data' => [
                'is_excluded' => $exclusion !== null,
                'exclusion' => $exclusion ? [
                    'type' => $exclusion->type,
                    'starts_at' => $exclusion->starts_at->toIso8601String(),
                    'ends_at' => $exclusion->ends_at?->toIso8601String(),
                    'remaining_days' => $exclusion->getRemainingDays(),
                    'duration_label' => $exclusion->getDurationLabel(),
                    'reason' => $exclusion->reason,
                ] : null,
            ],
        ]);
    }

    /**
     * Get self-exclusion history.
     */
    public function selfExclusionHistory(Request $request): JsonResponse
    {
        $history = $this->responsibleGamblingService->getExclusionHistory($request->user());

        return response()->json([
            'success' => true,
            'data' => $history->map(fn ($exclusion) => [
                'id' => $exclusion->id,
                'type' => $exclusion->type,
                'starts_at' => $exclusion->starts_at->toIso8601String(),
                'ends_at' => $exclusion->ends_at?->toIso8601String(),
                'duration_label' => $exclusion->getDurationLabel(),
                'is_active' => $exclusion->isActive(),
                'was_revoked' => $exclusion->isRevoked(),
                'created_at' => $exclusion->created_at->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Get available options for settings.
     */
    public function options(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => [
                'reality_check_intervals' => [
                    ['value' => 15, 'label' => '15 minutes'],
                    ['value' => 30, 'label' => '30 minutes'],
                    ['value' => 60, 'label' => '1 hour'],
                    ['value' => 120, 'label' => '2 hours'],
                ],
                'session_time_limits' => [
                    ['value' => 30, 'label' => '30 minutes'],
                    ['value' => 60, 'label' => '1 hour'],
                    ['value' => 120, 'label' => '2 hours'],
                    ['value' => 240, 'label' => '4 hours'],
                    ['value' => 480, 'label' => '8 hours'],
                ],
                'self_exclusion_durations' => [
                    ['value' => '24h', 'label' => '24 hours'],
                    ['value' => '7d', 'label' => '7 days'],
                    ['value' => '30d', 'label' => '30 days'],
                    ['value' => '90d', 'label' => '90 days'],
                    ['value' => 'permanent', 'label' => 'Permanent'],
                ],
            ],
        ]);
    }
}
