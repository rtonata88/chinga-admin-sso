// resources/js/pages/vrrr-pha/round-detail.tsx
//
// One Vrrr Pha round: its figures, the seed audit (PRD §8.3) and every
// bet on it. Seeds and the crash point are only present once the round
// has crashed; the engine withholds them before that, and so does this
// page. The audit result comes from the engine recomputing the crash
// point from the revealed seeds — it is not recomputed in the browser.

import { KpiCard, formatCount, formatCurrencyCompact } from '@/components/operator/kpi-card';
import UserLayout from '@/layouts/user-layout';
import { Head, Link } from '@inertiajs/react';

import {
    ERROR_BOX, PANEL, formatDateTime, formatMultiplier, formatNAD, formatRatioPct, num, outcomePill,
    shortUuid, statePill, tenantLabel,
} from './format';

interface Round {
    id: number;
    sequence: number;
    tenant_uuid: string;
    state: string;
    commitment: string;
    crash_point?: string;
    server_seed?: string;
    client_seed?: string;
    nonce?: number;
    house_edge: number | string;
    max_multiplier: number | string;
    growth_rate_k: number | string;
    opened_at: string;
    pulling_at: string | null;
    crashed_at: string | null;
    settled_at: string | null;
    bet_count: number;
    total_wagered: string;
    total_paid_out: string;
    max_exposure: string;
}

interface Bet {
    id: number;
    round_id: number;
    sequence: number;
    tenant_uuid: string;
    user_uuid: string;
    stake: string;
    amount_from_deposit: string | null;
    auto_cashout_target: string | null;
    cashout_multiplier: string | null;
    payout: string | null;
    outcome: string;
    credit_status: string | null;
    placed_by: string | null;
    placed_at: string;
    cancelled_at: string | null;
    cashout_trigger: string | null;
    latency_ms: number | null;
}

interface Verify {
    round_id?: number;
    commitment?: string;
    crash_point?: string;
    recomputed?: { commitment: string; crash_point: string };
    matches?: boolean;
    error?: string;
}

interface Props {
    round: Round | null;
    bets: Bet[];
    verify: Verify | null;
    tenantNames: Record<string, string>;
    error: string | null;
    backHref?: string;
}

function Field({ label, value, mono, wrap }: { label: string; value: string; mono?: boolean; wrap?: boolean }) {
    return (
        <div style={{ display: 'grid', gridTemplateColumns: '150px 1fr', gap: 12, padding: '7px 0', borderBottom: '1px solid var(--cg-rule)' }}>
            <span style={{ color: 'var(--cg-fg-3)', fontSize: 12, letterSpacing: '.04em', textTransform: 'uppercase' }}>{label}</span>
            <span style={{
                fontFamily: mono ? 'var(--cg-mono)' : undefined,
                fontSize: 13,
                color: 'var(--cg-fg-1)',
                wordBreak: wrap ? 'break-all' : undefined,
            }}>
                {value}
            </span>
        </div>
    );
}

