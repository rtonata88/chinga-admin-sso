// resources/js/pages/platform/games/show.tsx
//
// Game detail, brass-on-ink to match /platform/games. Header → KPI
// strip → Game info + Thumbnail panels → Assigned tenants table.
// Edit dialog stays on PrimeReact (multi-field form).

import { KpiCard, formatCount } from '@/components/operator/kpi-card';
import UserLayout from '@/layouts/user-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { Button } from 'primereact/button';
import { Dialog } from 'primereact/dialog';
import { Dropdown } from 'primereact/dropdown';
import { InputText } from 'primereact/inputtext';
import { InputTextarea } from 'primereact/inputtextarea';
import { Toast } from 'primereact/toast';
import { useEffect, useMemo, useRef, useState } from 'react';

interface GameTenant {
    uuid: string;
    name: string;
    slug: string;
    status: string;
    pivot: { enabled: boolean };
}

interface Game {
    uuid: string;
    name: string;
    slug: string;
    description: string;
    type: string;
    status: string;
    version: string;
    thumbnail_url: string;
    backend_url: string | null;
    launch_url: string | null;
    settings: Record<string, unknown>;
    tenants_count: number;
    tenants: GameTenant[];
    created_at: string;
}

const GAME_TYPE_OPTIONS = [
    { label: 'Slots', value: 'slots' },
    { label: 'Table', value: 'table' },
    { label: 'Instant', value: 'instant' },
    { label: 'Other', value: 'other' },
];

const STATUS_OPTIONS = [
    { label: 'Active', value: 'active' },
    { label: 'Development', value: 'development' },
    { label: 'Inactive', value: 'inactive' },
];

function statusPill(status: string): string {
    switch (status) {
        case 'active': return 'live';
        case 'development': return 'pending';
        case 'suspended': return 'flagged';
        case 'inactive': return 'void';
        default: return 'void';
    }
}

