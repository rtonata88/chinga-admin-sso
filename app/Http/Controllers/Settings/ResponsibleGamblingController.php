<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\ResponsibleGambling\ResponsibleGamblingService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ResponsibleGamblingController extends Controller
{
    public function __construct(
        protected ResponsibleGamblingService $responsibleGamblingService
    ) {}

    /**
     * Display the responsible gambling settings page.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $status = $this->responsibleGamblingService->getStatus($user);
        $history = $this->responsibleGamblingService->getExclusionHistory($user);

        return Inertia::render('settings/responsible-gambling', [
            'status' => $status,
            'exclusion_history' => $history->map(fn ($exclusion) => [
                'id' => $exclusion->id,
                'type' => $exclusion->type,
                'starts_at' => $exclusion->starts_at->toIso8601String(),
                'ends_at' => $exclusion->ends_at?->toIso8601String(),
                'duration_label' => $exclusion->getDurationLabel(),
                'is_active' => $exclusion->isActive(),
                'was_revoked' => $exclusion->isRevoked(),
                'created_at' => $exclusion->created_at->toIso8601String(),
            ]),
            'options' => [
                'reality_check_intervals' => [
                    ['value' => null, 'label' => 'No reminder'],
                    ['value' => 15, 'label' => '15 minutes'],
                    ['value' => 30, 'label' => '30 minutes'],
                    ['value' => 60, 'label' => '1 hour'],
                    ['value' => 120, 'label' => '2 hours'],
                ],
                'session_time_limits' => [
                    ['value' => null, 'label' => 'No limit'],
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
                    ['value' => 'permanent', 'label' => 'Permanent (cannot be undone)'],
                ],
            ],
        ]);
    }
}
