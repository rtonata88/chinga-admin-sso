<?php

namespace App\Http\Middleware;

use App\Services\ResponsibleGambling\ResponsibleGamblingService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSelfExclusion
{
    public function __construct(
        protected ResponsibleGamblingService $responsibleGamblingService
    ) {}

    /**
     * Handle an incoming request.
     *
     * Check if the user has an active self-exclusion and block access to gaming features.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $exclusion = $this->responsibleGamblingService->getActiveExclusion($user);

        if ($exclusion) {
            // For API requests, return JSON response
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Access denied due to active self-exclusion.',
                    'exclusion' => [
                        'type' => $exclusion->type,
                        'ends_at' => $exclusion->ends_at?->toIso8601String(),
                        'remaining_days' => $exclusion->getRemainingDays(),
                        'duration_label' => $exclusion->getDurationLabel(),
                    ],
                ], 403);
            }

            // For web requests, redirect to exclusion notice page
            return redirect()->route('self-exclusion.notice')
                ->with('exclusion', [
                    'type' => $exclusion->type,
                    'ends_at' => $exclusion->ends_at?->toIso8601String(),
                    'remaining_days' => $exclusion->getRemainingDays(),
                ]);
        }

        return $next($request);
    }
}
