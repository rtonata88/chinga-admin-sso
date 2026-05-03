import PageHeader from '@/components/acumatica/Common/PageHeader';
import StatusBadge from '@/components/acumatica/Common/StatusBadge';
import UserLayout from '@/layouts/user-layout';
import type { StatusVariant } from '@/types/acumatica';
import { Head, Link } from '@inertiajs/react';
import { Button } from 'primereact/button';
import { Column } from 'primereact/column';
import { DataTable } from 'primereact/datatable';

interface Pick {
    team_id: number;
    team_name: string | null;
    odds: string;
    won: boolean;
}

interface Bet {
    id: number;
    user_uuid: string;
    tenant_uuid: string | null;
    bet_amount: string;
    winning_amount: string;
    outcome: 'pending' | 'win' | 'lost';
    credit_status: string | null;
    placed_at: string;
    picks: Pick[];
}

interface Round {
    id: number;
    round_number: number;
    tenant_uuid: string | null;
    start_time: string;
    end_time: string | null;
    winning_team_ids: number[] | null;
    winning_teams: { id: number; name: string }[];
    bonus_teams: { id: number; name: string }[];
    bet_count: number;
    total_wagered: string;
    total_paid_out: string;
}

interface Props {
    round: Round | null;
    bets: Bet[];
    error: string | null;
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

export default function RoundDetail({ round, bets = [], error }: Props) {
    if (error || !round) {
        return (
            <UserLayout title="Round Detail">
                <Head title="Round Detail" />
                <div className="space-y-6">
                    <Link href="/fantasy/rounds">
                        <Button label="Back to rounds" icon="pi pi-arrow-left" size="small" text severity="secondary" />
                    </Link>
                    <div
                        className="rounded-xl px-5 py-4 text-sm"
                        style={{
                            background: 'rgba(248, 81, 73, 0.04)',
                            border: '1px solid rgba(248, 81, 73, 0.15)',
                            color: 'var(--acu-text)',
                        }}
                    >
                        <i className="pi pi-exclamation-triangle mr-2" style={{ color: '#F85149' }} />
                        {error ?? 'Round not found.'}
                    </div>
                </div>
            </UserLayout>
        );
    }

    const ggr = parseFloat(round.total_wagered) - parseFloat(round.total_paid_out);
    const bonusTeamIds = new Set(round.bonus_teams.map((t) => t.id));

    return (
        <UserLayout title={`Round #${round.round_number}`}>
            <Head title={`Round #${round.round_number}`} />

            <div className="space-y-6">
                <div>
                    <Link href="/fantasy/rounds">
                        <Button label="Back to rounds" icon="pi pi-arrow-left" size="small" text severity="secondary" />
                    </Link>
                </div>

                <PageHeader
                    title={`Round #${round.round_number}`}
                    subtitle={`Started ${new Date(round.start_time).toLocaleString()}${
                        round.end_time ? ` · Ended ${new Date(round.end_time).toLocaleString()}` : ''
                    }`}
                />

                {/* Aggregate */}
                <div className="grid gap-5 md:grid-cols-2 lg:grid-cols-4">
                    <Stat label="Bets" value={round.bet_count.toLocaleString()} />
                    <Stat label="Wagered" value={formatCurrency(round.total_wagered)} />
                    <Stat label="Paid Out" value={formatCurrency(round.total_paid_out)} />
                    <Stat label="GGR" value={formatCurrency(ggr)} />
                </div>

                {/* Winning teams */}
                <div className="acu-fieldset" style={{ '--fieldset-color': 'var(--acu-fieldset-gold)' } as React.CSSProperties}>
                    <div className="acu-fieldset-header">
                        <div className="acu-fieldset-title">
                            <i className="pi pi-trophy" />
                            <span>Winning Teams</span>
                            <span className="text-xs font-normal ml-1" style={{ color: 'var(--acu-text-light)' }}>
                                ({round.winning_teams.length})
                            </span>
                        </div>
                    </div>
                    <div className="acu-fieldset-body">
                        {round.winning_teams.length === 0 ? (
                            <span className="text-sm" style={{ color: 'var(--acu-text-light)' }}>
                                No winners recorded yet.
                            </span>
                        ) : (
                            <div className="flex flex-wrap gap-2">
                                {round.winning_teams.map((t) => (
                                    <span
                                        key={t.id}
                                        className="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-medium"
                                        style={{
                                            background: bonusTeamIds.has(t.id) ? 'rgba(201, 168, 76, 0.15)' : 'rgba(63, 185, 80, 0.12)',
                                            color: bonusTeamIds.has(t.id) ? '#C9A84C' : '#3FB950',
                                            border: `1px solid ${bonusTeamIds.has(t.id) ? 'rgba(201,168,76,0.3)' : 'rgba(63,185,80,0.25)'}`,
                                        }}
                                    >
                                        {bonusTeamIds.has(t.id) && <i className="pi pi-star-fill" style={{ fontSize: '0.65rem' }} />}
                                        {t.name}
                                    </span>
                                ))}
                            </div>
                        )}
                        {round.bonus_teams.length > 0 && (
                            <div className="text-xs mt-2" style={{ color: 'var(--acu-text-light)' }}>
                                <i className="pi pi-star-fill mr-1" style={{ color: '#C9A84C', fontSize: '0.7rem' }} />
                                Chinga Bonus team highlighted
                            </div>
                        )}
                    </div>
                </div>

                {/* Bets in this round */}
                <div className="acu-fieldset" style={{ '--fieldset-color': 'var(--acu-fieldset-blue)' } as React.CSSProperties}>
                    <div className="acu-fieldset-header">
                        <div className="acu-fieldset-title">
                            <i className="pi pi-ticket" />
                            <span>Bets</span>
                            <span className="text-xs font-normal ml-1" style={{ color: 'var(--acu-text-light)' }}>
                                ({bets.length})
                            </span>
                        </div>
                    </div>
                    <div className="acu-fieldset-body p-0">
                        <DataTable
                            value={bets}
                            size="small"
                            showGridlines={false}
                            emptyMessage="No bets in this round"
                            dataKey="id"
                        >
                            <Column
                                header="Player"
                                body={(row: Bet) => (
                                    <span
                                        className="text-xs font-mono"
                                        style={{ color: 'var(--acu-text-muted)' }}
                                        title={row.user_uuid}
                                    >
                                        {row.user_uuid.slice(0, 8)}…
                                    </span>
                                )}
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
                                header="Credit"
                                body={(row: Bet) =>
                                    row.outcome === 'win' && row.credit_status ? (
                                        <StatusBadge
                                            status={row.credit_status === 'paid' ? 'active' : 'error'}
                                            label={row.credit_status}
                                        />
                                    ) : (
                                        <span style={{ color: 'var(--acu-text-light)' }}>—</span>
                                    )
                                }
                                style={{ width: '6rem' }}
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
                    </div>
                </div>
            </div>
        </UserLayout>
    );
}

function Stat({ label, value }: { label: string; value: string }) {
    return (
        <div
            className="rounded-xl p-4"
            style={{
                background: 'var(--acu-surface-card)',
                border: '1px solid var(--acu-border)',
            }}
        >
            <div className="text-xs uppercase tracking-wide mb-1" style={{ color: 'var(--acu-text-light)' }}>
                {label}
            </div>
            <div className="text-xl font-semibold" style={{ color: 'var(--acu-text)' }}>
                {value}
            </div>
        </div>
    );
}