export default function RoundDetail({ round, bets = [], verify, tenantNames = {}, error, backHref = '/vrrr-pha/rounds' }: Props) {
    const back = (
        <Link href={backHref} className="cg-btn cg-btn--text cg-btn--sm" style={{ marginBottom: 12, display: 'inline-block' }}>
            ‹ Back to rounds
        </Link>
    );

    if (error || !round) {
        return (
            <UserLayout title="Vrrr Pha round">
                <Head title="Vrrr Pha round · Admin" />
                <div className="cgo-page">
                    {back}
                    <div style={ERROR_BOX}>
                        <strong style={{ color: 'var(--cg-neg)' }}>Error:</strong> {error ?? 'Round not found.'}
                    </div>
                </div>
            </UserLayout>
        );
    }

    const state = statePill(round.state);
    const revealed = round.crash_point !== undefined;
    const wagered = num(round.total_wagered);
    const paidOut = num(round.total_paid_out);
    const ggr = wagered - paidOut;
    const title = `Round #${round.sequence}`;

    return (
        <UserLayout title={title}>
            <Head title={`${title} · Vrrr Pha`} />
            <div className="cgo-page">
                {back}
                <div className="cgo-page-head">
                    <div>
                        <div className="cgo-eyebrow">Vrrr Pha · {tenantLabel(round.tenant_uuid, tenantNames)}</div>
                        <h1 className="cgo-title" style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                            {title}
                            <span className={`cgo-pill ${state.pill}`}>{state.label}</span>
                        </h1>
                        <div className="cgo-subtitle">
                            Opened {formatDateTime(round.opened_at)}
                            {round.pulling_at ? ` · Pulling ${formatDateTime(round.pulling_at)}` : ''}
                            {round.crashed_at ? ` · Crashed ${formatDateTime(round.crashed_at)}` : ''}
                            {round.settled_at ? ` · Settled ${formatDateTime(round.settled_at)}` : ''}
                        </div>
                    </div>
                </div>

                <div className="cgo-kpis cgo-kpis--5">
                    <KpiCard
                        label="Crash point"
                        value={revealed ? formatMultiplier(round.crash_point) : 'in play'}
                        brass={revealed}
                        meta={revealed ? 'server-drawn, sealed at open' : 'hidden until the round crashes'}
                    />
                    <KpiCard label="Bets" value={formatCount(round.bet_count)} meta="excluding cancelled" />
                    <KpiCard label="Wagered" value={formatCurrencyCompact(wagered)} meta="staked" />
                    <KpiCard label="Paid out" value={formatCurrencyCompact(paidOut)} meta="to cashed-out bets" />
                    <KpiCard label="GGR" value={formatCurrencyCompact(ggr)} meta={`max exposure ${formatNAD(round.max_exposure)}`} />
                </div>

                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(360px, 1fr))', gap: 16, marginBottom: 18 }}>
                    {/* Seed audit */}
                    <section style={PANEL} data-testid="seed-audit">
                        <div className="cgo-eyebrow" style={{ marginBottom: 8 }}>Seed audit</div>
                        <Field label="Commitment" value={round.commitment} mono wrap />
                        {revealed ? (
                            <>
                                <Field label="Server seed" value={round.server_seed ?? '—'} mono wrap />
                                <Field label="Client seed" value={round.client_seed ?? '—'} mono wrap />
                                <Field label="Nonce" value={String(round.nonce ?? '—')} mono />
                            </>
                        ) : (
                            <div style={{ padding: '10px 0', fontSize: 13, color: 'var(--cg-fg-3)' }}>
                                The server seed and crash point are revealed when the round crashes. Only the commitment is public until then.
                            </div>
                        )}
                        <Field label="House edge" value={formatRatioPct(round.house_edge)} mono />
                        <Field label="Max multiplier" value={formatMultiplier(round.max_multiplier)} mono />
                        <Field label="Growth rate k" value={String(round.growth_rate_k)} mono />

                        {verify && (
                            <div
                                data-testid="verify-result"
                                style={{
                                    marginTop: 14,
                                    padding: 12,
                                    borderRadius: 6,
                                    border: `1px solid ${verify.error ? 'var(--cg-warn)' : verify.matches ? 'var(--cg-pos)' : 'var(--cg-neg)'}`,
                                    background: 'var(--cg-ink-elevated)',
                                }}
                            >
                                {verify.error ? (
                                    <div style={{ color: 'var(--cg-warn)', fontSize: 13 }}>{verify.error}</div>
                                ) : (
                                    <>
                                        <div style={{ fontFamily: 'var(--cg-display)', fontSize: 16, color: verify.matches ? 'var(--cg-pos)' : 'var(--cg-neg)' }}>
                                            {verify.matches ? '✓ Seeds verify' : '✗ Seeds do not verify'}
                                        </div>
                                        <div style={{ fontSize: 12, color: 'var(--cg-fg-2)', marginTop: 6, fontFamily: 'var(--cg-mono)' }}>
                                            recomputed crash {formatMultiplier(verify.recomputed?.crash_point)} · stored {formatMultiplier(verify.crash_point)}
                                        </div>
                                        <div style={{ fontSize: 12, color: 'var(--cg-fg-3)', marginTop: 4, fontFamily: 'var(--cg-mono)', wordBreak: 'break-all' }}>
                                            recomputed commitment {verify.recomputed?.commitment ?? '—'}
                                        </div>
                                        {!verify.matches && (
                                            <div style={{ fontSize: 12, color: 'var(--cg-neg)', marginTop: 8 }}>
                                                The stored seeds do not reproduce this round's commitment or crash point. Treat the round as suspect and escalate.
                                            </div>
                                        )}
                                    </>
                                )}
                            </div>
                        )}
                    </section>

                    {/* Timeline */}
                    <section style={PANEL}>
                        <div className="cgo-eyebrow" style={{ marginBottom: 8 }}>Timeline</div>
                        <Field label="Round id" value={String(round.id)} mono />
                        <Field label="Tenant" value={`${tenantLabel(round.tenant_uuid, tenantNames)} · ${round.tenant_uuid}`} mono wrap />
                        <Field label="Opened" value={formatDateTime(round.opened_at)} />
                        <Field label="Pulling" value={formatDateTime(round.pulling_at)} />
                        <Field label="Crashed" value={formatDateTime(round.crashed_at)} />
                        <Field label="Settled" value={formatDateTime(round.settled_at)} />
                        <Field label="Max exposure" value={`NAD ${formatNAD(round.max_exposure)}`} mono />
                    </section>
                </div>

                {/* Bets */}
                <div className="cgo-table-wrap cgo-table-wrap--scroll">
                    <table className="cgo-wagers">
                        <thead>
                            <tr>
                                <th style={{ width: 80 }}>Bet</th>
                                <th style={{ width: 110 }}>Player</th>
                                <th style={{ width: 170 }}>Placed</th>
                                <th className="cgo-r" style={{ width: 110 }}>Stake</th>
                                <th className="cgo-r" style={{ width: 90 }}>Auto</th>
                                <th className="cgo-r" style={{ width: 100 }}>Cashed at</th>
                                <th className="cgo-r" style={{ width: 120 }}>Payout</th>
                                <th style={{ width: 110 }}>Outcome</th>
                                <th style={{ width: 90 }}>Placed by</th>
                                <th style={{ width: 90 }}>Trigger</th>
                                <th className="cgo-r" style={{ width: 80 }}>Latency</th>
                                <th style={{ width: 90 }}>Credit</th>
                            </tr>
                        </thead>
                        <tbody>
                            {bets.length === 0 ? (
                                <tr>
                                    <td colSpan={12} style={{ textAlign: 'center', color: 'var(--cg-fg-3)', padding: '32px 0' }}>
                                        No bets on this round.
                                    </td>
                                </tr>
                            ) : (
                                bets.map((b) => {
                                    const o = outcomePill(b.outcome);
                                    return (
                                        <tr key={b.id} className={b.credit_status === 'failed' ? 'flagged' : undefined}>
                                            <td><span className="cgo-name" style={{ fontFamily: 'var(--cg-mono)' }}>#{b.id}</span></td>
                                            <td><span className="cgo-uid" title={b.user_uuid}>{shortUuid(b.user_uuid)}</span></td>
                                            <td><span className="cgo-uid">{formatDateTime(b.placed_at)}</span></td>
                                            <td className="cgo-r">
                                                <span className="cgo-stake"><span className="cgo-ccy">NAD</span>{formatNAD(b.stake)}</span>
                                            </td>
                                            <td className="cgo-r"><span className="cgo-odds">{formatMultiplier(b.auto_cashout_target)}</span></td>
                                            <td className="cgo-r"><span className="cgo-odds">{formatMultiplier(b.cashout_multiplier)}</span></td>
                                            <td className="cgo-r">
                                                <span className="cgo-payout">{b.payout === null ? '—' : formatNAD(b.payout)}</span>
                                            </td>
                                            <td><span className={`cgo-pill ${o.pill}`}>{o.label}</span></td>
                                            <td><span className="cgo-uid">{b.placed_by ?? '—'}</span></td>
                                            <td><span className="cgo-uid">{b.cashout_trigger ?? '—'}</span></td>
                                            <td className="cgo-r"><span className="cgo-odds">{b.latency_ms === null ? '—' : `${b.latency_ms} ms`}</span></td>
                                            <td>
                                                <span className="cgo-uid" style={{ color: b.credit_status === 'failed' ? 'var(--cg-neg)' : undefined }}>
                                                    {b.credit_status ?? '—'}
                                                </span>
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                    <div className="cgo-table-foot">
                        <span>
                            <b className="cgo-mono">{bets.length}</b> bets including cancelled
                        </span>
                    </div>
                </div>
            </div>
        </UserLayout>
    );
}
