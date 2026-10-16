// resources/js/pages/dashboard.tsx
//
// Admin dashboard, retheme + recompose pass.
// Mirrors the Live Wagers Monitor visual idiom — eyebrow + title +
// pulsing live pill, brass KPI strip with deltas + sparkline, then a
// recent-rounds preview using the same hairline-row table pattern.
//
// For non-admin users the page falls back to a trimmed account view
// so it doesn't go blank for player accounts that happen to land here.
//
// Money is never added across currencies. A tenant's view is in its
// tenant's currency; a platform view whose tenants hold several
// currencies shows one figure per currency, a per-game row per currency,
// and every bet in its own tenant's currency. A player's wallet shows in
// the wallet's currency.

import UserLayout from '@/layouts/user-layout';
import { KpiCard, type KpiDelta, deltaPct, formatCount } from '@/components/operator/kpi-card';
import { MoneyFigure, isNegative } from '@/components/operator/money-figure';
import {
    type Amount,
    type CurrencyProfile,
    type CurrencyTotal,
    formatMoney,
    formatMoneyCompact,
    formatTotals,
    toCents,
    useCurrencies,
    useTenantCurrency,
} from '@/lib/money';
import { Head, Link, router } from '@inertiajs/react';
import { useEffect } from 'react';

// Live pill on the page header is the user-facing signal that the
// numbers are kept fresh. This is the actual refresh — every 5s we
// ask Inertia to re-fetch ONLY the live-changing props (KPIs,
// sparkline, recent bets, timestamp). preserveScroll + preserveState
// mean the page doesn't blink or jump. Polling pauses when the tab
// is hidden so we don't burn HTTP in the background.
const POLL_INTERVAL_MS = 5_000;
const LIVE_PROPS = ['wager_stats', 'wager_spark', 'recent_bets', 'per_game', 'feed_errors', 'last_updated'];

function useDashboardPoll(enabled: boolean): void {
    useEffect(() => {
        if (!enabled || typeof window === 'undefined') return;

        let intervalId: ReturnType<typeof setInterval> | null = null;

        // Inertia v2 router.reload preserves scroll + state implicitly
        // (it's a partial visit to the current URL), so only `only` is
        // needed here.
        const tick = () => {
            router.reload({ only: LIVE_PROPS });
        };

        const start = () => {
            if (intervalId !== null) return;
            intervalId = setInterval(tick, POLL_INTERVAL_MS);
        };
        const stop = () => {
            if (intervalId !== null) {
                clearInterval(intervalId);
                intervalId = null;
            }
        };
        const onVisibility = () => (document.hidden ? stop() : start());

        if (!document.hidden) start();
        document.addEventListener('visibilitychange', onVisibility);
        return () => {
            stop();
            document.removeEventListener('visibilitychange', onVisibility);
        };
    }, [enabled]);
}

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
    amount: string;
    balance_after: string;
    description: string | null;
    game_name: string | null;
    created_at: string;
}

interface WalletData {
    balance: string;
    currency: string;
    status: string;
    total_deposited: string;
    total_withdrawn: string;
    total_won: string;
    total_lost: string;
    /** Lifetime handle (won + lost), exact. */
    total_wagered: string;
    recent_transactions: WalletTransaction[];
}

/** Counts only: money is per currency in WagerStats.money_*. */
interface StatsBucket {
    bets_placed: number;
    active_players: number;
    wins: number;
    losses: number;
    pending: number;
}

type MoneyField = 'total_wagered' | 'total_paid_out' | 'ggr';

interface WagerStats {
    today: StatsBucket;
    yesterday: StatsBucket;
    /** One entry per currency (exactly one for a tenant). GGR = wagered − paid out. */
    money_today: CurrencyTotal<MoneyField>[];
    money_yesterday: CurrencyTotal<MoneyField>[];
}

type WagerOutcome = 'win' | 'lost' | 'pending';

interface RecentBet {
    id: string | number | null;
    placed_at: string | null;
    game_name: string;
    game_slug: string;
    /** One line per game vocabulary: Fantasy picks, or a Vrrr Pha cash-out / crash multiplier. */
    detail: string;
    player: { name: string; uuid_short: string | null; initials: string };
    tenant_uuid: string | null;
    tenant_name: string | null;
    /** The bet's tenant's currency; null for a tenant key the SSO does not know. */
    currency: string | null;
    round_number: number | null;
    team_names: string[];
    bet_amount: string;
    combined_odds: number;
    potential_payout: string;
    outcome: WagerOutcome | string;
    winning_amount: string;
}

