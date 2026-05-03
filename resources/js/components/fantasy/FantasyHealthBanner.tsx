import { useEffect, useState } from 'react';

interface HealthStatus {
    status: 'ok' | 'down' | 'degraded' | string;
    db?: string;
    message?: string;
    timestamp?: string;
}

const POLL_INTERVAL_MS = 30_000;

/**
 * Polls /api/v1/admin/fantasy-health every 30 seconds and renders a small
 * red banner above the page when the chinga-fantasy backend is unreachable
 * or degraded. Stays out of the way when everything is fine. Silently
 * swallows network errors — the banner itself is the signal.
 */
export function FantasyHealthBanner() {
    const [health, setHealth] = useState<HealthStatus | null>(null);

    useEffect(() => {
        let cancelled = false;

        async function check() {
            try {
                const response = await fetch('/api/v1/admin/fantasy-health', {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                if (!response.ok) return;
                const body = await response.json();
                if (!cancelled) setHealth(body.data ?? null);
            } catch {
                // Network failure to SSO itself — leave the banner state alone.
            }
        }

        void check();
        const id = setInterval(check, POLL_INTERVAL_MS);
        return () => {
            cancelled = true;
            clearInterval(id);
        };
    }, []);

    if (!health || health.status === 'ok') return null;

    const isDown = health.status === 'down';
    const accent = isDown ? '#F85149' : '#D29922';
    const label = isDown ? 'Chinga Fantasy backend unreachable' : 'Chinga Fantasy backend degraded';
    const detail =
        health.message ??
        (health.db === 'error'
            ? 'Database connection failed.'
            : 'Some fantasy features may not work until the backend is restored.');

    return (
        <div
            className="rounded-lg px-4 py-2.5 mb-4 flex items-center gap-3 text-sm"
            style={{
                background: `${accent}10`,
                border: `1px solid ${accent}40`,
                color: 'var(--acu-text)',
            }}
        >
            <i className="pi pi-exclamation-triangle" style={{ color: accent, fontSize: '0.95rem' }} />
            <div className="flex-1">
                <span className="font-semibold mr-2" style={{ color: accent }}>
                    {label}
                </span>
                <span style={{ color: 'var(--acu-text-light)' }}>{detail}</span>
            </div>
            <code
                className="text-xs px-2 py-0.5 rounded"
                style={{
                    background: 'var(--acu-surface-card)',
                    border: '1px solid var(--acu-border)',
                    color: 'var(--acu-text-muted)',
                }}
                title="Run on the backend host"
            >
                npm run devStart
            </code>
        </div>
    );
}
