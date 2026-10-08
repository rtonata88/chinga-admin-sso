// resources/js/pages/dorex/round-detail.tsx
//
// One Dorex round (X4): its figures, the path the engine redrew from the persisted seed with
// every close marked on it, the audit that re-settled every position on that path, and the
// positions. The seed and the path are only present once the round has ended; the engine
// withholds them before that, and so does this page.

import { KpiCard, formatCount, formatCurrencyCompact } from '@/components/operator/kpi-card';
import UserLayout from '@/layouts/user-layout';
import { Head, Link } from '@inertiajs/react';

import {
    ERROR_BOX, PANEL, TAP, formatDateTime, formatNAD, formatRatioPct, num, shortUuid, statePill, tenantLabel,
} from '../vrrr-pha/format';

interface Round {
    id: number;
    sequence: number;
    tenant_uuid: string;
    state: string;
    instrument: string;
    commitment: string;
    house_edge: string;
    q: string;
    vol: string;
    max_ticks: number;
    tick_ms: number;
    max_price: string;
    max_win_per_position: string;
    start_price: string;
    server_seed?: string;
    client_seed?: string;
    path_hash?: string;
    path_end?: 'margin_call' | 'max_price' | 'round_end';
    end_tick?: number;
    margin_call_tick?: number | null;
    peak_price?: string;
    prices?: string[];
    opened_at: string;
    started_at: string | null;
    ended_at: string | null;
    settled_at: string | null;
    bet_count: number;
    total_wagered: string;
    total_paid_out: string;
}

interface Position {
    id: number;
    user_uuid: string;
    stake: string;
    effective_ceiling: string;
    outcome: 'open' | 'closed';
    close_tick: number | null;
    close_price: string | null;
    close_reason: 'manual' | 'margin_call' | 'ceiling' | 'round_end' | null;
    payout: string | null;
    credit_status: string;
    latency_ms: number | null;
    placed_at: string;
}

interface Mismatch {
    positionId: string | null;
    field: string;
    expected: string;
    published: string;
}

interface Verify {
    matches?: boolean;
    positions_checked?: number;
    path_hash?: string;
    mismatches?: Mismatch[];
    error?: string;
}

interface GameProps {
    name: string;
    base: string;
}

interface Props {
    game?: GameProps;
    round: Round | null;
    positions: Position[];
    verify: Verify | null;
    tenantNames: Record<string, string>;
    error: string | null;
    backHref?: string;
}

const END: Record<NonNullable<Round['path_end']>, string> = {
    margin_call: 'Margin call',
    max_price: 'Price ceiling',
    round_end: 'Full length',
};

const REASON: Record<NonNullable<Position['close_reason']>, string> = {
    manual: 'closed',
    margin_call: 'margin call',
    ceiling: 'ceiling',
    round_end: 'round end',
};

function Field({ label, value, mono, wrap }: { label: string; value: string; mono?: boolean; wrap?: boolean }) {
    return (
        <div style={{ display: 'grid', gridTemplateColumns: 'minmax(96px, 150px) minmax(0, 1fr)', gap: 12, padding: '7px 0', borderBottom: '1px solid var(--cg-rule)' }}>
            <span style={{ color: 'var(--cg-fg-3)', fontSize: 12, letterSpacing: '.04em', textTransform: 'uppercase' }}>{label}</span>
            <span style={{ fontFamily: mono ? 'var(--cg-mono)' : undefined, fontSize: 13, color: 'var(--cg-fg-1)', wordBreak: wrap ? 'break-all' : undefined }}>
                {value}
            </span>
        </div>
    );
}

/** The redrawn path with the entry line and every close marked. Display only: the engine did the maths. */
function PathChart({ prices, start, maxTicks, closes }: { prices: number[]; start: number; maxTicks: number; closes: { tick: number; price: number }[] }) {
    const W = 900;
    const H = 260;
    const pad = { l: 8, r: 56, t: 10, b: 22 };
    const hi = Math.max(1, ...prices) * 1.05;
    const lo = Math.min(start, ...prices) * 0.95;
    const x = (t: number) => pad.l + (t / Math.max(1, maxTicks)) * (W - pad.l - pad.r);
    const y = (p: number) => pad.t + ((hi - p) / Math.max(1e-9, hi - lo)) * (H - pad.t - pad.b);
    const d = prices.map((p, i) => `${i === 0 ? 'M' : 'L'}${x(i).toFixed(1)} ${y(p).toFixed(1)}`).join('');
    return (
        <svg viewBox={`0 0 ${W} ${H}`} width="100%" role="img" aria-label="The round's path" data-testid="path-chart" style={{ display: 'block' }}>
            <line x1={pad.l} x2={W - pad.r} y1={y(start)} y2={y(start)} stroke="var(--cg-fg-3)" strokeWidth={1} />
            <text x={W - pad.r + 4} y={y(start) + 4} fontSize={11} fill="var(--cg-fg-2)" fontFamily="var(--cg-mono)">
                {start.toFixed(2)}
            </text>
            <line x1={pad.l} x2={W - pad.r} y1={y(1)} y2={y(1)} stroke="var(--cg-fg-3)" strokeWidth={1} strokeDasharray="2 3" />
            <path d={d} fill="none" stroke="var(--cg-brass)" strokeWidth={1.5} strokeLinejoin="round" />
            {closes.map((c, i) => (
                <rect key={i} x={x(c.tick) - 3} y={y(c.price) - 3} width={6} height={6} fill="var(--cg-fg-1)" />
            ))}
            <text x={pad.l} y={H - 6} fontSize={11} fill="var(--cg-fg-3)" fontFamily="var(--cg-mono)">tick 0</text>
            <text x={W - pad.r} y={H - 6} fontSize={11} fill="var(--cg-fg-3)" fontFamily="var(--cg-mono)" textAnchor="end">
                tick {maxTicks}
            </text>
        </svg>
    );
}

