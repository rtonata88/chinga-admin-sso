// resources/js/pages/dashboard.tsx
//
// Admin dashboard, retheme + recompose pass.
// Mirrors the Live Wagers Monitor visual idiom — eyebrow + title +
// pulsing live pill, brass KPI strip with deltas + sparkline, then a
// recent-rounds preview using the same hairline-row table pattern.
//
// For non-admin users the page falls back to a trimmed account view
// so it doesn't go blank for player accounts that happen to land here.

import UserLayout from '@/layouts/user-layout';
import { Head, Link } from '@inertiajs/react';

interface Account {
    name: string;
    email: string;
    display_name: string | null;
    avatar_url: string | null;
    status: string;
    email_verified: boolean;
    two_factor_enabled: boolean;
    member_since: string;
    last_login_at: string | null;
}

interface WalletTransaction {
    type: string;
    amount: number;
    balance_after: number;
    description: string | null;
    game_name: string | null;
    created_at: string;
}

interface WalletData {
    balance: number;
    currency: string;
    status: string;
    total_deposited: number;
    total_withdrawn: number;
    total_won: number;
    total_lost: number;
    recent_transactions: WalletTransaction[];
}

interface StatsBucket {
    bets_placed: number;
    active_players: number;
    total_wagered: string | number;
    total_paid_out: string | number;
    // GGR fields. `ggr` is the legacy gross figure (wagered - paid_out).
    // `real_ggr` is deposit-aware: only counts the deposit-funded slice
    // of activity, so re-staked winnings don't inflate house revenue.
    ggr?: string | number;
    deposit_wagered?: string | number;
    deposit_paid_out?: string | number;
    real_ggr?: string | number;
    wins: number;
    losses: number;
    pending: number;
}

interface WagerStats {
    today: StatsBucket;
    yesterday: StatsBucket;
}

interface RecentRound {
    id: number | null;
    round_number: number | null;
    tenant_uuid: string | null;
    created_at: string | null;
    bet_count: number | null;
    total_wagered: number | null;
}

interface DashboardProps {
    account?: Account;
    wallet?: WalletData | null;
    is_admin?: boolean;
    wager_stats?: WagerStats | null;
    wager_spark?: number[] | null;
    recent_rounds?: RecentRound[];
    last_updated?: string;
}

function num(value: string | number | null | undefined): number {
    if (value === null || value === undefined) return 0;
    const n = typeof value === 'string' ? parseFloat(value) : value;
    return Number.isFinite(n) ? n : 0;
}

function formatCount(n: number): string {
    return n.toLocaleString('en-US');
}

function formatCurrencyCompact(n: number): string {
    if (n >= 1_000_000) return (n / 1_000_000).toFixed(1) + 'M';
    if (n >= 1_000) return (n / 1_000).toFixed(1) + 'K';
    return n.toFixed(0);
}

