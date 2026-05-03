import UserLayout from '@/layouts/user-layout';
import { Head } from '@inertiajs/react';
import { Column } from 'primereact/column';
import { DataTable } from 'primereact/datatable';
import { useEffect, useState } from 'react';

interface RevenueTotals {
    total_bets: number;
    total_wins: number;
    gross_gaming_revenue: number;
    tax_amount: number;
    net_gaming_revenue: number;
    chinga_share: number;
    tenant_share: number;
}

interface PerTenantRow {
    tenant_id: number;
    tenant?: { uuid: string; name: string; business_model?: 'reseller' | 'direct' };
    business_model?: 'reseller' | 'direct';
    total_bets: number;
    gross_gaming_revenue: number;
    tax_amount: number;
    net_gaming_revenue: number;
    chinga_share: number;
    tenant_share: number;
}

interface RevenueSummary {
    totals: RevenueTotals;
    per_tenant: PerTenantRow[];
}

function fmt(value: number | string | null | undefined): string {
    const n = typeof value === 'string' ? parseFloat(value) : (value ?? 0);
    return `NAD ${Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

export default function RevenueIndex() {
    const [summary, setSummary] = useState<RevenueSummary | null>(null);
    const [loading, setLoading] = useState(true);

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
                        {/* Waterfall: GGR → tax → NGR → splits */}
                        <div
                            className="rounded-xl p-5"
                            style={{
                                background: 'var(--acu-surface-card)',
                                border: '1px solid var(--acu-border)',
                            }}
                        >
                            <div className="text-sm font-semibold mb-4" style={{ color: 'var(--acu-text)' }}>
                                Revenue waterfall (this period)
                            </div>
                            <div className="grid grid-cols-1 md:grid-cols-7 gap-3 items-center text-sm">
                                <WaterfallStep label="Bets" value={fmt(summary.totals.total_bets)} tone="neutral" />
                                <WaterfallSign sign="−" />
                                <WaterfallStep label="Wins" value={fmt(summary.totals.total_wins)} tone="neutral" />
                                <WaterfallSign sign="=" />
                                <WaterfallStep label="GGR" value={fmt(summary.totals.gross_gaming_revenue)} tone="primary" />
                                <WaterfallSign sign="−" />
                                <WaterfallStep label="Tax" value={fmt(summary.totals.tax_amount)} tone="warn" />
                            </div>
                            <div className="grid grid-cols-1 md:grid-cols-7 gap-3 items-center text-sm mt-3">
                                <WaterfallStep label="NGR" value={fmt(summary.totals.net_gaming_revenue)} tone="primary" />
                                <WaterfallSign sign="=" />
                                <WaterfallStep label="Tenant share" value={fmt(summary.totals.tenant_share)} tone="success" />
                                <WaterfallSign sign="+" />
                                <WaterfallStep label="Platform (Chinga)" value={fmt(summary.totals.chinga_share)} tone="success" />
                                <div />
                                <div />
                            </div>
                        </div>

                        <div className="acu-fieldset">
                            <div className="acu-fieldset-header">
                                <span className="acu-fieldset-title">Per Tenant</span>
                            </div>
                            <div className="acu-fieldset-body">
                                <DataTable value={summary.per_tenant} stripedRows dataKey="tenant_id">
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
                                    <Column header="Bets" body={(r: PerTenantRow) => fmt(r.total_bets)} />
                                    <Column header="GGR" body={(r: PerTenantRow) => fmt(r.gross_gaming_revenue)} />
                                    <Column header="Tax" body={(r: PerTenantRow) => fmt(r.tax_amount)} />
                                    <Column header="NGR" body={(r: PerTenantRow) => fmt(r.net_gaming_revenue)} />
                                    <Column header="Tenant share" body={(r: PerTenantRow) => fmt(r.tenant_share)} />
                                    <Column header="Platform" body={(r: PerTenantRow) => fmt(r.chinga_share)} />
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
