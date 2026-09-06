// resources/js/pages/admin/audit-logs.tsx
//
// Security audit trail, brass-on-ink to match /tenant-overview.
// Page head → quick-category chips + search → table → pager.
// Detail row (old/new values) is collapsed by default and toggled
// per-row to keep the index dense.

import UserLayout from '@/layouts/user-layout';
import { Head } from '@inertiajs/react';
import { Fragment, useEffect, useState } from 'react';

interface AuditLog {
    id: number;
    user: { uuid: string; name: string; email: string } | null;
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

const QUICK_FILTERS = [
    { label: 'All', value: '' },
    { label: 'Auth', value: 'auth' },
    { label: 'Login', value: 'login' },
    { label: 'KYC', value: 'kyc' },
    { label: 'Admin', value: 'admin' },
    { label: 'Wallet', value: 'wallet' },
    { label: 'Security', value: 'security' },
];

const DATETIME_FMT = new Intl.DateTimeFormat('en-GB', {
    day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit',
});

function formatDateTime(iso: string): string {
    return DATETIME_FMT.format(new Date(iso));
}

function actionColor(action: string | null | undefined): string {
    if (!action) return 'var(--cg-fg-3)';
    if (action.startsWith('security') || action.includes('.failed') || action.includes('.locked')) {
        return 'var(--cg-neg)';
    }
    if (action.startsWith('admin') || action.includes('.deleted') || action.includes('.banned')) {
        return 'var(--cg-warn)';
    }
    if (action.startsWith('auth') || action.includes('.login') || action.includes('.created')) {
        return 'var(--cg-pos)';
    }
    return 'var(--cg-fg-2)';
}

function formatAction(action: string | null | undefined): string {
    if (!action) return '—';
    return action.split('.').map((p) => p.charAt(0).toUpperCase() + p.slice(1)).join(' › ');
}

export default function AuditLogs() {
    const [logs, setLogs] = useState<AuditLog[]>([]);
    const [meta, setMeta] = useState<Meta | null>(null);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [quickFilter, setQuickFilter] = useState('');
    const [page, setPage] = useState(1);
    const [expanded, setExpanded] = useState<Record<number, boolean>>({});

    const fetchLogs = async (pageNum?: number) => {
        setLoading(true);
        const fetchPage = pageNum ?? page;
        try {
            const params = new URLSearchParams();
            // Quick-filter chips set the action prefix; the free-text input
            // adds further substring filtering.
            const action = [quickFilter, search].filter(Boolean).join('.');
            if (action) params.append('action', action);
            params.append('page', fetchPage.toString());

            const response = await fetch(`/api/v1/admin/audit-logs?${params}`, {
                headers: { Accept: 'application/json' },
            });
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
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [page, quickFilter]);

    const submitSearch = () => { setPage(1); fetchLogs(1); };

    const toggleExpand = (id: number) => {
        setExpanded((curr) => ({ ...curr, [id]: !curr[id] }));
    };

    const hasDetails = (log: AuditLog) =>
        (log.old_values && Object.keys(log.old_values).length > 0) ||
        (log.new_values && Object.keys(log.new_values).length > 0);

    return (
        <UserLayout title="Audit logs">
            <Head title="Audit logs · Admin" />

            <div className="cgo-page">
                {/* Page header */}
                <div className="cgo-page-head">
                    <div>
                        <div className="cgo-eyebrow">Admin</div>
                        <h1 className="cgo-title">Audit logs</h1>
                        <div className="cgo-subtitle">
                            Security and activity audit trail — every privileged action.
                        </div>
                    </div>
                    <div style={{ display: 'flex', gap: 8 }}>
                        <button
                            type="button"
                            className="cg-btn cg-btn--ghost cg-btn--sm"
                            onClick={() => fetchLogs()}
                        >
                            Refresh
                        </button>
                    </div>
                </div>

                {/* Filter bar */}
                <div className="cgo-filterbar">
                    {QUICK_FILTERS.map((f) => (
                        <button
                            key={f.value || 'all'}
                            type="button"
                            className={`cgo-chip${quickFilter === f.value ? ' active' : ''}`}
                            onClick={() => { setQuickFilter(f.value); setPage(1); }}
                        >
                            {f.label}
                        </button>
                    ))}
                    <div className="cgo-right">
                        <label className="cgo-input">
                            <input
                                type="text"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                onKeyDown={(e) => e.key === 'Enter' && submitSearch()}
                                placeholder="Filter by action substring (e.g., reset, lock)…"
                                style={{ minWidth: 260 }}
                            />
                        </label>
                        <button
                            type="button"
                            className="cg-btn cg-btn--ghost cg-btn--sm"
                            onClick={submitSearch}
                        >
                            Filter
                        </button>
                    </div>
                </div>

                {/* Logs table */}
                <div
                    className="cgo-table-wrap cgo-table-wrap--scroll"
                    style={{ borderRadius: '0 0 8px 8px', borderTop: 0 }}
                >
                    <table className="cgo-wagers">
                        <thead>
                            <tr>
                                <th style={{ width: 180 }}>Timestamp</th>
                                <th style={{ minWidth: 200 }}>User</th>
                                <th style={{ width: 200 }}>Action</th>
                                <th>Description</th>
                                <th style={{ width: 130 }}>IP</th>
                                <th style={{ width: 60 }} />
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr>
                                    <td colSpan={6} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>
                                        Loading…
                                    </td>
                                </tr>
                            ) : logs.length === 0 ? (
                                <tr>
                                    <td colSpan={6} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>
                                        No log entries match the current filter.
                                    </td>
                                </tr>
                            ) : (
                                logs.map((log) => (
                                    <Fragment key={log.id}>
                                        <tr>
                                            <td>
                                                <span className="cgo-uid">{formatDateTime(log.created_at)}</span>
                                            </td>
                                            <td style={{ maxWidth: 240 }}>
                                                {log.user ? (
                                                    <>
                                                        <div className="cgo-name cgo-cell-clip" title={log.user.name}>
                                                            {log.user.name}
                                                        </div>
                                                        <div className="cgo-uid">{log.user.email}</div>
                                                    </>
                                                ) : (
                                                    <span style={{ fontStyle: 'italic', color: 'var(--cg-fg-3)' }}>
                                                        System
                                                    </span>
                                                )}
                                            </td>
                                            <td>
                                                <span
                                                    style={{
                                                        display: 'inline-block',
                                                        padding: '2px 8px',
                                                        border: `1px solid ${actionColor(log.action)}`,
                                                        borderRadius: 4,
                                                        fontSize: 11,
                                                        color: actionColor(log.action),
                                                        fontFamily: 'var(--cg-mono)',
                                                        letterSpacing: '0.02em',
                                                    }}
                                                >
                                                    {formatAction(log.action)}
                                                </span>
                                            </td>
                                            <td style={{ maxWidth: 360 }}>
                                                <div className="cgo-cell-clip" style={{ fontSize: 12, color: 'var(--cg-fg-1)' }}>
                                                    {log.description}
                                                </div>
                                            </td>
                                            <td>
                                                <span className="cgo-uid" style={{ fontFamily: 'var(--cg-mono)' }}>
                                                    {log.ip_address || '—'}
                                                </span>
                                            </td>
                                            <td>
                                                {hasDetails(log) ? (
                                                    <button
                                                        type="button"
                                                        className="cgo-row-action"
                                                        aria-label={expanded[log.id] ? 'Hide details' : 'Show details'}
                                                        title={expanded[log.id] ? 'Hide details' : 'Show details'}
                                                        onClick={() => toggleExpand(log.id)}
                                                    >
                                                        {expanded[log.id] ? '−' : '+'}
                                                    </button>
                                                ) : null}
                                            </td>
                                        </tr>
                                        {expanded[log.id] && hasDetails(log) && (
                                            <tr>
                                                <td
                                                    colSpan={6}
                                                    style={{
                                                        background: 'var(--cg-ink-elevated)',
                                                        padding: 16,
                                                    }}
                                                >
                                                    <div
                                                        style={{
                                                            display: 'grid',
                                                            gridTemplateColumns: '1fr 1fr',
                                                            gap: 16,
                                                            fontSize: 11,
                                                            fontFamily: 'var(--cg-mono)',
                                                        }}
                                                    >
                                                        <div>
                                                            <div className="cgo-uid" style={{ marginBottom: 4 }}>Old values</div>
                                                            <pre
                                                                style={{
                                                                    margin: 0,
                                                                    padding: 8,
                                                                    background: 'var(--cg-ink-card)',
                                                                    border: '1px solid var(--cg-rule)',
                                                                    borderRadius: 4,
                                                                    color: 'var(--cg-fg-2)',
                                                                    overflow: 'auto',
                                                                    maxHeight: 200,
                                                                }}
                                                            >
                                                                {log.old_values
                                                                    ? JSON.stringify(log.old_values, null, 2)
                                                                    : '—'}
                                                            </pre>
                                                        </div>
                                                        <div>
                                                            <div className="cgo-uid" style={{ marginBottom: 4 }}>New values</div>
                                                            <pre
                                                                style={{
                                                                    margin: 0,
                                                                    padding: 8,
                                                                    background: 'var(--cg-ink-card)',
                                                                    border: '1px solid var(--cg-rule)',
                                                                    borderRadius: 4,
                                                                    color: 'var(--cg-fg-2)',
                                                                    overflow: 'auto',
                                                                    maxHeight: 200,
                                                                }}
                                                            >
                                                                {log.new_values
                                                                    ? JSON.stringify(log.new_values, null, 2)
                                                                    : '—'}
                                                            </pre>
                                                        </div>
                                                    </div>
                                                    {log.user_agent && (
                                                        <div
                                                            style={{
                                                                marginTop: 12,
                                                                paddingTop: 12,
                                                                borderTop: '1px solid var(--cg-rule)',
                                                                fontSize: 11,
                                                                color: 'var(--cg-fg-3)',
                                                                fontFamily: 'var(--cg-mono)',
                                                                wordBreak: 'break-all',
                                                            }}
                                                        >
                                                            <strong style={{ color: 'var(--cg-fg-2)' }}>User agent:</strong>{' '}
                                                            {log.user_agent}
                                                        </div>
                                                    )}
                                                </td>
                                            </tr>
                                        )}
                                    </Fragment>
                                ))
                            )}
                        </tbody>
                    </table>

                    {/* Footer + pager */}
                    {meta && (
                        <div className="cgo-table-foot">
                            <span>
                                {meta.total > 0 ? (
                                    <>
                                        Showing <b className="cgo-mono">{logs.length}</b> of{' '}
                                        <b className="cgo-mono">{meta.total.toLocaleString()}</b> events
                                    </>
                                ) : (
                                    'No events'
                                )}
                            </span>
                            {meta.last_page > 1 && (
                                <div className="cgo-pager">
                                    <button
                                        type="button"
                                        disabled={meta.current_page === 1}
                                        onClick={() => setPage((p) => Math.max(1, p - 1))}
                                        aria-label="Previous"
                                    >
                                        ‹
                                    </button>
                                    <button type="button" className="curr" disabled>
                                        {meta.current_page}
                                    </button>
                                    <button
                                        type="button"
                                        disabled={meta.current_page === meta.last_page}
                                        onClick={() => setPage((p) => Math.min(meta.last_page, p + 1))}
                                        aria-label="Next"
                                    >
                                        ›
                                    </button>
                                </div>
                            )}
                        </div>
                    )}
                </div>
            </div>
        </UserLayout>
    );
}