function formatNAD(n: number): string {
    return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function deltaPct(today: number, yesterday: number): { sign: 'pos' | 'neg' | 'flat'; text: string } {
    if (yesterday === 0) {
        if (today > 0) return { sign: 'pos', text: 'New today' };
        return { sign: 'flat', text: 'No change' };
    }
    const diff = today - yesterday;
    const pct = (diff / yesterday) * 100;
    const sign: 'pos' | 'neg' | 'flat' = pct > 0 ? 'pos' : pct < 0 ? 'neg' : 'flat';
    const abs = Math.abs(pct).toFixed(1);
    return { sign, text: `${pct >= 0 ? '+' : '-'}${abs}%` };
}

// Map a numeric series to 0..100 bar heights for the sparkline.
function sparkBars(series: number[] | null | undefined): number[] {
    if (!series || series.length === 0) return [];
    const max = Math.max(...series, 1);
    return series.map((v) => Math.max(8, Math.round((v / max) * 100)));
}

export default function Dashboard(props: DashboardProps) {
    const isAdmin = !!props.is_admin;

    if (!isAdmin) {
        return <NonAdminDashboard {...props} />;
    }

    const today = props.wager_stats?.today;
    const yesterday = props.wager_stats?.yesterday;

    // Card 1 — Active players
    const playersToday = num(today?.active_players);
    const playersYesterday = num(yesterday?.active_players);
    const playersDelta = deltaPct(playersToday, playersYesterday);

    // Card 2 — Bets placed
    const betsToday = num(today?.bets_placed);
    const betsYesterday = num(yesterday?.bets_placed);
    const betsDelta = deltaPct(betsToday, betsYesterday);

    // Card 3 — Total wagered (raw activity; includes re-stakes)
    const wageredToday = num(today?.total_wagered);
    const wageredYesterday = num(yesterday?.total_wagered);
    const wageredDelta = deltaPct(wageredToday, wageredYesterday);

    // Card 4 — Total wins (paid out to winners)
    const winsToday = num(today?.total_paid_out);
    const winsYesterday = num(yesterday?.total_paid_out);
    const winsDelta = deltaPct(winsToday, winsYesterday);

    // Card 5 — GGR (deposit-aware). Falls back to gross GGR if the
    // upstream is too old to expose real_ggr — that path is just
    // wagered - paid_out, which is what the user is correcting away
    // from but is the safest fallback.
    const ggrToday =
        today?.real_ggr !== undefined
            ? num(today.real_ggr)
            : num(today?.ggr) || wageredToday - winsToday;
    const ggrYesterday =
        yesterday?.real_ggr !== undefined
            ? num(yesterday.real_ggr)
            : num(yesterday?.ggr) || wageredYesterday - winsYesterday;
    const ggrDelta = deltaPct(ggrToday, ggrYesterday);

    const sparkHeights = sparkBars(props.wager_spark);
    const recentRounds = props.recent_rounds ?? [];

    return (
        <UserLayout title="Dashboard">
            <Head title="Dashboard" />
            <div className="cgo-page">
                {/* Page header */}
                <div className="cgo-page-head">
                    <div>
                        <div className="cgo-eyebrow">Trading desk</div>
                        <h1 className="cgo-title">
                            Live activity
                            <span className="cgo-live-pill">Live</span>
                        </h1>
                        <div className="cgo-subtitle">
                            Open wagers, handle and player activity right now. Updated{' '}
                            {props.last_updated ?? '—'}.
                        </div>
                    </div>
                    <div className="cgo-head-actions">
                        <Link href="/operator/wagers" className="cg-btn cg-btn--primary cg-btn--sm">
                            Open wagers monitor
                        </Link>
                    </div>
                </div>

                {/* KPI strip — five cards: players, bets, wagered, wins, GGR. */}
                <div className="cgo-kpis cgo-kpis--5">
                    <KpiCard
                        label="Active players · today"
                        value={formatCount(playersToday)}
                        delta={playersDelta.sign === 'flat' ? undefined : playersDelta}
                        meta="distinct user uuids"
                    />
                    <KpiCard
                        label="Bets placed · today"
                        value={formatCount(betsToday)}
                        delta={betsDelta.sign === 'flat' ? undefined : betsDelta}
                        meta="all outcomes"
                    />
                    <KpiCard
                        label="Total wagered · today"
                        value={formatCurrencyCompact(wageredToday)}
                        meta="NAD · gross"
                        spark={sparkHeights}
                    />
                    <KpiCard
                        label="Total wins · today"
                        value={formatCurrencyCompact(winsToday)}
                        delta={winsDelta.sign === 'flat' ? undefined : winsDelta}
                        meta="NAD · paid to players"
                    />
                    <KpiCard
                        label="GGR · today"
                        value={formatCurrencyCompact(ggrToday)}
                        brass
                        delta={ggrDelta.sign === 'flat' ? undefined : ggrDelta}
                        meta="NAD · deposit-funded"
                    />
                </div>

                {/* Recent rounds preview */}
                <div className="cgo-table-wrap" style={{ borderRadius: 8 }}>
                    <div className="cgo-table-bar">
                        <div className="cgo-table-bar-title">Recent rounds</div>
                        <Link href="/operator/wagers" className="cgo-table-bar-link">
                            View all →
                        </Link>
                    </div>
                    <table className="cgo-wagers">
                        <thead>
                            <tr>
                                <th style={{ width: 130 }}>Round</th>
                                <th>Tenant</th>
                                <th className="cgo-r">Bets</th>
                                <th className="cgo-r">Wagered</th>
                                <th>Started</th>
                            </tr>
                        </thead>
                        <tbody>
                            {recentRounds.length === 0 ? (
                                <tr>
                                    <td colSpan={5} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>
                                        No recent rounds yet.
                                    </td>
                                </tr>
                            ) : (
                                recentRounds.map((r) => (
                                    <tr key={r.id ?? Math.random()}>
                                        <td>
                                            <span className="cgo-stake" style={{ fontSize: 16 }}>
                                                #{r.round_number ?? '—'}
                                            </span>
                                        </td>
                                        <td>
                                            <span style={{ color: 'var(--cg-fg-2)', fontFamily: 'var(--cg-mono)', fontSize: 11.5 }}>
                                                {r.tenant_uuid ?? '—'}
                                            </span>
                                        </td>
                                        <td className="cgo-r">
                                            <span className="cgo-odds">{r.bet_count ?? '—'}</span>
                                        </td>
                                        <td className="cgo-r">
                                            {r.total_wagered !== null ? (
                                                <span className="cgo-stake">
                                                    <span className="cgo-ccy">NAD</span>
                                                    {formatNAD(r.total_wagered)}
                                                </span>
                                            ) : (
                                                <span className="cgo-odds">—</span>
                                            )}
                                        </td>
                                        <td className="cgo-ts">{r.created_at ? formatTime(r.created_at) : '—'}</td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </UserLayout>
    );
}

interface KpiCardProps {
    label: string;
    value: string;
    brass?: boolean;
    delta?: { sign: 'pos' | 'neg' | 'flat'; text: string };
    meta?: string;
    spark?: number[];
}

function KpiCard({ label, value, brass, delta, meta, spark }: KpiCardProps) {
    return (
        <div className="cgo-kpi">
            <div className="cgo-kpi-label">{label}</div>
            <div className={`cgo-kpi-num${brass ? ' brass' : ''}`}>{value}</div>
            <div className="cgo-kpi-foot">
                {delta ? (
                    <span
                        className={
                            delta.sign === 'pos'
                                ? 'cgo-delta-pos'
                                : delta.sign === 'neg'
                                  ? 'cgo-delta-neg'
                                  : 'cgo-meta'
                        }
                    >
                        {delta.sign === 'pos' ? '▲ ' : delta.sign === 'neg' ? '▼ ' : ''}
                        {delta.text}
                    </span>
                ) : (
                    <span className="cgo-meta">{meta}</span>
                )}
                {spark && spark.length > 0 ? (
                    <div className="cgo-spark">
                        {spark.map((h, i) => (
                            <span key={i} style={{ height: `${h}%` }} />
                        ))}
                    </div>
                ) : delta && meta ? (
                    <span className="cgo-meta">{meta}</span>
                ) : null}
            </div>
        </div>
    );
}

function formatTime(iso: string): string {
    try {
        const d = new Date(iso);
        const hh = String(d.getHours()).padStart(2, '0');
        const mm = String(d.getMinutes()).padStart(2, '0');
        const ss = String(d.getSeconds()).padStart(2, '0');
        const day = d.toLocaleDateString('en-US', { day: '2-digit', month: 'short' });
        return `${hh}:${mm}:${ss} · ${day}`;
    } catch {
        return iso;
    }
}

// Non-admin fallback. Trimmed-down account view in the brass idiom so
// the page doesn't go blank for player accounts.
function NonAdminDashboard({ account, wallet }: DashboardProps) {
    return (
        <UserLayout title="Dashboard">
            <Head title="Dashboard" />
            <div className="cgo-page">
                <div className="cgo-page-head">
                    <div>
                        <div className="cgo-eyebrow">Account</div>
                        <h1 className="cgo-title">
                            Welcome back{account?.display_name ? `, ${account.display_name}` : ''}
                        </h1>
                        <div className="cgo-subtitle">{account?.email}</div>
                    </div>
                </div>

                {wallet ? (
                    <div className="cgo-kpis">
                        <KpiCard
                            label={`Balance · ${wallet.currency}`}
                            value={formatNAD(wallet.balance)}
                            brass
                            meta={wallet.status?.toUpperCase()}
                        />
                        <KpiCard label="Total won" value={formatNAD(wallet.total_won)} meta="lifetime" />
                        <KpiCard
                            label="Total wagered"
                            value={formatNAD(wallet.total_lost + wallet.total_won)}
                            meta="lifetime"
                        />
                        <KpiCard
                            label="Total deposited"
                            value={formatNAD(wallet.total_deposited)}
                            meta="lifetime"
                        />
                    </div>
                ) : null}
            </div>
        </UserLayout>
    );
}
