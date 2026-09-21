// resources/js/pages/platform/treasury.tsx
//
// Player liability and distributable profit. Deposits are money held
// for players; the only profit is what players have lost on settled
// bets. The page puts the bank balance (entered by hand) against every
// wallet balance and every withdrawal on hold, takes off the variance
// reserve and the tax provision, and shows what is left to transfer.

import { KpiCard, formatCount, formatCurrencyCompact } from '@/components/operator/kpi-card';
import UserLayout from '@/layouts/user-layout';
import { Head, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';

interface Settings {
    bank_balance: string;
    bank_balance_as_of: string | null;
    variance_reserve: string;
    tax_pct: string;
    updated_at: string | null;
}

interface Summary {
    bank_balance: string;
    owed: string;
    held: string;
    liability: string;
    surplus: string;
    covered: boolean;
    coverage_pct: string | null;
    reserve: string;
    tax_provision: string;
    distributable: string;
    ledger_cash: string;
    lifetime_ggr: string;
    month_ggr: string;
    dormant: string;
}

interface TenantRow {
    tenant_uuid: string;
    tenant_name: string;
    business_model: string;
    wallets: number;
    funded: number;
    owed: string;
    dormant: string;
    held: string;
    held_count: number;
    staff_deposits: string;
    player_deposits: string;
    paid_out: string;
    ledger_cash: string;
    lifetime_ggr: string;
    month_ggr: string;
}

interface Props {
    settings: Settings;
    summary: Summary;
    tenants: TenantRow[];
    totals: Omit<TenantRow, 'tenant_uuid' | 'tenant_name' | 'business_model'>;
    as_of: string;
    month: string;
    dormant_days: number;
}

const num = (v: string | number | null | undefined): number => {
    const n = typeof v === 'string' ? parseFloat(v) : (v ?? 0);
    return Number.isFinite(n) ? n : 0;
};
const nad = (v: string | number | null | undefined): string =>
    num(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const MONTH_FMT = new Intl.DateTimeFormat('en-GB', { month: 'long', year: 'numeric' });
const DATETIME_FMT = new Intl.DateTimeFormat('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' });

const PANEL: React.CSSProperties = { background: 'var(--cg-ink-card)', border: '1px solid var(--cg-rule)', borderRadius: 8, padding: 16 };

function Signed({ v, bold }: { v: string; bold?: boolean }) {
    const n = num(v);
    return (
        <span className="cgo-payout" style={{ color: n < 0 ? 'var(--cg-neg)' : 'var(--cg-fg-1)', fontWeight: bold ? 600 : undefined }}>
            {nad(v)}
        </span>
    );
}

function Line({ label, value, sub, minus, total }: { label: string; value: string; sub?: string; minus?: boolean; total?: boolean }) {
    return (
        <div style={{
            display: 'grid', gridTemplateColumns: '1fr auto', gap: 12, padding: '8px 0',
            borderTop: total ? '1px solid var(--cg-rule-strong)' : undefined,
            borderBottom: total ? undefined : '1px solid var(--cg-rule)',
        }}>
            <div>
                <div style={{ fontSize: 13, color: total ? 'var(--cg-fg-1)' : 'var(--cg-fg-2)', fontWeight: total ? 600 : undefined }}>
                    {minus ? '− ' : ''}{label}
                </div>
                {sub && <div style={{ fontSize: 11, color: 'var(--cg-fg-3)', marginTop: 2 }}>{sub}</div>}
            </div>
            <div style={{ fontFamily: 'var(--cg-mono)', fontSize: total ? 16 : 13, color: total ? 'var(--cg-brass-hi)' : 'var(--cg-fg-1)', fontWeight: total ? 600 : undefined, alignSelf: 'center' }}>
                {num(value) < 0 && total ? <span style={{ color: 'var(--cg-neg)' }}>{nad(value)}</span> : nad(value)}
            </div>
        </div>
    );
}

export default function Treasury({ settings, summary, tenants = [], totals, as_of, month, dormant_days }: Props) {
    const form = useForm({
        bank_balance: settings.bank_balance,
        bank_balance_as_of: settings.bank_balance_as_of ?? '',
        variance_reserve: settings.variance_reserve,
        tax_pct: settings.tax_pct,
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put('/platform/treasury', { preserveScroll: true });
    };
    const monthLabel = MONTH_FMT.format(new Date(month));
    const distributableNeg = num(summary.distributable) < 0;

    return (
        <UserLayout title="Treasury">
            <Head title="Treasury · Admin" />
            <div className="cgo-page">
                <div className="cgo-page-head">
                    <div>
                        <div className="cgo-eyebrow">Admin</div>
                        <h1 className="cgo-title">Treasury</h1>
                        <div className="cgo-subtitle">
                            What is owed to players, what the bank holds, and what the business may take out. Ledger as of {DATETIME_FMT.format(new Date(as_of))}.
                        </div>
                    </div>
                </div>

                {!summary.covered && (
                    <div data-testid="uncovered" style={{ background: 'var(--cg-ink-card)', border: '1px solid var(--cg-neg)', borderRadius: 6, padding: 14, marginBottom: 18, fontSize: 13, color: 'var(--cg-fg-1)' }}>
                        <strong style={{ color: 'var(--cg-neg)' }}>Player funds are not fully backed.</strong>{' '}
                        The bank balance is N${nad(summary.bank_balance)} against N${nad(summary.liability)} owed. Nothing is distributable until the shortfall of N${nad(Math.abs(num(summary.surplus)))} is covered.
                    </div>
                )}

                <div className="cgo-kpis cgo-kpis--5">
                    <KpiCard
                        label="Bank balance"
                        value={formatCurrencyCompact(num(summary.bank_balance))}
                        meta={settings.bank_balance_as_of ? `entered as of ${settings.bank_balance_as_of}` : 'not entered yet'}
                    />
                    <KpiCard label="Owed to players" value={formatCurrencyCompact(num(summary.liability))} meta={`${formatCount(totals.funded)} funded wallets · ${formatCount(totals.held_count)} withdrawals on hold`} />
                    <KpiCard label="Coverage" value={summary.coverage_pct === null ? '—' : `${summary.coverage_pct}%`} meta={summary.covered ? 'bank ÷ owed' : 'below 100%'} />
                    <KpiCard label="Reserve + tax" value={formatCurrencyCompact(num(summary.reserve) + num(summary.tax_provision))} meta={`reserve ${nad(summary.reserve)} · tax ${nad(summary.tax_provision)}`} />
                    <KpiCard label="Distributable" value={formatCurrencyCompact(num(summary.distributable))} brass={!distributableNeg} meta={distributableNeg ? 'nothing to transfer' : 'safe to transfer out'} />
                </div>

                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(340px, 1fr))', gap: 16, marginBottom: 18 }}>
                    <section style={PANEL} data-testid="calculation">
                        <div className="cgo-eyebrow" style={{ marginBottom: 6 }}>Calculation</div>
                        <Line label="Bank balance" value={summary.bank_balance} sub="entered below, from the business account" />
                        <Line minus label="Wallet balances" value={summary.owed} sub="every open wallet, including dormant ones" />
                        <Line minus label="Withdrawals on hold" value={summary.held} sub="requested or approved, already off the wallet, not yet paid" />
                        <Line minus label="Variance reserve" value={summary.reserve} sub="entered below; a bad streak is paid from here" />
                        <Line minus label="Tax provision" value={summary.tax_provision} sub={`${settings.tax_pct}% of ${monthLabel} GGR (${nad(summary.month_ggr)}), floored at zero`} />
                        <Line total label="Distributable" value={summary.distributable} />
                    </section>

                    <section style={PANEL}>
                        <div className="cgo-eyebrow" style={{ marginBottom: 6 }}>Inputs</div>
                        <form onSubmit={submit} style={{ display: 'grid', gap: 12 }}>
                            <label style={{ display: 'grid', gap: 4, fontSize: 12, color: 'var(--cg-fg-2)' }}>
                                Bank balance (N$)
                                <span className="cgo-input"><input type="number" step="0.01" min="0" value={form.data.bank_balance} onChange={(e) => form.setData('bank_balance', e.target.value)} /></span>
                                {form.errors.bank_balance && <span style={{ color: 'var(--cg-neg)' }}>{form.errors.bank_balance}</span>}
                            </label>
                            <label style={{ display: 'grid', gap: 4, fontSize: 12, color: 'var(--cg-fg-2)' }}>
                                Bank balance as of
                                <span className="cgo-input"><input type="date" value={form.data.bank_balance_as_of} onChange={(e) => form.setData('bank_balance_as_of', e.target.value)} /></span>
                            </label>
                            <label style={{ display: 'grid', gap: 4, fontSize: 12, color: 'var(--cg-fg-2)' }}>
                                Variance reserve (N$)
                                <span className="cgo-input"><input type="number" step="0.01" min="0" value={form.data.variance_reserve} onChange={(e) => form.setData('variance_reserve', e.target.value)} /></span>
                                {form.errors.variance_reserve && <span style={{ color: 'var(--cg-neg)' }}>{form.errors.variance_reserve}</span>}
                            </label>
                            <label style={{ display: 'grid', gap: 4, fontSize: 12, color: 'var(--cg-fg-2)' }}>
                                Gaming tax (% of GGR)
                                <span className="cgo-input"><input type="number" step="0.01" min="0" max="100" value={form.data.tax_pct} onChange={(e) => form.setData('tax_pct', e.target.value)} /></span>
                                {form.errors.tax_pct && <span style={{ color: 'var(--cg-neg)' }}>{form.errors.tax_pct}</span>}
                            </label>
                            <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                                <button type="submit" className="cg-btn cg-btn--primary cg-btn--sm" disabled={form.processing}>Save</button>
                                {settings.updated_at && <span style={{ fontSize: 11, color: 'var(--cg-fg-3)' }}>last saved {DATETIME_FMT.format(new Date(settings.updated_at))}</span>}
                                {form.recentlySuccessful && <span style={{ fontSize: 11, color: 'var(--cg-pos)' }}>Saved</span>}
                            </div>
                        </form>
                        <div style={{ marginTop: 14, fontSize: 12, color: 'var(--cg-fg-3)', lineHeight: 1.5 }}>
                            Size the reserve from the worst swing you expect: at least two or three times the max win per round, or three days of your worst observed daily loss scaled to the number of active players. Take profit once a month from the closed month's GGR, and never more than this figure.
                        </div>
                    </section>
                </div>

                <div className="cgo-table-wrap cgo-table-wrap--scroll">
                    <table className="cgo-wagers">
                        <thead>
                            <tr>
                                <th style={{ width: 170 }}>Tenant</th>
                                <th className="cgo-r" style={{ width: 80 }}>Wallets</th>
                                <th className="cgo-r" style={{ width: 120 }}>Owed</th>
                                <th className="cgo-r" style={{ width: 110 }}>Dormant</th>
                                <th className="cgo-r" style={{ width: 110 }}>On hold</th>
                                <th className="cgo-r" style={{ width: 120 }}>Staff deposits</th>
                                <th className="cgo-r" style={{ width: 120 }}>Player deposits</th>
                                <th className="cgo-r" style={{ width: 110 }}>Paid out</th>
                                <th className="cgo-r" style={{ width: 120 }}>Ledger cash</th>
                                <th className="cgo-r" style={{ width: 110 }}>Month GGR</th>
                                <th className="cgo-r" style={{ width: 120 }}>Lifetime GGR</th>
                            </tr>
                        </thead>
                        <tbody>
                            {tenants.length === 0 ? (
                                <tr><td colSpan={11} style={{ textAlign: 'center', color: 'var(--cg-fg-3)', padding: '32px 0' }}>No wallets yet.</td></tr>
                            ) : tenants.map((t) => (
                                <tr key={t.tenant_uuid}>
                                    <td>
                                        <span className="cgo-name">{t.tenant_name}</span>
                                        <div className="cgo-uid">{t.business_model}</div>
                                    </td>
                                    <td className="cgo-r"><span className="cgo-odds">{formatCount(t.funded)} / {formatCount(t.wallets)}</span></td>
                                    <td className="cgo-r"><span className="cgo-stake"><span className="cgo-ccy">NAD</span>{nad(t.owed)}</span></td>
                                    <td className="cgo-r"><span className="cgo-payout">{nad(t.dormant)}</span></td>
                                    <td className="cgo-r"><span className="cgo-payout">{nad(t.held)}</span></td>
                                    <td className="cgo-r"><span className="cgo-payout">{nad(t.staff_deposits)}</span></td>
                                    <td className="cgo-r"><span className="cgo-payout">{nad(t.player_deposits)}</span></td>
                                    <td className="cgo-r"><span className="cgo-payout">{nad(t.paid_out)}</span></td>
                                    <td className="cgo-r"><Signed v={t.ledger_cash} /></td>
                                    <td className="cgo-r"><Signed v={t.month_ggr} /></td>
                                    <td className="cgo-r"><Signed v={t.lifetime_ggr} /></td>
                                </tr>
                            ))}
                            {tenants.length > 0 && (
                                <tr style={{ background: 'var(--cg-ink-elevated)' }}>
                                    <td><span className="cgo-name">All tenants</span></td>
                                    <td className="cgo-r"><span className="cgo-odds">{formatCount(totals.funded)} / {formatCount(totals.wallets)}</span></td>
                                    <td className="cgo-r"><span className="cgo-stake"><span className="cgo-ccy">NAD</span>{nad(totals.owed)}</span></td>
                                    <td className="cgo-r"><span className="cgo-payout">{nad(totals.dormant)}</span></td>
                                    <td className="cgo-r"><span className="cgo-payout">{nad(totals.held)}</span></td>
                                    <td className="cgo-r"><span className="cgo-payout">{nad(totals.staff_deposits)}</span></td>
                                    <td className="cgo-r"><span className="cgo-payout">{nad(totals.player_deposits)}</span></td>
                                    <td className="cgo-r"><span className="cgo-payout">{nad(totals.paid_out)}</span></td>
                                    <td className="cgo-r"><Signed v={totals.ledger_cash} bold /></td>
                                    <td className="cgo-r"><Signed v={totals.month_ggr} bold /></td>
                                    <td className="cgo-r"><Signed v={totals.lifetime_ggr} bold /></td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                    <div className="cgo-table-foot">
                        <span>
                            Dormant is balance in wallets untouched for {dormant_days} days; it is still owed. Staff deposits were credited in the admin; check they were cash-backed before treating ledger cash as money in the bank. Ledger cash is deposits less paid withdrawals; if it differs from the bank balance, the difference is what left the business account.
                        </span>
                    </div>
                </div>
            </div>
        </UserLayout>
    );
}
