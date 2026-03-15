<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Auth\SecurityAuditService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SecurityLogController extends Controller
{
    public function __construct(
        protected SecurityAuditService $auditService
    ) {}

    /**
     * Display security audit log.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();
        $logs = $this->auditService->getLogsForUser($user, 100);

        return Inertia::render('settings/security/audit-log', [
            'logs' => $logs->map(fn ($log) => [
                'id' => $log->id,
                'event_type' => $log->event_type,
                'severity' => $log->severity,
                'ip_address' => $log->ip_address,
                'location' => $log->city && $log->country_code
                    ? "{$log->city}, {$log->country_code}"
                    : null,
                'metadata' => $log->metadata,
                'created_at' => $log->created_at->toIso8601String(),
            ]),
            'event_types' => [
                'login' => 'Successful login',
                'login_failed' => 'Failed login attempt',
                'logout' => 'Logout',
                'password_changed' => 'Password changed',
                'password_reset' => 'Password reset',
                'email_changed' => 'Email changed',
                'phone_changed' => 'Phone changed',
                'mfa_enabled' => 'MFA enabled',
                'mfa_disabled' => 'MFA disabled',
                'account_locked' => 'Account locked',
                'account_unlocked' => 'Account unlocked',
                'session_revoked' => 'Session revoked',
                'new_device' => 'New device login',
                'new_location' => 'New location login',
            ],
        ]);
    }
}