/** One row per (game, currency). */
interface PerGame {
    game_uuid: string;
    game_name: string;
    currency: string;
    bets_placed: number;
    active_players: number;
    total_wagered: string;
    total_paid_out: string;
    ggr: string;
}

interface DashboardProps {
    account?: Account;
    wallet?: WalletData | null;
    is_admin?: boolean;
    wager_stats?: WagerStats | null;
    wager_spark?: number[] | null;
    recent_bets?: RecentBet[];
    per_game?: PerGame[];
    feed_errors?: string[];
    last_updated?: string;
}

function num(value: string | number | null | undefined): number {
    if (value === null || value === undefined) return 0;
    const n = typeof value === 'string' ? parseFloat(value) : value;
    return Number.isFinite(n) ? n : 0;
}

/**
 * A money card's value and day-over-day delta. One currency: the compact
 * figure and its delta. Several (a platform spanning currencies): one
 * figure per currency and no delta, since a percentage across currencies
 * would weigh one against another.
 */
function moneyCard(
    today: CurrencyTotal<MoneyField>[],
    yesterday: CurrencyTotal<MoneyField>[],
    field: MoneyField,
    profileOf: (code: string) => CurrencyProfile | null,
): { value: string; delta?: KpiDelta } {
    if (today.length === 1 && yesterday.length <= 1 && (yesterday.length === 0 || yesterday[0].currency === today[0].currency)) {
        const p = profileOf(today[0].currency);
        const d = deltaPct(toCents(today[0][field] as Amount), toCents(yesterday[0]?.[field] as Amount));
        return { value: p ? formatMoneyCompact(today[0][field] as Amount, p) : '—', delta: d.sign === 'flat' ? undefined : d };
    }
    return { value: formatTotals(today, field, profileOf, true) };
}

// Map a numeric series to 0..100 bar heights for the sparkline.
function sparkBars(series: number[] | null | undefined): number[] {
    if (!series || series.length === 0) return [];
    const max = Math.max(...series, 1);
    return series.map((v) => Math.max(8, Math.round((v / max) * 100)));
}

