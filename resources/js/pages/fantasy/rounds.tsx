import PageHeader from '@/components/acumatica/Common/PageHeader';
import StatusBadge from '@/components/acumatica/Common/StatusBadge';
import UserLayout from '@/layouts/user-layout';
import type { StatusVariant } from '@/types/acumatica';
import { Head, router } from '@inertiajs/react';
import { Button } from 'primereact/button';
import { Column } from 'primereact/column';
import { DataTable } from 'primereact/datatable';
import { Dropdown } from 'primereact/dropdown';
import { useState } from 'react';

interface Round {
    id: number;
    round_number: number;
    tenant_uuid: string | null;
    start_time: string;
    end_time: string | null;
    winning_team_ids: number[] | null;
    bet_count: number;
    total_wagered: string;
    total_paid_out: string;
}

interface TenantOption {
    uuid: string;
    name: string;
    slug: string;
}

interface Filters {
    tenant_uuid: string | null;
    page: number;
    per_page: number;
}

interface Props {
    rounds: Round[];
    tenants: TenantOption[];
    filters: Filters;
    error: string | null;
    lockedTenantUuid?: string | null;
    listHref?: string;
    detailHrefBase?: string;
}

function formatCurrency(amount: string | number): string {
    const n = typeof amount === 'string' ? parseFloat(amount) : amount;
    return `NAD ${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function deriveStatus(round: Round): { label: string; variant: StatusVariant } {
    if (round.end_time && round.winning_team_ids && round.winning_team_ids.length > 0) {
        return { label: 'completed', variant: 'active' };
    }
    if (round.end_time) {
        return { label: 'finished', variant: 'pending' };
    }
    return { label: 'in progress', variant: 'pending' };
}

export default function Rounds({
    rounds = [],
    tenants = [],
    filters,
    error,
    lockedTenantUuid = null,
    listHref = '/fantasy/rounds',
    detailHrefBase = '/fantasy/rounds',
}: Props) {
    const [tenantUuid, setTenantUuid] = useState<string | null>(filters?.tenant_uuid ?? null);
    const tenantLocked = !!lockedTenantUuid;

    const applyFilters = (next: { tenant_uuid?: string | null; page?: number }) => {
        const params: Record<string, string | number> = {};
        const nextTenant = next.tenant_uuid !== undefined ? next.tenant_uuid : tenantUuid;
        if (nextTenant && !tenantLocked) params.tenant_uuid = nextTenant;
        if (next.page && next.page > 1) params.page = next.page;
        router.get(listHref, params, { preserveState: true, preserveScroll: true });
    };

    const tenantOptions = [
        { label: 'All tenants', value: null },
        ...tenants.map((t) => ({ label: t.name, value: t.uuid })),
    ];

    const isEmpty = !rounds || rounds.length === 0;
    const page = filters?.page ?? 1;
    const perPage = filters?.per_page ?? 25;
    const hasNext = rounds.length === perPage;

    return (
        <UserLayout title="Fantasy Rounds">
            <Head title="Fantasy Rounds" />

            <div className="space-y-6">
                <PageHeader title="Fantasy Rounds" subtitle="Monitor game rounds and results across tenants" />

                {error && (
                    <div
                        className="rounded-xl px-5 py-4 text-sm"
                        style={{
                            background: 'rgba(248, 81, 73, 0.04)',
                            border: '1px solid rgba(248, 81, 73, 0.15)',
                            color: 'var(--acu-text)',
                        }}
                    >
                        <i className="pi pi-exclamation-triangle mr-2" style={{ color: '#F85149' }} />
                        {error}
                    </div>
                )}

                {!tenantLocked && (
                    <div className="flex flex-wrap gap-3 items-end">
                        <div className="flex flex-col gap-1">
                            <label className="text-xs font-medium" style={{ color: 'var(--acu-text-light)' }}>
                                Tenant
                            </label>
                            <Dropdown
                                value={tenantUuid}
                                options={tenantOptions}
                                onChange={(e) => {
                                    setTenantUuid(e.value);
                                    applyFilters({ tenant_uuid: e.value, page: 1 });
                                }}
                                placeholder="All tenants"
                                style={{ minWidth: '14rem' }}
                                showClear
                            />
                        </div>
                    </div>
                )}

                {isEmpty ? (
                    <div
                        className="rounded-xl p-12 text-center"
                        style={{
                            background: 'var(--acu-surface-card)',
                            border: '1px solid var(--acu-border)',
                        }}
                    >
                        <div
                            className="w-16 h-16 rounded-xl flex items-center justify-center mx-auto mb-4"
                            style={{ background: 'var(--acu-surface-hover)' }}
                        >
                            <i className="pi pi-clock text-2xl" style={{ color: 'var(--acu-text-light)' }} />
                        </div>
                        <h3
                            className="text-lg font-semibold mb-2"
                            style={{ color: 'var(--acu-text)', fontFamily: 'var(--font-display)' }}
                        >
                            No Rounds
                        </h3>
                        <p className="text-sm" style={{ color: 'var(--acu-text-light)', maxWidth: '28rem', margin: '0 auto' }}>
                            No rounds match the current filters.
                        </p>
                    </div>
                ) : (
                    <div className="acu-fieldset" style={{ '--fieldset-color': 'var(--acu-fieldset-gold)' } as React.CSSProperties}>
                        <div className="acu-fieldset-header">
                            <div className="acu-fieldset-title">
                                <i className="pi pi-history" />
                                <span>Rounds</span>
                                <span className="text-xs font-normal ml-1" style={{ color: 'var(--acu-text-light)' }}>
                                    ({rounds.length})
                                </span>
                            </div>
                        </div>
                        <div className="acu-fieldset-body p-0">
                            <DataTable
                                value={rounds}
                                size="small"
                                showGridlines={false}
                                emptyMessage="No rounds found"
                                onRowClick={(e) => router.get(`${detailHrefBase}/${(e.data as Round).id}`)}
                                rowHover
                                dataKey="id"
                            >
                                <Column
                                    header="Round #"
                                    body={(row: Round) => (
                                        <span className="font-medium text-sm" style={{ color: 'var(--acu-text)' }}>
                                            #{row.round_number}
                                        </span>
                                    )}
                                />
                                <Column
                                    header="Started"
                                    body={(row: Round) => (
                                        <span className="text-sm" style={{ color: 'var(--acu-text-muted)' }}>
                                            {new Date(row.start_time).toLocaleString()}
                                        </span>
                                    )}
                                />
                                <Column
                                    header="Bets"
                                    body={(row: Round) => (
                                        <span className="text-sm" style={{ color: 'var(--acu-text-muted)' }}>
                                            {row.bet_count.toLocaleString()}
                                        </span>
                                    )}
                                />
                                <Column
                                    header="Wagered"
                                    body={(row: Round) => (
                                        <span className="text-sm" style={{ color: 'var(--acu-text-muted)' }}>
                                            {formatCurrency(row.total_wagered)}
                                        </span>
                                    )}
                                />
                                <Column
                                    header="Paid Out"
                                    body={(row: Round) => (
                                        <span className="text-sm" style={{ color: 'var(--acu-text-muted)' }}>
                                            {formatCurrency(row.total_paid_out)}
                                        </span>
                                    )}
                                />
                                <Column
                                    header="Status"
                                    body={(row: Round) => {
                                        const s = deriveStatus(row);
                                        return <StatusBadge status={s.variant} label={s.label} />;
                                    }}
                                />
                                <Column
                                    header=""
                                    body={() => (
                                        <i className="pi pi-chevron-right" style={{ color: 'var(--acu-text-light)' }} />
                                    )}
                                    style={{ width: '2rem' }}
                                />
                            </DataTable>
                        </div>
                        <div className="flex items-center justify-between p-3 border-t" style={{ borderColor: 'var(--acu-border)' }}>
                            <span className="text-xs" style={{ color: 'var(--acu-text-light)' }}>
                                Page {page}
                            </span>
                            <div className="flex gap-2">
                                <Button
                                    icon="pi pi-chevron-left"
                                    label="Previous"
                                    size="small"
                                    severity="secondary"
                                    text
                                    disabled={page <= 1}
                                    onClick={() => applyFilters({ page: page - 1 })}
                                />
                                <Button
                                    icon="pi pi-chevron-right"
                                    iconPos="right"
                                    label="Next"
                                    size="small"
                                    severity="secondary"
                                    text
                                    disabled={!hasNext}
                                    onClick={() => applyFilters({ page: page + 1 })}
                                />
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </UserLayout>
    );
}
