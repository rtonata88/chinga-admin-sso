<?php

namespace App\Http\Controllers;

use App\Models\KycDocument;
use App\Models\LoginNotification;
use App\Models\UserSession;
use Inertia\Inertia;
use Inertia\Response;

class UserDashboardController extends Controller
{
    public function __invoke(): Response
    {
        $user = auth()->user();

        // Account overview
        $account = [
            'name' => $user->name,
            'email' => $user->email,
            'display_name' => $user->display_name,
            'avatar_url' => $user->avatar_url,
            'status' => $user->status ?? 'active',
            'kyc_level' => $user->kyc_level ?? 0,
            'email_verified' => $user->email_verified_at !== null,
            'phone_verified' => $user->phone_verified_at !== null,
            'two_factor_enabled' => $user->two_factor_enabled ?? $user->sms_mfa_enabled ?? false,
            'member_since' => $user->created_at->toIso8601String(),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
        ];

        // KYC documents
        $kycDocuments = KycDocument::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get()
            ->map(fn ($doc) => [
                'uuid' => $doc->uuid,
                'document_type' => $doc->document_type,
                'status' => $doc->status,
                'rejection_reason' => $doc->rejection_reason,
                'created_at' => $doc->created_at->toIso8601String(),
                'verified_at' => $doc->verified_at?->toIso8601String(),
            ]);

        // Recent sessions
        $sessions = UserSession::where('user_id', $user->id)
            ->orderBy('last_active_at', 'desc')
            ->limit(5)
            ->get()
            ->map(fn ($session) => [
                'device_type' => $session->device_type,
                'browser' => $session->browser,
                'platform' => $session->platform,
                'ip_address' => $session->ip_address,
                'city' => $session->city,
                'country_code' => $session->country_code,
                'is_current' => $session->is_current,
                'last_active_at' => $session->last_active_at?->toIso8601String(),
            ]);

        // Recent login notifications (new devices/locations)
        $loginAlerts = LoginNotification::where('user_id', $user->id)
            ->where(function ($q) {
                $q->where('is_new_device', true)
                  ->orWhere('is_new_location', true);
            })
            ->orderBy('created_at', 'desc')
            ->limit(3)
            ->get()
            ->map(fn ($n) => [
                'device_type' => $n->device_type,
                'browser' => $n->browser,
                'platform' => $n->platform,
                'city' => $n->city,
                'country_code' => $n->country_code,
                'is_new_device' => $n->is_new_device,
                'is_new_location' => $n->is_new_location,
                'created_at' => $n->created_at->toIso8601String(),
            ]);

        // Active sessions count
        $activeSessionCount = UserSession::where('user_id', $user->id)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })
            ->count();

        return Inertia::render('dashboard', [
            'account' => $account,
            'kyc_documents' => $kycDocuments,
            'sessions' => $sessions,
            'login_alerts' => $loginAlerts,
            'active_session_count' => $activeSessionCount,
        ]);
    }
}