export default function Dashboard(props: DashboardProps) {
    const isAdmin = !!props.is_admin;
    useDashboardPoll(isAdmin);
    const profileOf = useCurrencies();

    if (!isAdmin) {
        return <NonAdminDashboard {...props} />;
    }

    const today = props.wager_stats?.today;
    const yesterday = props.wager_stats?.yesterday;
    const moneyToday = props.wager_stats?.money_today ?? [];
    const moneyYesterday = props.wager_stats?.money_yesterday ?? [];

    // Card 1 — Active players
    const playersToday = num(today?.active_players);
    const playersYesterday = num(yesterday?.active_players);
    const playersDelta = deltaPct(playersToday, playersYesterday);

    // Card 2 — Bets placed
    const betsToday = num(today?.bets_placed);
    const betsYesterday = num(yesterday?.bets_placed);
    const betsDelta = deltaPct(betsToday, betsYesterday);

    // Cards 3–5 are money, per currency. Card 3 — total wagered (raw
    // activity; includes re-stakes); card 4 — total wins (paid out to
    // winners); card 5 — gross GGR (wagered − paid out), the figure
    // invoices, revenue share and tax are computed on. The deposit-aware
    // `real_ggr` is not shown: on production the deposit/winnings split is
    // unreliable (staff top-ups land in winnings), so it reads as a loss on
    // a day the house kept money.
    const wagered = moneyCard(moneyToday, moneyYesterday, 'total_wagered', profileOf);
    const wins = moneyCard(moneyToday, moneyYesterday, 'total_paid_out', profileOf);
    const ggr = moneyCard(moneyToday, moneyYesterday, 'ggr', profileOf);

    const sparkHeights = sparkBars(props.wager_spark);
    const recentBets = props.recent_bets ?? [];
    const perGame = props.per_game ?? [];
    const feedErrors = props.feed_errors ?? [];

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
                        value={wagered.value}
                        meta="gross"
                        spark={sparkHeights}
                    />
                    <KpiCard
                        label="Total wins · today"
                        value={wins.value}
                        delta={wins.delta}
                        meta="paid to players"
                    />
                    <KpiCard
                        label="GGR · today"
                        value={ggr.value}
                        brass
                        delta={ggr.delta}
                        meta="wagered − paid out"
                    />
                </div>

                {/* Recent bets preview — header stays put while the body
                 * scrolls. The bar above sits OUTSIDE the scroll container
                 * so it doesn't move when scrolling either. */}
                {feedErrors.length > 0 && (
                    <div data-testid="feed-errors" style={{ background: 'var(--cg-ink-card)', border: '1px solid var(--cg-warn)', borderRadius: 6, padding: '10px 14px', marginBottom: 14, fontSize: 12, color: 'var(--cg-fg-2)' }}>
                        <strong style={{ color: 'var(--cg-warn)' }}>Not included:</strong> {feedErrors.join(', ')} could not be reached. The figures above exclude {feedErrors.length === 1 ? 'that game' : 'those games'}.
                    </div>
                )}

                {/* Per-game split of today's figures, so one game's day is visible inside the totals. */}
                {perGame.length > 1 && (
                    <div className="cgo-table-wrap" style={{ marginBottom: 18 }} data-testid="per-game">
                        <table className="cgo-wagers">
                            <thead>
                                <tr>
                                    <th>Game · today</th>
                                    <th className="cgo-r" style={{ width: 90 }}>Players</th>
                                    <th className="cgo-r" style={{ width: 90 }}>Bets</th>
                                    <th className="cgo-r" style={{ width: 130 }}>Wagered</th>
                                    <th className="cgo-r" style={{ width: 130 }}>Wins</th>
                                    <th className="cgo-r" style={{ width: 130 }}>GGR</th>
                                </tr>
                            </thead>
                            <tbody>
                                {perGame.map((g) => {
                                    const p = profileOf(g.currency);
                                    return (
                                        <tr key={`${g.game_uuid}:${g.currency}`}>
                                            <td><span className="cgo-name">{g.game_name}</span></td>
                                            <td className="cgo-r"><span className="cgo-odds">{formatCount(g.active_players)}</span></td>
                                            <td className="cgo-r"><span className="cgo-odds">{formatCount(g.bets_placed)}</span></td>
                                            <td className="cgo-r"><span className="cgo-stake"><MoneyFigure value={g.total_wagered} profile={p} /></span></td>
                                            <td className="cgo-r"><span className="cgo-payout"><MoneyFigure value={g.total_paid_out} profile={p} code={false} /></span></td>
                                            <td className="cgo-r"><span className="cgo-payout" style={{ color: isNegative(g.ggr) ? 'var(--cg-neg)' : 'var(--cg-fg-1)' }}><MoneyFigure value={g.ggr} profile={p} code={false} /></span></td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}

                {/* Below md the tenant, round and odds columns are hidden so
                 * the key columns fit a phone; the table still scrolls sideways. */}
                <div className="cgo-table-bar flex-wrap gap-2" style={{ borderRadius: '8px 8px 0 0', borderTop: '1px solid var(--cg-rule)', borderLeft: '1px solid var(--cg-rule)', borderRight: '1px solid var(--cg-rule)' }}>
                    <div className="cgo-table-bar-title">Recent bets</div>
                    <Link href="/operator/wagers" className="cgo-table-bar-link inline-flex items-center max-sm:min-h-10">
                        View all →
                    </Link>
                </div>
                <div className="cgo-table-wrap cgo-table-wrap--scroll" style={{ borderRadius: '0 0 8px 8px' }}>
                    <table className="cgo-wagers">
                        <thead>
                            <tr>
                                <th style={{ minWidth: 180 }}>Player</th>
                                <th className="hidden md:table-cell" style={{ minWidth: 140 }}>Tenant</th>
                                <th className="cgo-r hidden md:table-cell" style={{ width: 80 }}>Round</th>
                                <th style={{ minWidth: 220 }}>Game · selection</th>
                                <th className="cgo-r" style={{ width: 110 }}>Wager</th>
                                <th className="cgo-r hidden md:table-cell" style={{ width: 80 }}>Odds</th>
                                <th className="cgo-r" style={{ width: 110 }}>Potential</th>
                                <th style={{ width: 100 }}>Outcome</th>
                            </tr>
                        </thead>
                        <tbody>
                            {recentBets.length === 0 ? (
                                <tr>
                                    <td colSpan={8} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>
                                        No bets placed yet.
                                    </td>
                                </tr>
                            ) : (
                                recentBets.map((b, i) => {
                                    const pill = outcomePill(b.outcome);
                                    const p = profileOf(b.currency);
                                    return (
                                        <tr key={b.id ?? i}>
                                            <td style={{ maxWidth: 220 }}>
                                                <div className="cgo-user">
                                                    <div className="cgo-av">{b.player.initials}</div>
                                                    <div style={{ minWidth: 0 }}>
                                                        <div className="cgo-name cgo-cell-clip" title={b.player.name}>{b.player.name}</div>
                                                        {b.player.uuid_short ? (
                                                            <div className="cgo-uid">{b.player.uuid_short}</div>
                                                        ) : null}
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="hidden md:table-cell" style={{ maxWidth: 180 }}>
                                                {b.tenant_name || b.tenant_uuid ? (
                                                    <span
                                                        className="cgo-cell-clip"
                                                        style={{ display: 'block', color: 'var(--cg-fg-2)', fontSize: 12 }}
                                                        title={b.tenant_name ?? b.tenant_uuid ?? ''}
                                                    >
                                                        {b.tenant_name ?? b.tenant_uuid}
                                                    </span>
                                                ) : (
                                                    <span style={{ color: 'var(--cg-fg-4)' }}>—</span>
                                                )}
                                            </td>
                                            <td className="cgo-r hidden md:table-cell">
                                                <span className="cgo-odds">#{b.round_number ?? '—'}</span>
                                            </td>
                                            <td style={{ maxWidth: 300 }}>
                                                <div className="cgo-uid" style={{ marginBottom: 2 }}>{b.game_name}</div>
                                                <span
                                                    className="cgo-selection cgo-cell-clip"
                                                    style={{ display: 'block' }}
                                                    title={b.team_names.length > 0 ? b.team_names.join(', ') : b.detail}
                                                >
                                                    <span className="cgo-pick">{b.detail}</span>
                                                </span>
                                            </td>
                                            <td className="cgo-r">
                                                <span className="cgo-stake">
                                                    <MoneyFigure value={b.bet_amount} profile={p} />
                                                </span>
                                            </td>
                                            <td className="cgo-r hidden md:table-cell">
                                                <span className="cgo-odds">
                                                    {b.combined_odds.toFixed(2)}x
                                                </span>
                                            </td>
                                            <td className="cgo-r">
                                                <span className="cgo-payout"><MoneyFigure value={b.potential_payout} profile={p} code={false} /></span>
                                            </td>
                                            <td>
                                                <span className={pill.className}>{pill.label}</span>
                                            </td>
                                        </tr>
                                    );
                                })
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </UserLayout>
    );
}

function outcomePill(outcome: string): { className: string; label: string } {
    switch (outcome) {
        case 'win':
            return { className: 'cgo-pill live', label: 'Win' };
        case 'lost':
            return { className: 'cgo-pill flagged', label: 'Loss' };
        case 'void':
            return { className: 'cgo-pill void', label: 'Cancelled' };
        case 'pending':
        default:
            return { className: 'cgo-pill pending', label: 'Pending' };
    }
}


// Non-admin fallback. Trimmed-down account view in the brass idiom so
// the page doesn't go blank for player accounts.
function NonAdminDashboard({ account, wallet }: DashboardProps) {
    const profileOf = useCurrencies();
    // The wallet's own currency; it is the player's tenant's, which is the fallback.
    const tenantCurrency = useTenantCurrency();
    const p = (wallet ? profileOf(wallet.currency) : null) ?? tenantCurrency;
    const money = (v: Amount) => (p ? formatMoney(v, p) : '—');
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
                            label={`Balance · ${p?.code ?? wallet.currency}`}
                            value={money(wallet.balance)}
                            brass
                            meta={wallet.status?.toUpperCase()}
                        />
                        <KpiCard label="Total won" value={money(wallet.total_won)} meta="lifetime" />
                        <KpiCard
                            label="Total wagered"
                            value={money(wallet.total_wagered)}
                            meta="lifetime"
                        />
                        <KpiCard
                            label="Total deposited"
                            value={money(wallet.total_deposited)}
                            meta="lifetime"
                        />
                    </div>
                ) : null}
            </div>
        </UserLayout>
    );
}
