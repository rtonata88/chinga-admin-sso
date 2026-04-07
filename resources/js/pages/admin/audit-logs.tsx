import PageHeader from '@/components/acumatica/Common/PageHeader';
import UserLayout from '@/layouts/user-layout';
import { Head } from '@inertiajs/react';
import { Button } from 'primereact/button';
import { Column } from 'primereact/column';
import { DataTable } from 'primereact/datatable';
import { InputText } from 'primereact/inputtext';
import { useEffect, useState } from 'react';

interface AuditLog {
    id: number;
    user: {
        uuid: string;
        name: string;
        email: string;
    } | null;
    action: string;
    description: string;
    ip_address: string;
    user_agent: string;
    old_values: Record<string, unknown> | null;
    new_values: Record<string, unknown> | null;
    created_at: string;
}

interface Meta {
    current_page: number;
    last_page: number;
    total: number;
}

export default function AuditLogs() {
    const [logs, setLogs] = useState<AuditLog[]>([]);
    const [meta, setMeta] = useState<Meta | null>(null);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const [first, setFirst] = useState(0);
    const rowsPerPage = 15;

    const fetchLogs = async (pageNum?: number) => {
        setLoading(true);
        const fetchPage = pageNum ?? page;
        try {
            const params = new URLSearchParams();
            if (search) params.append('action', search);
            params.append('page', fetchPage.toString());

            const response = await fetch(
                `/api/v1/admin/audit-logs?${params}`,
                { headers: { Accept: 'application/json' } },
            );
            const data = await response.json();
            if (data.success) {
                setLogs(data.data);
                setMeta(data.meta);
            }
        } catch (error) {
            console.error('Failed to fetch logs:', error);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        fetchLogs();
    }, [page]);

    const handleSearch = () => {
        setPage(1);
        setFirst(0);
        fetchLogs(1);
    };

    const onPageChange = (e: { first: number; page: number }) => {
        setFirst(e.first);
        setPage(e.page + 1);
    };

    const formatAction = (action: string) => {
        return action
            .split('.')
            .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
            .join(' > ');
    };

    const timestampTemplate = (rowData: AuditLog) => (
        <span
            style={{
                fontSize: '0.875rem',
                whiteSpace: 'nowrap',
                color: 'var(--acu-text-muted)',
                fontFamily: 'var(--font-body)',
            }}
        >
            {new Date(rowData.created_at).toLocaleString()}
        </span>
    );

    const userTemplate = (rowData: AuditLog) => {
        if (rowData.user) {
            return (
                <div>
                    <div
                        style={{
                            fontSize: '0.875rem',
                            fontWeight: 500,
                            color: 'var(--acu-text)',
                            fontFamily: 'var(--font-body)',
                        }}
                    >
                        {rowData.user.name}
                    </div>
                    <div
                        style={{
                            fontSize: '0.75rem',
                            color: 'var(--acu-text-light)',
                            fontFamily: 'var(--font-body)',
                        }}
                    >
                        {rowData.user.email}
                    </div>
                </div>
            );
        }
        return (
            <span
                style={{
                    fontSize: '0.875rem',
                    color: 'var(--acu-text-muted)',
                    fontFamily: 'var(--font-body)',
                }}
            >
                System
            </span>
        );
    };

    const actionTemplate = (rowData: AuditLog) => (
        <code
            style={{
                background: 'var(--acu-surface-elevated)',
                color: 'var(--acu-primary)',
                border: '1px solid var(--acu-border)',
                borderRadius: '6px',
                padding: '2px 8px',
                fontSize: '0.75rem',
                fontFamily: 'var(--font-body)',
            }}
        >
            {formatAction(rowData.action)}
        </code>
    );

    const descriptionTemplate = (rowData: AuditLog) => (
        <span
            style={{
                fontSize: '0.875rem',
                color: 'var(--acu-text)',
                fontFamily: 'var(--font-body)',
                maxWidth: '300px',
                display: 'block',
                overflow: 'hidden',
                textOverflow: 'ellipsis',
                whiteSpace: 'nowrap',
            }}
        >
            {rowData.description}
        </span>
    );

    const ipTemplate = (rowData: AuditLog) => (
        <span
            style={{
                fontSize: '0.875rem',
                color: 'var(--acu-text-muted)',
                fontFamily: 'var(--font-body)',
            }}
        >
            {rowData.ip_address}
        </span>
    );

    return (
        <UserLayout title="Audit Logs">
            <Head title="Audit Logs" />

            <div className="space-y-8">
                <PageHeader title="Audit Logs" subtitle="Security and activity audit trail">
                    <Button
                        label="Refresh"
                        icon="pi pi-refresh"
                        severity="secondary"
                        outlined
                        size="small"
                        onClick={() => fetchLogs()}
                    />
                </PageHeader>

                {/* Search / Filter */}
                <div
                    className="rounded-xl p-4"
                    style={{
                        background: 'var(--acu-surface-card)',
                        border: '1px solid var(--acu-border)',
                    }}
                >
                    <div className="flex gap-3 items-center">
                        <InputText
                            placeholder="Filter by action (e.g., login, kyc, admin)..."
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                            className="w-full max-w-sm"
                        />
                        <Button
                            label="Filter"
                            icon="pi pi-search"
                            size="small"
                            onClick={handleSearch}
                        />
                    </div>
                </div>

                {/* Logs Table */}
                <div
                    className="acu-fieldset"
                    style={{ '--fieldset-color': 'var(--acu-fieldset-gold)' } as React.CSSProperties}
                >
                    <div className="acu-fieldset-header">
                        <div className="acu-fieldset-title">
                            <i className="pi pi-list" />
                            <span style={{ fontFamily: 'var(--font-display)' }}>Activity Log</span>
                            {meta?.total != null && (
                                <span
                                    style={{
                                        fontSize: '0.75rem',
                                        fontWeight: 400,
                                        color: 'var(--acu-text-light)',
                                        marginLeft: '0.25rem',
                                        fontFamily: 'var(--font-body)',
                                    }}
                                >
                                    ({meta.total} total events)
                                </span>
                            )}
                        </div>
                    </div>
                    <div className="acu-fieldset-body p-0">
                        <DataTable
                            value={logs}
                            loading={loading}
                            size="small"
                            showGridlines={false}
                            emptyMessage="No logs found"
                            paginator
                            rows={rowsPerPage}
                            totalRecords={meta?.total || 0}
                            lazy
                            first={first}
                            onPage={onPageChange}
                            paginatorTemplate="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink CurrentPageReport"
                            currentPageReportTemplate="Page {currentPage} of {totalPages} ({totalRecords} entries)"
                        >
                            <Column
                                header="Timestamp"
                                body={timestampTemplate}
                                style={{ width: '180px' }}
                            />
                            <Column
                                header="User"
                                body={userTemplate}
                                style={{ width: '200px' }}
                            />
                            <Column
                                header="Action"
                                body={actionTemplate}
                                style={{ width: '180px' }}
                            />
                            <Column
                                header="Description"
                                body={descriptionTemplate}
                            />
                            <Column
                                header="IP Address"
                                body={ipTemplate}
                                style={{ width: '140px' }}
                            />
                        </DataTable>
                    </div>
                </div>
            </div>
        </UserLayout>
    );
}
