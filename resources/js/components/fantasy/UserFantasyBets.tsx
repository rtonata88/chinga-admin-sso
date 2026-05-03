import StatusBadge from '@/components/acumatica/Common/StatusBadge';
import type { StatusVariant } from '@/types/acumatica';
import { Link } from '@inertiajs/react';
import { Column } from 'primereact/column';
import { DataTable } from 'primereact/datatable';
import { useEffect, useState } from 'react';

interface Pick {
    team_id: number;
    team_name: string | null;
    odds: string;
    won: boolean;
}

interface Bet {
    id: number;
    round_id: number;
    round_number: number;
    bet_amount: string;
    winning_amount: string;
    outcome: 'pending' | 'win' | 'lost';
    credit_status: string | null;
    placed_at: string;
    picks: Pick[];
}

function formatCurrency(amount: string | number): string {
    const n = typeof amount === 'string' ? parseFloat(amount) : amount;
    return `NAD ${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function outcomeVariant(outcome: Bet['outcome']): StatusVariant {
    if (outcome === 'win') return 'active';
    if (outcome === 'lost') return 'error';
    return 'pending';
}

interface Props {
    userUuid: string;
}

export function UserFantasyBets({ userUuid }: Props) {
    const [bets, setBets] = useState<Bet[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        let cancelled = false;
        async function load() {
            setLoading(true);
            setError(null);
            try {
                const response = await fetch(`/api/v1/admin/users/${userUuid}/fantasy-bets?limit=50`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                const data = await response.json();
                if (cancelled) return;
                if (response.ok && data.success) {
                    setBets(data.data ?? []);
                } else {
                    setError(data.message || `Failed to load (${response.status})`);
                }
            } catch {
                if (!cancelled) setError('Failed to connect to the server');
            } finally {
                if (!cancelled) setLoading(false);
            }
        }
        void load();
        return () => {
            cancelled = true;
        };
    }, [userUuid]);

    const totalBets = bets.length;
    const wins = bets.filter((b) => b.outcome === 'win').length;
    const totalWagered = bets.reduce((acc, b) => acc + parseFloat(b.bet_amount || '0'), 0);
    const totalWon = bets.filter((b) => b.outcome === 'win').reduce((acc, b) => acc + parseFloat(b.winning_amount || '0'), 0);

    return (
        <div className="acu-fieldset" style={{ '--fieldset-color': 'var(--acu-fieldset-gold)' } as React.CSSProperties}>
            <div className="acu-fieldset-header">
                <div className="acu-fieldset-title">
                    <i className="pi pi-ticket" />
                    <span>Fantasy Bet History</span>
                    {!loading && (
                        <span className="text-xs font-normal ml-1" style={{ color: 'var(--acu-text-light)' }}>
                            ({totalBets} most recent)
                        </span>
                    )}
                </div>
            </div>
            <div className="acu-fieldset-body">
                {error ? (
                    <div className="text-sm" style={{ color: '#F85149' }}>
                        <i className="pi pi-exclamation-triangle mr-2" />
                        {error}
                    </div>
                ) : loading ? (
                    <div className="text-sm" style={{ color: 'var(--acu-text-light)' }}>
                        <i className="pi pi-spin pi-spinner mr-2" />
                        Loading bet history…
                    </div>
                ) : totalBets === 0 ? (
                    <div className="text-sm" style={{ color: 'var(--acu-text-light)' }}>
                        This user hasn't placed any fantasy bets yet.
                    </div>
                ) : (
                    <>
                        <div className="grid grid-cols-2 md:grid-cols-4 gap-3 mb-4">
                            <SummaryStat label="Bets" value={totalBets.toString()} />
                            <SummaryStat label="Wins" value={`${wins}`} />
                            <SummaryStat label="Wagered" value={formatCurrency(totalWagered)} />
                            <SummaryStat label="Won" value={formatCurrency(totalWon)} />
                        </div>
                        <DataTable value={bets} size="small" showGridlines={false} dataKey="id">
                            <Column
                                header="Round"
                                body={(row: Bet) => (
                                    <Link
                                        href={`/fantasy/rounds/${row.round_id}`}
                                        className="text-sm font-medium hover:underline"
                                        style={{ color: 'var(--acu-primary)' }}
                                    >
                                        #{row.round_number}
                                    </Link>
                                )}
                                style={{ width: '5rem' }}
                            />
                            <Column
                                header="Picks"
                                body={(row: Bet) => (
                                    <div className="flex flex-wrap gap-1 max-w-md">
                                        {row.picks.map((p, i) => (
                                            <span
                                                key={i}
                                                className="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs"
                                                style={{
                                                    background: p.won ? 'rgba(63, 185, 80, 0.12)' : 'rgba(248, 81, 73, 0.08)',
                                                    color: p.won ? '#3FB950' : '#F85149',
                                                    border: `1px solid ${p.won ? 'rgba(63,185,80,0.25)' : 'rgba(248,81,73,0.2)'}`,
                                                }}
                                                title={`${p.team_name ?? p.team_id} @ ${p.odds}`}
                                            >
                                                {p.team_name ?? `#${p.team_id}`}
                                                <span style={{ opacity: 0.7 }}>×{parseFloat(p.odds).toFixed(2)}</span>
                                            </span>
                                        ))}
                                    </div>
                                )}
                            />
                            <Column
                                header="Wager"
                                body={(row: Bet) => (
                                    <span className="text-sm" style={{ color: 'var(--acu-text)' }}>
                                        {formatCurrency(row.bet_amount)}
                                    </span>
                                )}
                            />
                            <Column
                                header="Outcome"
                                body={(row: Bet) => <StatusBadge status={outcomeVariant(row.outcome)} label={row.outcome} />}
                                style={{ width: '7rem' }}
                            />
                            <Column
                                header="Payout"
                                body={(row: Bet) => (
                                    <span
                                        className="text-sm font-medium"
                                        style={{ color: row.outcome === 'win' ? '#3FB950' : 'var(--acu-text-light)' }}
                                    >
                                        {row.outcome === 'win' ? formatCurrency(row.winning_amount) : '—'}
                                    </span>
                                )}
                            />
                            <Column
                                header="Placed"
                                body={(row: Bet) => (
                                    <span className="text-xs" style={{ color: 'var(--acu-text-muted)' }}>
                                        {new Date(row.placed_at).toLocaleString()}
                                    </span>
                                )}
                            />
                        </DataTable>
                    </>
                )}
            </div>
        </div>
    );
}

function SummaryStat({ label, value }: { label: string; value: string }) {
    return (
        <div
            className="rounded-lg p-3"
            style={{
                background: 'var(--acu-surface-card)',
                border: '1px solid var(--acu-border)',
            }}
        >
            <div className="text-xs uppercase tracking-wide" style={{ color: 'var(--acu-text-light)' }}>
                {label}
            </div>
            <div className="text-base font-semibold" style={{ color: 'var(--acu-text)' }}>
                {value}
            </div>
        </div>
    );
}