const DATE_FMT = new Intl.DateTimeFormat('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });

interface InfoItem {
    label: string;
    value: React.ReactNode;
    span?: number;
    mono?: boolean;
}

function InfoPanel({ title, items }: { title: string; items: InfoItem[] }) {
    return (
        <div style={{ border: '1px solid var(--cg-rule)', borderRadius: 8, overflow: 'hidden', background: 'var(--cg-ink-card)' }}>
            <div className="cgo-table-bar">
                <div className="cgo-table-bar-title">{title}</div>
            </div>
            <div
                style={{
                    display: 'grid',
                    gridTemplateColumns: 'repeat(12, 1fr)',
                    gap: '14px 18px',
                    padding: '18px',
                }}
            >
                {items.map((it, i) => (
                    <div key={i} style={{ gridColumn: `span ${it.span ?? 6}` }}>
                        <div
                            style={{
                                fontSize: 10,
                                textTransform: 'uppercase',
                                letterSpacing: '0.16em',
                                color: 'var(--cg-fg-3)',
                                fontWeight: 600,
                                marginBottom: 4,
                            }}
                        >
                            {it.label}
                        </div>
                        <div
                            style={{
                                fontSize: 13,
                                color: 'var(--cg-fg-1)',
                                fontFamily: it.mono ? 'var(--cg-mono)' : undefined,
                                fontFeatureSettings: it.mono ? "'tnum' 1" : undefined,
                                wordBreak: 'break-word',
                            }}
                        >
                            {it.value}
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}

export default function GameShow() {
    const [game, setGame] = useState<Game | null>(null);
    const [loading, setLoading] = useState(true);
    const [editOpen, setEditOpen] = useState(false);
    const [saving, setSaving] = useState(false);
    const [editForm, setEditForm] = useState({
        name: '',
        description: '',
        type: 'slots',
        status: 'development',
        version: '',
        thumbnail_url: '',
        backend_url: '',
        launch_url: '',
    });
    const toast = useRef<Toast>(null);

    const { uuid } = usePage<{ uuid: string }>().props;

    const getCsrfToken = () =>
        document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const fetchGame = () => {
        setLoading(true);
        fetch(`/api/v1/platform/games/${uuid}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((res) => res.json())
            .then((res) => {
                setGame(res.data);
                setLoading(false);
            })
            .catch(() => setLoading(false));
    };

    useEffect(() => { fetchGame(); /* eslint-disable-next-line */ }, [uuid]);

    const enabledCount = useMemo(
        () => (game?.tenants ?? []).filter((t) => t.pivot.enabled).length,
        [game]
    );

    const openEditDialog = () => {
        if (!game) return;
        setEditForm({
            name: game.name,
            description: game.description || '',
            type: game.type,
            status: game.status,
            version: game.version || '',
            thumbnail_url: game.thumbnail_url || '',
            backend_url: game.backend_url || '',
            launch_url: game.launch_url || '',
        });
        setEditOpen(true);
    };

    const handleSaveEdit = async () => {
        setSaving(true);
        try {
            const response = await fetch(`/api/v1/platform/games/${uuid}`, {
                method: 'PUT',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify({
                    ...editForm,
                    thumbnail_url: editForm.thumbnail_url || null,
                    backend_url: editForm.backend_url || null,
                    launch_url: editForm.launch_url || null,
                }),
            });
            const data = await response.json();
            if (data.data) {
                setEditOpen(false);
                fetchGame();
                toast.current?.show({ severity: 'success', summary: 'Saved', detail: 'Game updated.' });
            } else {
                toast.current?.show({ severity: 'error', summary: 'Error', detail: data.message || 'Failed to update game.' });
            }
        } catch (error) {
            toast.current?.show({ severity: 'error', summary: 'Error', detail: 'Failed to update game.' });
        } finally {
            setSaving(false);
        }
    };

    if (loading || !game) {
        return (
            <UserLayout title="Game">
                <Head title="Loading…" />
                <div className="cgo-page">
                    <div style={{ color: 'var(--cg-fg-3)', padding: '40px 0' }}>Loading game…</div>
                </div>
            </UserLayout>
        );
    }

    const infoItems: InfoItem[] = [
        { label: 'Slug', value: game.slug, mono: true, span: 6 },
        { label: 'Type', value: <span style={{ textTransform: 'capitalize' }}>{game.type}</span>, span: 6 },
        { label: 'Version', value: game.version || '—', mono: true, span: 6 },
        { label: 'Created', value: DATE_FMT.format(new Date(game.created_at)), mono: true, span: 6 },
        { label: 'Backend URL', value: game.backend_url || '—', mono: true, span: 6 },
        {
            label: 'Launch URL',
            value: game.launch_url ? (
                <a href={game.launch_url} target="_blank" rel="noreferrer" style={{ color: 'var(--cg-brass)' }}>
                    {game.launch_url}
                    <i className="pi pi-external-link" style={{ fontSize: 10, marginLeft: 6 }} />
                </a>
            ) : (
                '—'
            ),
            mono: true,
            span: 6,
        },
        {
            label: 'Description',
            value: game.description || '—',
            span: 12,
        },
    ];

    return (
        <UserLayout title={game.name}>
            <Head title={`${game.name} · Game`} />
            <Toast ref={toast} />

            <div className="cgo-page">
                {/* Page header */}
                <div className="cgo-page-head">
                    <div>
                        <div className="cgo-eyebrow">Platform · Game</div>
                        <h1 className="cgo-title">{game.name}</h1>
                        <div className="cgo-subtitle" style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
                            <span className="cgo-uid" style={{ fontFamily: 'var(--cg-mono)' }}>{game.slug}</span>
                            <span className={`cgo-pill ${statusPill(game.status)}`}>{game.status}</span>
                        </div>
                    </div>
                    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                        <button
                            type="button"
                            className="cg-btn cg-btn--ghost cg-btn--sm"
                            onClick={() => router.visit('/platform/games')}
                        >
                            ← Back
                        </button>
                        <button
                            type="button"
                            className="cg-btn cg-btn--ghost cg-btn--sm"
                            onClick={fetchGame}
                        >
                            Refresh
                        </button>
                        {game.launch_url && (
                            <a
                                href={game.launch_url}
                                target="_blank"
                                rel="noreferrer"
                                className="cg-btn cg-btn--ghost cg-btn--sm"
                                title={game.launch_url}
                            >
                                Open game
                                <i className="pi pi-external-link" style={{ fontSize: 10, marginLeft: 6 }} />
                            </a>
                        )}
                        <button
                            type="button"
                            className="cg-btn cg-btn--primary cg-btn--sm"
                            onClick={openEditDialog}
                        >
                            Edit
                        </button>
                    </div>
                </div>

                {/* KPI strip */}
                <div className="cgo-kpis">
                    <KpiCard
                        label="Type"
                        value={<span style={{ textTransform: 'capitalize' }}>{game.type}</span>}
                        meta="game category"
                    />
                    <KpiCard
                        label="Version"
                        value={game.version || '—'}
                        meta="current build"
                    />
                    <KpiCard
                        label="Tenants assigned"
                        value={formatCount(game.tenants_count)}
                        brass
                        meta={`${enabledCount} enabled`}
                    />
                    <KpiCard
                        label="Status"
                        value={<span style={{ textTransform: 'capitalize' }}>{game.status}</span>}
                        meta={
                            game.status === 'active' ? 'live in production'
                            : game.status === 'development' ? 'not yet live'
                            : 'unavailable'
                        }
                    />
                </div>

                {/* Info + thumbnail */}
                <div
                    style={{
                        display: 'grid',
                        gridTemplateColumns: game.thumbnail_url ? '2fr 1fr' : '1fr',
                        gap: 16,
                        marginBottom: 24,
                    }}
                >
                    <InfoPanel title="Game details" items={infoItems} />
                    {game.thumbnail_url && (
                        <div
                            style={{
                                border: '1px solid var(--cg-rule)',
                                borderRadius: 8,
                                overflow: 'hidden',
                                background: 'var(--cg-ink-card)',
                            }}
                        >
                            <div className="cgo-table-bar">
                                <div className="cgo-table-bar-title">Thumbnail</div>
                            </div>
                            <div
                                style={{
                                    padding: 16,
                                    display: 'grid',
                                    placeItems: 'center',
                                    background: 'var(--cg-ink-elevated)',
                                }}
                            >
                                <img
                                    src={game.thumbnail_url}
                                    alt={game.name}
                                    style={{ maxWidth: '100%', maxHeight: 220, objectFit: 'contain', borderRadius: 4 }}
                                />
                            </div>
                        </div>
                    )}
                </div>

                {/* Assigned tenants */}
                <div
                    className="cgo-table-bar"
                    style={{
                        borderRadius: '8px 8px 0 0',
                        borderTop: '1px solid var(--cg-rule)',
                        borderLeft: '1px solid var(--cg-rule)',
                        borderRight: '1px solid var(--cg-rule)',
                        marginTop: 0,
                    }}
                >
                    <div className="cgo-table-bar-title">
                        Assigned tenants · {game.tenants?.length ?? 0}
                    </div>
                </div>
                <div
                    className="cgo-table-wrap cgo-table-wrap--scroll"
                    style={{ borderRadius: '0 0 8px 8px', marginBottom: 32 }}
                >
                    <table className="cgo-wagers">
                        <thead>
                            <tr>
                                <th style={{ minWidth: 240 }}>Tenant</th>
                                <th style={{ width: 130 }}>Tenant status</th>
                                <th style={{ width: 130 }}>Game enabled</th>
                                <th style={{ width: 70 }} />
                            </tr>
                        </thead>
                        <tbody>
                            {(game.tenants?.length ?? 0) === 0 ? (
                                <tr>
                                    <td colSpan={4} style={{ textAlign: 'center', color: 'var(--cg-fg-3)', padding: '32px 0' }}>
                                        No tenants assigned to this game yet.
                                    </td>
                                </tr>
                            ) : (
                                game.tenants.map((t) => (
                                    <tr key={t.uuid}>
                                        <td>
                                            <div className="cgo-name">{t.name}</div>
                                            <div className="cgo-uid">{t.slug}</div>
                                        </td>
                                        <td>
                                            <span className={`cgo-pill ${statusPill(t.status)}`}>
                                                {t.status}
                                            </span>
                                        </td>
                                        <td>
                                            <span className={`cgo-pill ${t.pivot.enabled ? 'live' : 'void'}`}>
                                                {t.pivot.enabled ? 'enabled' : 'disabled'}
                                            </span>
                                        </td>
                                        <td>
                                            <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
                                                <a
                                                    href={`/platform/tenants/${t.uuid}`}
                                                    className="cgo-row-action"
                                                    aria-label="View tenant"
                                                    title="View tenant"
                                                    onClick={(e) => {
                                                        e.preventDefault();
                                                        router.visit(`/platform/tenants/${t.uuid}`);
                                                    }}
                                                >
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z" /><circle cx="12" cy="12" r="3" /></svg>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* Edit dialog */}
            <Dialog
                header={`Edit · ${game.name}`}
                visible={editOpen}
                style={{ width: '32rem' }}
                onHide={() => setEditOpen(false)}
                modal
                draggable={false}
                footer={
                    <div className="flex justify-end gap-2">
                        <Button label="Cancel" severity="secondary" outlined onClick={() => setEditOpen(false)} />
                        <Button
                            label={saving ? 'Saving…' : 'Save changes'}
                            onClick={handleSaveEdit}
                            disabled={saving || !editForm.name}
                            loading={saving}
                        />
                    </div>
                }
            >
                <div className="space-y-4">
                    <div className="flex flex-col gap-1">
                        <label htmlFor="edit-name" style={{ fontSize: 12, fontWeight: 500 }}>Name *</label>
                        <InputText
                            id="edit-name"
                            value={editForm.name}
                            onChange={(e) => setEditForm({ ...editForm, name: e.target.value })}
                            className="w-full"
                        />
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div className="flex flex-col gap-1">
                            <label htmlFor="edit-type" style={{ fontSize: 12, fontWeight: 500 }}>Type</label>
                            <Dropdown
                                id="edit-type"
                                value={editForm.type}
                                options={GAME_TYPE_OPTIONS}
                                onChange={(e) => setEditForm({ ...editForm, type: e.value })}
                                className="w-full"
                            />
                        </div>
                        <div className="flex flex-col gap-1">
                            <label htmlFor="edit-status" style={{ fontSize: 12, fontWeight: 500 }}>Status</label>
                            <Dropdown
                                id="edit-status"
                                value={editForm.status}
                                options={STATUS_OPTIONS}
                                onChange={(e) => setEditForm({ ...editForm, status: e.value })}
                                className="w-full"
                            />
                        </div>
                    </div>
                    <div className="flex flex-col gap-1">
                        <label htmlFor="edit-version" style={{ fontSize: 12, fontWeight: 500 }}>Version</label>
                        <InputText
                            id="edit-version"
                            value={editForm.version}
                            onChange={(e) => setEditForm({ ...editForm, version: e.target.value })}
                            placeholder="e.g. 1.0.0"
                            className="w-full"
                        />
                    </div>
                    <div className="flex flex-col gap-1">
                        <label htmlFor="edit-description" style={{ fontSize: 12, fontWeight: 500 }}>Description</label>
                        <InputTextarea
                            id="edit-description"
                            value={editForm.description}
                            onChange={(e) => setEditForm({ ...editForm, description: e.target.value })}
                            rows={3}
                            className="w-full"
                        />
                    </div>
                    <div className="flex flex-col gap-1">
                        <label htmlFor="edit-thumbnail" style={{ fontSize: 12, fontWeight: 500 }}>Thumbnail URL</label>
                        <InputText
                            id="edit-thumbnail"
                            value={editForm.thumbnail_url}
                            onChange={(e) => setEditForm({ ...editForm, thumbnail_url: e.target.value })}
                            placeholder="https://…"
                            className="w-full"
                        />
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div className="flex flex-col gap-1">
                            <label htmlFor="edit-backend-url" style={{ fontSize: 12, fontWeight: 500 }}>Backend URL</label>
                            <InputText
                                id="edit-backend-url"
                                value={editForm.backend_url}
                                onChange={(e) => setEditForm({ ...editForm, backend_url: e.target.value })}
                                placeholder="https://engine.example.com"
                                className="w-full"
                            />
                            <span style={{ fontSize: 11, color: 'var(--cg-fg-3)' }}>
                                Admin API base. Health, stats and rounds are read from here.
                            </span>
                        </div>
                        <div className="flex flex-col gap-1">
                            <label htmlFor="edit-launch-url" style={{ fontSize: 12, fontWeight: 500 }}>Launch URL</label>
                            <InputText
                                id="edit-launch-url"
                                value={editForm.launch_url}
                                onChange={(e) => setEditForm({ ...editForm, launch_url: e.target.value })}
                                placeholder="https://play.example.com"
                                className="w-full"
                            />
                            <span style={{ fontSize: 11, color: 'var(--cg-fg-3)' }}>
                                Player-facing entry point, used by "Open game".
                            </span>
                        </div>
                    </div>
                </div>
            </Dialog>
        </UserLayout>
    );
}
