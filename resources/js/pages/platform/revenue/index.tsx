import UserLayout from '@/layouts/user-layout';
import { formatMoney, useCurrencies, type Amount, type CurrencyProfile, type CurrencyTotal } from '@/lib/money';
import { Head } from '@inertiajs/react';
import { Column } from 'primereact/column';
import { DataTable } from 'primereact/datatable';
import { useEffect, useState } from 'react';

type RevenueField =
    | 'total_bets'
    | 'total_wins'
    | 'gross_gaming_revenue'
    | 'tax_amount'
    | 'net_gaming_revenue'
    | 'chinga_share'
    | 'tenant_share';

interface PerTenantRow {
    tenant_id: number;
    tenant?: { uuid: string; name: string; business_model?: 'reseller' | 'direct' };
    business_model?: 'reseller' | 'direct';
    /** The tenant's currency; every figure on the row is in it. */
    currency: string;
    total_bets: string;
    gross_gaming_revenue: string;
    tax_amount: string;
    net_gaming_revenue: string;
    chinga_share: string;
    tenant_share: string;
}

interface RevenueSummary {
    /** One entry per currency: different currencies are never added together. */
    totals: CurrencyTotal<RevenueField>[];
    per_tenant: PerTenantRow[];
}

export default function RevenueIndex() {
    const [summary, setSummary] = useState<RevenueSummary | null>(null);
    const [loading, setLoading] = useState(true);
    const profileOf = useCurrencies();
    const money = (v: Amount, code: string): string => {
        const p: CurrencyProfile | null = profileOf(code);
        return p ? formatMoney(v, p) : '—';
    };

    useEffect(() => {
        fetch('/api/v1/platform/revenue/summary')
            .then((res) => res.json())
            .then((data) => {
                setSummary(data);
                setLoading(false);
            });
    }, []);

    return (
        <UserLayout title="Revenue">
            <Head title="Revenue" />

            <div className="space-y-6">
                <h1 className="text-2xl font-bold">Revenue Dashboard</h1>

                {loading ? (
                    <div className="text-center py-8">Loading…</div>
                ) : summary ? (
                    <>
                        {/* Waterfall: GGR → tax → NGR → splits, one per currency (never added across currencies). */}
                        {summary.totals.length === 0 ? (
                            <div
                                className="rounded-xl p-5 text-sm"
                                style={{
                                    background: 'var(--acu-surface-card)',
                                    border: '1px solid var(--acu-border)',
                                    color: 'var(--acu-text-light)',
                                }}
                            >
                                No revenue recorded in this period.
                            </div>
                        ) : (
                            summary.totals.map((t) => {
                                const f = (field: RevenueField) => money(t[field], t.currency);
                                return (
                                    <div
                                        key={t.currency}
                                        className="rounded-xl p-5"
                                        style={{
                                            background: 'var(--acu-surface-card)',
                                            border: '1px solid var(--acu-border)',
                                        }}
                                    >
                                        <div className="text-sm font-semibold mb-4" style={{ color: 'var(--acu-text)' }}>
                                            Revenue waterfall (this period) · {t.currency}
                                        </div>
                                        <div className="grid grid-cols-1 md:grid-cols-7 gap-3 items-center text-sm">
                                            <WaterfallStep label="Bets" value={f('total_bets')} tone="neutral" />
                                            <WaterfallSign sign="−" />
                                            <WaterfallStep label="Wins" value={f('total_wins')} tone="neutral" />
                                            <WaterfallSign sign="=" />
                                            <WaterfallStep label="GGR" value={f('gross_gaming_revenue')} tone="primary" />
                                            <WaterfallSign sign="−" />
                                            <WaterfallStep label="Tax" value={f('tax_amount')} tone="warn" />
                                        </div>
                                        <div className="grid grid-cols-1 md:grid-cols-7 gap-3 items-center text-sm mt-3">
                                            <WaterfallStep label="NGR" value={f('net_gaming_revenue')} tone="primary" />
                                            <WaterfallSign sign="=" />
                                            <WaterfallStep label="Tenant share" value={f('tenant_share')} tone="success" />
                                            <WaterfallSign sign="+" />
                                            <WaterfallStep label="Platform (Chinga)" value={f('chinga_share')} tone="success" />
                                            <div />
                                            <div />
                                        </div>
                                    </div>
                                );
                            })
                        )}

                        <div className="acu-fieldset">
                            <div className="acu-fieldset-header">
                                <span className="acu-fieldset-title">Per Tenant</span>
                            </div>
                            <div className="acu-fieldset-body">
                                {/* Below md the table keeps a readable width and scrolls inside its wrapper. */}
                                <DataTable value={summary.per_tenant} stripedRows dataKey="tenant_id" tableClassName="max-md:min-w-[50rem]">
                                    <Column
                                        header="Tenant"
                                        body={(row: PerTenantRow) => row.tenant?.name || '—'}
                                        sortable
                                    />
                                    <Column
                                        header="Model"
                                        body={(row: PerTenantRow) => {
                                            const model = row.business_model ?? row.tenant?.business_model ?? 'reseller';
                                            return (
                                                <span
                                                    className="inline-block px-2 py-0.5 rounded text-xs font-medium"
                                                    style={{
                                                        background: model === 'direct' ? 'rgba(88, 166, 255, 0.15)' : 'rgba(63, 185, 80, 0.12)',
                                                        color: model === 'direct' ? '#58A6FF' : '#3FB950',
                                                    }}
                                                >
                                                    {model}
                                                </span>
                                            );
                                        }}
                                    />
                                    <Column header="Bets" body={(r: PerTenantRow) => money(r.total_bets, r.currency)} />
                                    <Column header="GGR" body={(r: PerTenantRow) => money(r.gross_gaming_revenue, r.currency)} />
                                    <Column header="Tax" body={(r: PerTenantRow) => money(r.tax_amount, r.currency)} />
                                    <Column header="NGR" body={(r: PerTenantRow) => money(r.net_gaming_revenue, r.currency)} />
                                    <Column header="Tenant share" body={(r: PerTenantRow) => money(r.tenant_share, r.currency)} />
                                    <Column header="Platform" body={(r: PerTenantRow) => money(r.chinga_share, r.currency)} />
                                </DataTable>
                            </div>
                        </div>
                    </>
                ) : null}
            </div>
        </UserLayout>
    );
}

