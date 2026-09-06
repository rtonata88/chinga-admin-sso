// resources/js/pages/platform/games/index.tsx
//
// Platform game catalog, brass-on-ink to match /tenant-overview.
// KPI strip → type chips + search → games table → Add-game dialog
// (kept on PrimeReact for the multi-field form).

import { KpiCard, formatCount } from '@/components/operator/kpi-card';
import UserLayout from '@/layouts/user-layout';
import { Head, router } from '@inertiajs/react';
import { Button } from 'primereact/button';
import { Dialog } from 'primereact/dialog';
import { Dropdown } from 'primereact/dropdown';
import { InputText } from 'primereact/inputtext';
import { InputTextarea } from 'primereact/inputtextarea';
import { Toast } from 'primereact/toast';
import { useEffect, useMemo, useRef, useState } from 'react';

interface Game {
    uuid: string;
    name: string;
    slug: string;
    type: string;
    status: string;
    version: string;
    tenants_count: number;
    created_at: string;
}

const TYPE_FILTERS = [
    { label: 'All', value: '' },
    { label: 'Slots', value: 'slots' },
    { label: 'Table', value: 'table' },
    { label: 'Instant', value: 'instant' },
    { label: 'Other', value: 'other' },
];

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
        case 'inactive': return 'void';
        default: return 'void';
    }
}

const generateSlug = (name: string) =>
    name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');