export default function DorexRoundDetail({ game = { name: 'Dorex', base: '/dorex' }, round, positions = [], verify, tenantNames = {}, error, backHref = `${game.base}/rounds` }: Props) {
    const back = (
        <Link href={backHref} className={`cg-btn cg-btn--text cg-btn--sm max-sm:leading-10! ${TAP}`} style={{ marginBottom: 12, display: 'inline-block' }}>
            ‹ Back to rounds
        </Link>
    );

    if (error || !round) {
        return (
            <UserLayout title={`${game.name} round`}>
                <Head title={`${game.name} round · Admin`} />
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
    const revealed = round.server_seed != null;
    const wagered = num(round.total_wagered);
    const paidOut = num(round.total_paid_out);
    const title = `Round #${round.sequence}`;
    const prices = (round.prices ?? []).map(num);
    const closes = positions
        .filter((p) => p.close_tick !== null && p.close_price !== null)
        .map((p) => ({ tick: p.close_tick as number, price: num(p.close_price) }));

    return (
        <UserLayout title={title}>
            <Head title={`${title} · ${game.name}`} />
            <div className="cgo-page">
                {back}
                <div className="cgo-page-head">
                    <div>
                        <div className="cgo-eyebrow">{game.name} · {round.instrument} · {tenantLabel(round.tenant_uuid, tenantNames)}</div>
                        <h1 className="cgo-title" style={{ display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 12 }}>
                            {title}
                            <span className={`cgo-pill ${state.pill}`}>{state.label}</span>
                        </h1>
                        <div className="cgo-subtitle">
                            Opened {formatDateTime(round.opened_at)}
                            {round.started_at ? ` · Live ${formatDateTime(round.started_at)}` : ''}
                            {round.ended_at ? ` · Ended ${formatDateTime(round.ended_at)}` : ''}
                        </div>
                    </div>
                </div>

                <div className="cgo-kpis cgo-kpis--5">
                    <KpiCard
                        label="Path ended"
                        value={revealed && round.path_end ? END[round.path_end] : 'in play'}
                        brass={revealed}
                        meta={revealed ? `tick ${round.end_tick ?? '—'} · peak ${num(round.peak_price).toFixed(4)}` : 'hidden until the round ends'}
                    />
                    <KpiCard label="Stakes" value={formatCount(round.bet_count)} meta="one per player" />
                    <KpiCard label="Wagered" value={formatCurrencyCompact(wagered)} meta="staked" />
                    <KpiCard label="Paid out" value={formatCurrencyCompact(paidOut)} meta="to closed stakes" />
                    <KpiCard label="GGR" value={formatCurrencyCompact(wagered - paidOut)} meta="wagered − paid out" />
                </div>

                {prices.length > 0 && (
                    <section style={{ ...PANEL, marginBottom: 18 }}>
                        <div className="cgo-eyebrow" style={{ marginBottom: 8 }}>The path, redrawn from the seed · closes marked</div>
                        <PathChart prices={prices} start={num(round.start_price)} maxTicks={round.max_ticks} closes={closes} />
                    </section>
                )}

                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(min(360px, 100%), 1fr))', gap: 16, marginBottom: 18 }}>
                    <section style={{ ...PANEL, minWidth: 0 }} data-testid="path-audit">
                        <div className="cgo-eyebrow" style={{ marginBottom: 8 }}>Path audit</div>
                        <Field label="Commitment" value={round.commitment} mono wrap />
                        {revealed ? (
                            <>
                                <Field label="Server seed" value={round.server_seed ?? '—'} mono wrap />
                                <Field label="Client seed" value={round.client_seed ?? '—'} mono wrap />
                                <Field label="Path hash" value={round.path_hash ?? '—'} mono wrap />
                            </>
                        ) : (
                            <div style={{ padding: '10px 0', fontSize: 13, color: 'var(--cg-fg-3)' }}>
                                The server seed and the path are revealed when the round ends. Only the commitment is public until then.
                            </div>
                        )}
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
                                            {verify.matches ? '✓ Path and every close verify' : '✗ Does not verify'}
                                        </div>
                                        <div style={{ fontSize: 12, color: 'var(--cg-fg-2)', marginTop: 6 }}>
                                            Redrawn from the seed; {formatCount(verify.positions_checked ?? 0)} closed stakes re-settled on it.
                                        </div>
                                        {(verify.mismatches ?? []).map((m, i) => (
                                            <div key={i} style={{ fontSize: 12, color: 'var(--cg-neg)', marginTop: 4, fontFamily: 'var(--cg-mono)', wordBreak: 'break-all' }}>
                                                {m.positionId ? `stake #${m.positionId} ` : ''}
                                                {m.field}: expected {m.expected}, stored {m.published}
                                            </div>
                                        ))}
                                        {!verify.matches && (
                                            <div style={{ fontSize: 12, color: 'var(--cg-neg)', marginTop: 8 }}>
                                                The stored round does not reproduce from its seed. Treat the round as suspect and escalate.
                                            </div>
                                        )}
                                    </>
                                )}
                            </div>
                        )}
                    </section>

                    <section style={{ ...PANEL, minWidth: 0 }}>
                        <div className="cgo-eyebrow" style={{ marginBottom: 8 }}>Parameters, as drawn</div>
                        <Field label="House edge" value={formatRatioPct(round.house_edge)} mono />
                        <Field label="Start price" value={round.start_price} mono />
                        <Field label="q per tick" value={round.q} mono />
                        <Field label="vol per tick" value={round.vol} mono />
                        <Field label="Ticks" value={`${round.max_ticks} × ${round.tick_ms} ms`} mono />
                        <Field label="Price ceiling" value={round.max_price} mono />
                        <Field label="Max win" value={`NAD ${formatNAD(round.max_win_per_position)}`} mono />
                        <Field label="Settled" value={formatDateTime(round.settled_at)} />
                    </section>
                </div>

                <div className="cgo-table-wrap cgo-table-wrap--scroll">
                    <table className="cgo-wagers max-md:min-w-[1000px]">
                        <thead>
                            <tr>
                                <th style={{ width: 80 }}>Stake</th>
                                <th style={{ width: 110 }}>Player</th>
                                <th className="cgo-r" style={{ width: 110 }}>Amount</th>
                                <th className="cgo-r" style={{ width: 100 }}>Ceiling</th>
                                <th className="cgo-r" style={{ width: 80 }}>Tick</th>
                                <th className="cgo-r" style={{ width: 100 }}>Price</th>
                                <th style={{ width: 110 }}>Closed by</th>
                                <th className="cgo-r" style={{ width: 120 }}>Payout</th>
                                <th className="cgo-r" style={{ width: 80 }}>Latency</th>
                                <th style={{ width: 90 }}>Credit</th>
                            </tr>
                        </thead>
                        <tbody>
                            {positions.length === 0 ? (
                                <tr>
                                    <td colSpan={10} style={{ textAlign: 'center', color: 'var(--cg-fg-3)', padding: '32px 0' }}>
                                        No stakes on this round.
                                    </td>
                                </tr>
                            ) : (
                                positions.map((p) => (
                                    <tr key={p.id} className={p.credit_status === 'failed' || p.credit_status === 'held' ? 'flagged' : undefined}>
                                        <td><span className="cgo-name" style={{ fontFamily: 'var(--cg-mono)' }}>#{p.id}</span></td>
                                        <td><span className="cgo-uid" title={p.user_uuid}>{shortUuid(p.user_uuid)}</span></td>
                                        <td className="cgo-r">
                                            <span className="cgo-stake"><span className="cgo-ccy">NAD</span>{formatNAD(p.stake)}</span>
                                        </td>
                                        <td className="cgo-r"><span className="cgo-odds">{num(p.effective_ceiling).toFixed(2)}</span></td>
                                        <td className="cgo-r"><span className="cgo-odds">{p.close_tick ?? '—'}</span></td>
                                        <td className="cgo-r"><span className="cgo-odds">{p.close_price === null ? '—' : num(p.close_price).toFixed(4)}</span></td>
                                        <td><span className="cgo-uid">{p.close_reason ? REASON[p.close_reason] : 'open'}</span></td>
                                        <td className="cgo-r"><span className="cgo-payout">{p.payout === null ? '—' : formatNAD(p.payout)}</span></td>
                                        <td className="cgo-r"><span className="cgo-odds">{p.latency_ms === null ? '—' : `${p.latency_ms} ms`}</span></td>
                                        <td>
                                            <span className="cgo-uid" style={{ color: p.credit_status === 'failed' || p.credit_status === 'held' ? 'var(--cg-neg)' : undefined }}>
                                                {num(p.payout) > 0 ? p.credit_status : '—'}
                                            </span>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                    <div className="cgo-table-foot">
                        <span>
                            <b className="cgo-mono">{positions.length}</b> stakes
                        </span>
                    </div>
                </div>
            </div>
        </UserLayout>
    );
}
