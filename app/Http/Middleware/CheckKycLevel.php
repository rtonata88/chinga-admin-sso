<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckKycLevel
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  int  $level  Minimum required KYC level
     */
    public function handle(Request $request, Closure $next, int $level = 1): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        if ($user->kyc_level < $level) {
            $levelNames = [
                0 => 'unverified',
                1 => 'basic',
                2 => 'enhanced',
                3 => 'full',
            ];

            return response()->json([
                'message' => 'KYC verification required.',
                'error' => 'kyc_required',
                'required_level' => $level,
                'required_level_name' => $levelNames[$level] ?? 'unknown',
                'current_level' => $user->kyc_level,
                'current_level_name' => $levelNames[$user->kyc_level] ?? 'unknown',
                'verification_url' => route('kyc.status'),
            ], 403);
        }

        return $next($request);
    }
}