export default function GamesIndex() {
    const toast = useRef<Toast>(null);
    const [games, setGames] = useState<Game[]>([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [typeFilter, setTypeFilter] = useState('');

    const [addOpen, setAddOpen] = useState(false);
    const [saving, setSaving] = useState(false);
    const [formData, setFormData] = useState({
        name: '',
        slug: '',
        description: '',
        type: 'slots',
        status: 'development',
        version: '',
        thumbnail_url: '',
    });

    const getCsrfToken = () =>
        document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    const fetchGames = () => {
        setLoading(true);
        const params = new URLSearchParams();
        if (search) params.set('search', search);
        if (typeFilter) params.set('type', typeFilter);

        fetch(`/api/v1/platform/games?${params}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((res) => res.json())
            .then((res) => {
                setGames(res.data || []);
                setLoading(false);
            })
            .catch(() => setLoading(false));
    };

    useEffect(() => {
        fetchGames();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [typeFilter]);

    const submitSearch = () => fetchGames();

    const stats = useMemo(() => {
        let active = 0;
        let development = 0;
        let inactive = 0;
        let totalTenants = 0;
        for (const g of games) {
            if (g.status === 'active') active++;
            else if (g.status === 'development') development++;
            else inactive++;
            totalTenants += g.tenants_count || 0;
        }
        return { total: games.length, active, development, inactive, totalTenants };
    }, [games]);

    const handleAddGame = async () => {
        setSaving(true);
        try {
            const response = await fetch('/api/v1/platform/games', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify(formData),
            });
            const data = await response.json();
            if (data.data) {
                setAddOpen(false);
                setFormData({
                    name: '', slug: '', description: '', type: 'slots',
                    status: 'development', version: '', thumbnail_url: '',
                });
                fetchGames();
                toast.current?.show({ severity: 'success', summary: 'Created', detail: 'Game added to catalog.' });
            } else {
                toast.current?.show({ severity: 'error', summary: 'Error', detail: data.message || 'Failed to create game.' });
            }
        } catch (error) {
            console.error('Failed to create game:', error);
            toast.current?.show({ severity: 'error', summary: 'Error', detail: 'Failed to create game.' });
        } finally {
            setSaving(false);
        }
    };

    return (
        <UserLayout title="Games">
            <Head title="Games · Platform" />
            <Toast ref={toast} />

            <div className="cgo-page">
                {/* Page header */}
                <div className="cgo-page-head">
                    <div>
                        <div className="cgo-eyebrow">Platform</div>
                        <h1 className="cgo-title">Games</h1>
                        <div className="cgo-subtitle">
                            Game catalog — what's available to assign to tenants.
                        </div>
                    </div>
                    <div style={{ display: 'flex', gap: 8 }}>
                        <button
                            type="button"
                            className="cg-btn cg-btn--ghost cg-btn--sm"
                            onClick={fetchGames}
                        >
                            Refresh
                        </button>
                        <button
                            type="button"
                            className="cg-btn cg-btn--primary cg-btn--sm"
                            onClick={() => setAddOpen(true)}
                        >
                            + New game
                        </button>
                    </div>
                </div>

                {/* KPI strip */}
                <div className="cgo-kpis">
                    <KpiCard
                        label="Total games"
                        value={loading ? '—' : formatCount(stats.total)}
                        brass
                        meta={typeFilter ? `type: ${typeFilter}` : 'in catalog'}
                    />
                    <KpiCard
                        label="Active"
                        value={loading ? '—' : formatCount(stats.active)}
                        meta="live in production"
                    />
                    <KpiCard
                        label="Development"
                        value={loading ? '—' : formatCount(stats.development)}
                        meta="not yet live"
                    />
                    <KpiCard
                        label="Tenant assignments"
                        value={loading ? '—' : formatCount(stats.totalTenants)}
                        meta="across all games"
                    />
                </div>

                {/* Filter bar */}
                <div className="cgo-filterbar">
                    {TYPE_FILTERS.map((f) => (
                        <button
                            key={f.value || 'all'}
                            type="button"
                            className={`cgo-chip${typeFilter === f.value ? ' active' : ''}`}
                            onClick={() => setTypeFilter(f.value)}
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
                                placeholder="Search games…"
                                style={{ minWidth: 240 }}
                            />
                        </label>
                        <button
                            type="button"
                            className="cg-btn cg-btn--ghost cg-btn--sm"
                            onClick={submitSearch}
                        >
                            Search
                        </button>
                    </div>
                </div>

                {/* Games table */}
                <div
                    className="cgo-table-wrap cgo-table-wrap--scroll"
                    style={{ borderRadius: '0 0 8px 8px', borderTop: 0 }}
                >
                    <table className="cgo-wagers">
                        <thead>
                            <tr>
                                <th style={{ minWidth: 240 }}>Game</th>
                                <th style={{ width: 110 }}>Type</th>
                                <th style={{ width: 120 }}>Status</th>
                                <th style={{ width: 100 }}>Version</th>
                                <th className="cgo-r" style={{ width: 100 }}>Tenants</th>
                                <th style={{ width: 70 }} />
                            </tr>
                        </thead>
                        <tbody>
                            {loading ? (
                                <tr>
                                    <td colSpan={6} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>
                                        Loading…
                                    </td>
                                </tr>
                            ) : games.length === 0 ? (
                                <tr>
                                    <td colSpan={6} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>
                                        No games match the current filters.
                                    </td>
                                </tr>
                            ) : (
                                games.map((g) => (
                                    <tr key={g.uuid}>
                                        <td>
                                            <div className="cgo-name">{g.name}</div>
                                            <div className="cgo-uid">{g.slug}</div>
                                        </td>
                                        <td>
                                            <span style={{ fontSize: 12, color: 'var(--cg-fg-2)', textTransform: 'capitalize' }}>
                                                {g.type}
                                            </span>
                                        </td>
                                        <td>
                                            <span className={`cgo-pill ${statusPill(g.status)}`}>
                                                {g.status}
                                            </span>
                                        </td>
                                        <td>
                                            <span className="cgo-uid" style={{ fontFamily: 'var(--cg-mono)' }}>
                                                {g.version || '—'}
                                            </span>
                                        </td>
                                        <td className="cgo-r">
                                            <span className="cgo-odds">{formatCount(g.tenants_count)}</span>
                                        </td>
                                        <td>
                                            <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
                                                <a
                                                    href={`/platform/games/${g.uuid}`}
                                                    className="cgo-row-action"
                                                    aria-label="View game"
                                                    title="View game"
                                                    onClick={(e) => {
                                                        e.preventDefault();
                                                        router.visit(`/platform/games/${g.uuid}`);
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

            {/* New game dialog */}
            <Dialog
                header="New game"
                visible={addOpen}
                style={{ width: '32rem' }}
                onHide={() => setAddOpen(false)}
                modal
                draggable={false}
                footer={
                    <div className="flex justify-end gap-2">
                        <Button label="Cancel" severity="secondary" outlined onClick={() => setAddOpen(false)} />
                        <Button
                            label={saving ? 'Creating…' : 'Create game'}
                            onClick={handleAddGame}
                            disabled={saving || !formData.name || !formData.slug || !formData.type}
                            loading={saving}
                        />
                    </div>
                }
            >
                <p style={{ fontSize: 12, color: 'var(--cg-fg-3)', marginBottom: 16 }}>
                    Add a new game to the platform catalog.
                </p>
                <div className="space-y-4">
                    <div className="flex flex-col gap-1">
                        <label htmlFor="game-name" style={{ fontSize: 12, fontWeight: 500 }}>Name *</label>
                        <InputText
                            id="game-name"
                            value={formData.name}
                            onChange={(e) => {
                                const name = e.target.value;
                                setFormData({ ...formData, name, slug: formData.slug || generateSlug(name) });
                            }}
                            placeholder="e.g. Lucky Sevens"
                            className="w-full"
                        />
                    </div>
                    <div className="flex flex-col gap-1">
                        <label htmlFor="game-slug" style={{ fontSize: 12, fontWeight: 500 }}>Slug *</label>
                        <InputText
                            id="game-slug"
                            value={formData.slug}
                            onChange={(e) =>
                                setFormData({ ...formData, slug: e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '') })
                            }
                            placeholder="e.g. lucky-sevens"
                            className="w-full"
                        />
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div className="flex flex-col gap-1">
                            <label htmlFor="game-type" style={{ fontSize: 12, fontWeight: 500 }}>Type *</label>
                            <Dropdown
                                id="game-type"
                                value={formData.type}
                                options={GAME_TYPE_OPTIONS}
                                onChange={(e) => setFormData({ ...formData, type: e.value })}
                                className="w-full"
                            />
                        </div>
                        <div className="flex flex-col gap-1">
                            <label htmlFor="game-status" style={{ fontSize: 12, fontWeight: 500 }}>Status</label>
                            <Dropdown
                                id="game-status"
                                value={formData.status}
                                options={STATUS_OPTIONS}
                                onChange={(e) => setFormData({ ...formData, status: e.value })}
                                className="w-full"
                            />
                        </div>
                    </div>
                    <div className="flex flex-col gap-1">
                        <label htmlFor="game-version" style={{ fontSize: 12, fontWeight: 500 }}>Version</label>
                        <InputText
                            id="game-version"
                            value={formData.version}
                            onChange={(e) => setFormData({ ...formData, version: e.target.value })}
                            placeholder="e.g. 1.0.0"
                            className="w-full"
                        />
                    </div>
                    <div className="flex flex-col gap-1">
                        <label htmlFor="game-description" style={{ fontSize: 12, fontWeight: 500 }}>Description</label>
                        <InputTextarea
                            id="game-description"
                            value={formData.description}
                            onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                            placeholder="Brief game description…"
                            rows={3}
                            className="w-full"
                        />
                    </div>
                    <div className="flex flex-col gap-1">
                        <label htmlFor="game-thumbnail" style={{ fontSize: 12, fontWeight: 500 }}>Thumbnail URL</label>
                        <InputText
                            id="game-thumbnail"
                            value={formData.thumbnail_url}
                            onChange={(e) => setFormData({ ...formData, thumbnail_url: e.target.value })}
                            placeholder="https://…"
                            className="w-full"
                        />
                    </div>
                </div>
            </Dialog>
        </UserLayout>
    );
}
