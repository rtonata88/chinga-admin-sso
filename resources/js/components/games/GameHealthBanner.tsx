import { useEffect, useState } from 'react';

interface GameHealth {
    game_uuid: string;
    name: string;
    slug: string;
    status: 'ok' | 'down' | 'degraded' | string;
    db?: string | null;
    message?: string | null;
}

const POLL_INTERVAL_MS = 30_000;

/**
 * Polls /api/v1/admin/games-health every 30 seconds and renders one small
 * banner per game backend that is unreachable or degraded. Stays out of
 * the way when everything is fine. Silently swallows network errors — the
 * banner itself is the signal.
 */
export function GameHealthBanner() {
    const [games, setGames] = useState<GameHealth[]>([]);

    useEffect(() => {
        let cancelled = false;

        async function check() {
            try {
                const response = await fetch('/api/v1/admin/games-health', {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                if (!response.ok) return;
                const body = await response.json();
                if (!cancelled) setGames(Array.isArray(body.data) ? body.data : []);
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

    const unhealthy = games.filter((g) => g.status !== 'ok');
    if (unhealthy.length === 0) return null;

    return (
        <>
            {unhealthy.map((game) => {
                const isDown = game.status === 'down';
                const accent = isDown ? '#F85149' : '#D29922';
                const label = isDown ? `${game.name} backend unreachable` : `${game.name} backend degraded`;
                const detail =
                    game.message ??
                    (game.db === 'error'
                        ? 'Database connection failed.'
                        : `Some ${game.name} features may not work until the backend is restored.`);

                return (
                    <div
                        key={game.game_uuid}
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
                            title="Game slug"
                        >
                            {game.slug}
                        </code>
                    </div>
                );
            })}
        </>
    );
}