type Tone = 'neutral' | 'primary' | 'warn' | 'success';

function WaterfallStep({ label, value, tone }: { label: string; value: string; tone: Tone }) {
    const palette: Record<Tone, { bg: string; border: string; color: string }> = {
        neutral: { bg: 'var(--acu-surface-elevated)', border: 'var(--acu-border)', color: 'var(--acu-text)' },
        primary: { bg: 'rgba(88, 166, 255, 0.10)', border: 'rgba(88, 166, 255, 0.3)', color: '#58A6FF' },
        warn: { bg: 'rgba(210, 153, 34, 0.10)', border: 'rgba(210, 153, 34, 0.3)', color: '#D29922' },
        success: { bg: 'rgba(63, 185, 80, 0.12)', border: 'rgba(63, 185, 80, 0.3)', color: '#3FB950' },
    };
    const p = palette[tone];
    return (
        <div
            className="rounded-lg px-3 py-2"
            style={{ background: p.bg, border: `1px solid ${p.border}` }}
        >
            <div className="text-xs uppercase tracking-wide" style={{ color: 'var(--acu-text-light)' }}>{label}</div>
            <div className="text-sm font-semibold" style={{ color: p.color }}>{value}</div>
        </div>
    );
}

function WaterfallSign({ sign }: { sign: '−' | '+' | '=' }) {
    return (
        <div className="text-center text-lg font-bold" style={{ color: 'var(--acu-text-light)' }}>
            {sign}
        </div>
    );
}
