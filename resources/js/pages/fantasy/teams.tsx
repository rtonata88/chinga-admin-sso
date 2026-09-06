// resources/js/pages/fantasy/teams.tsx
//
// Fantasy teams catalog, brass-on-ink to match /tenant-overview.
// Active/inactive chips + search → table with logo cell + edit/delete
// row actions → pager. Add/edit dialog and delete confirm stay on
// PrimeReact (form state + confirmation flow).

import UserLayout from '@/layouts/user-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { Button } from 'primereact/button';
import { Dialog } from 'primereact/dialog';
import { InputSwitch } from 'primereact/inputswitch';
import { InputText } from 'primereact/inputtext';
import { Toast } from 'primereact/toast';
import { useEffect, useRef, useState } from 'react';

interface Team {
    id: number;
    uuid: string;
    name: string;
    short_name: string | null;
    logo_url: string | null;
    country: string | null;
    league: string | null;
    is_active: boolean;
    created_at: string;
}

interface Props {
    teams: { data: Team[]; current_page: number; last_page: number; total: number };
    filters: { search?: string; active?: string };
}

const emptyTeam = {
    name: '',
    short_name: '',
    logo_url: '',
    country: '',
    league: '',
    is_active: true,
};

const STATUS_FILTERS = [
    { label: 'All', value: '' },
    { label: 'Active', value: 'true' },
    { label: 'Inactive', value: 'false' },
];

export default function Teams({ teams, filters }: Props) {
    const [search, setSearch] = useState(filters.search || '');
    const [activeFilter, setActiveFilter] = useState(filters.active ?? '');
    const [dialogOpen, setDialogOpen] = useState(false);
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [editingTeam, setEditingTeam] = useState<Team | null>(null);
    const [deletingTeam, setDeletingTeam] = useState<Team | null>(null);
    const [form, setForm] = useState(emptyTeam);
    const [saving, setSaving] = useState(false);
    const toast = useRef<Toast>(null);

    const { flash } = usePage<{ flash: { success?: string; error?: string } }>().props;

    useEffect(() => {
        if (flash?.success) toast.current?.show({ severity: 'success', summary: 'Saved', detail: flash.success });
        if (flash?.error) toast.current?.show({ severity: 'error', summary: 'Error', detail: flash.error });
    }, [flash]);

    const applyFilters = (next: { search?: string; active?: string; page?: number }) => {
        const params: Record<string, string> = {};
        const nextSearch = next.search !== undefined ? next.search : search;
        const nextActive = next.active !== undefined ? next.active : activeFilter;
        if (nextSearch) params.search = nextSearch;
        if (nextActive) params.active = nextActive;
        if (next.page && next.page > 1) params.page = String(next.page);
        router.get('/fantasy/teams', params, { preserveState: true, preserveScroll: true });
    };

    const submitSearch = () => applyFilters({ search, active: activeFilter });

    const openCreateDialog = () => {
        setEditingTeam(null);
        setForm(emptyTeam);
        setDialogOpen(true);
    };

    const openEditDialog = (team: Team) => {
        setEditingTeam(team);
        setForm({
            name: team.name,
            short_name: team.short_name || '',
            logo_url: team.logo_url || '',
            country: team.country || '',
            league: team.league || '',
            is_active: team.is_active,
        });
        setDialogOpen(true);
    };

    const openDeleteDialog = (team: Team) => {
        setDeletingTeam(team);
        setDeleteDialogOpen(true);
    };

    const handleSubmit = () => {
        setSaving(true);
        const data = {
            ...form,
            short_name: form.short_name || null,
            logo_url: form.logo_url || null,
            country: form.country || null,
            league: form.league || null,
        };

        if (editingTeam) {
            router.put(`/fantasy/teams/${editingTeam.uuid}`, data, {
                onSuccess: () => { setDialogOpen(false); setSaving(false); },
                onError: () => setSaving(false),
            });
        } else {
            router.post('/fantasy/teams', data, {
                onSuccess: () => { setDialogOpen(false); setSaving(false); },
                onError: () => setSaving(false),
            });
        }
    };

    const handleDelete = () => {
        if (!deletingTeam) return;
        router.delete(`/fantasy/teams/${deletingTeam.uuid}`, {
            onSuccess: () => setDeleteDialogOpen(false),
        });
    };

    return (
        <UserLayout title="Fantasy teams">
            <Head title="Fantasy teams · Admin" />
            <Toast ref={toast} />

            <div className="cgo-page">
                {/* Page header */}
                <div className="cgo-page-head">
                    <div>
                        <div className="cgo-eyebrow">Fantasy</div>
                        <h1 className="cgo-title">Teams</h1>
                        <div className="cgo-subtitle">
                            Catalog of teams available for the Fantasy game.
                        </div>
                    </div>
                    <div style={{ display: 'flex', gap: 8 }}>
                        <button
                            type="button"
                            className="cg-btn cg-btn--ghost cg-btn--sm"
                            onClick={() => router.reload({ only: ['teams'] })}
                        >
                            Refresh
                        </button>
                        <button
                            type="button"
                            className="cg-btn cg-btn--primary cg-btn--sm"
                            onClick={openCreateDialog}
                        >
                            + New team
                        </button>
                    </div>
                </div>

                {/* Filter bar */}
                <div className="cgo-filterbar">
                    {STATUS_FILTERS.map((f) => (
                        <button
                            key={f.value || 'all'}
                            type="button"
                            className={`cgo-chip${activeFilter === f.value ? ' active' : ''}`}
                            onClick={() => { setActiveFilter(f.value); applyFilters({ active: f.value, page: 1 }); }}
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
                                placeholder="Search name, country, league…"
                                style={{ minWidth: 260 }}
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

                {/* Teams table */}
                <div
                    className="cgo-table-wrap cgo-table-wrap--scroll"
                    style={{ borderRadius: '0 0 8px 8px', borderTop: 0 }}
                >
                    <table className="cgo-wagers">
                        <thead>
                            <tr>
                                <th style={{ minWidth: 240 }}>Team</th>
                                <th style={{ minWidth: 140 }}>Country</th>
                                <th style={{ minWidth: 160 }}>League</th>
                                <th style={{ width: 100 }}>Status</th>
                                <th style={{ width: 110 }} />
                            </tr>
                        </thead>
                        <tbody>
                            {teams.data.length === 0 ? (
                                <tr>
                                    <td colSpan={5} style={{ textAlign: 'center', color: 'var(--cg-fg-3)', padding: '32px 0' }}>
                                        No teams match the current filters.
                                    </td>
                                </tr>
                            ) : (
                                teams.data.map((t) => (
                                    <tr key={t.uuid}>
                                        <td>
                                            <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                                                {t.logo_url ? (
                                                    <img
                                                        src={t.logo_url}
                                                        alt={t.name}
                                                        style={{
                                                            width: 32,
                                                            height: 32,
                                                            borderRadius: 4,
                                                            objectFit: 'cover',
                                                            border: '1px solid var(--cg-rule)',
                                                        }}
                                                    />
                                                ) : (
                                                    <div
                                                        style={{
                                                            width: 32,
                                                            height: 32,
                                                            borderRadius: 4,
                                                            display: 'grid',
                                                            placeItems: 'center',
                                                            background: 'var(--cg-ink-elevated)',
                                                            border: '1px solid var(--cg-rule)',
                                                            color: 'var(--cg-fg-3)',
                                                            fontSize: 11,
                                                            fontFamily: 'var(--cg-mono)',
                                                            fontWeight: 700,
                                                            letterSpacing: '0.04em',
                                                        }}
                                                    >
                                                        {(t.short_name || t.name.substring(0, 2)).toUpperCase()}
                                                    </div>
                                                )}
                                                <div>
                                                    <div className="cgo-name">{t.name}</div>
                                                    {t.short_name && (
                                                        <div className="cgo-uid">{t.short_name}</div>
                                                    )}
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span style={{ fontSize: 12, color: 'var(--cg-fg-2)' }}>
                                                {t.country || '—'}
                                            </span>
                                        </td>
                                        <td>
                                            <span style={{ fontSize: 12, color: 'var(--cg-fg-2)' }}>
                                                {t.league || '—'}
                                            </span>
                                        </td>
                                        <td>
                                            <span className={`cgo-pill ${t.is_active ? 'live' : 'void'}`}>
                                                {t.is_active ? 'active' : 'inactive'}
                                            </span>
                                        </td>
                                        <td>
                                            <div style={{ display: 'flex', gap: 4, justifyContent: 'flex-end' }}>
                                                <button
                                                    type="button"
                                                    className="cgo-row-action"
                                                    aria-label="Edit team"
                                                    title="Edit team"
                                                    onClick={() => openEditDialog(t)}
                                                >
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M12 20h9" /><path d="M16.5 3.5a2.121 2.121 0 1 1 3 3L7 19l-4 1 1-4z" /></svg>
                                                </button>
                                                <button
                                                    type="button"
                                                    className="cgo-row-action"
                                                    aria-label="Delete team"
                                                    title="Delete team"
                                                    style={{ color: 'var(--cg-neg)', borderColor: 'var(--cg-neg)' }}
                                                    onClick={() => openDeleteDialog(t)}
                                                >
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><polyline points="3 6 5 6 21 6" /><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" /><line x1="10" y1="11" x2="10" y2="17" /><line x1="14" y1="11" x2="14" y2="17" /></svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>

                    {/* Footer + pager */}
                    <div className="cgo-table-foot">
                        <span>
                            {teams.total > 0 ? (
                                <>
                                    Showing <b className="cgo-mono">{teams.data.length}</b> of{' '}
                                    <b className="cgo-mono">{teams.total.toLocaleString()}</b> teams
                                </>
                            ) : (
                                'No teams'
                            )}
                        </span>
                        {teams.last_page > 1 && (
                            <div className="cgo-pager">
                                <button
                                    type="button"
                                    disabled={teams.current_page === 1}
                                    onClick={() => applyFilters({ page: teams.current_page - 1 })}
                                    aria-label="Previous"
                                >
                                    ‹
                                </button>
                                <button type="button" className="curr" disabled>
                                    {teams.current_page}
                                </button>
                                <button
                                    type="button"
                                    disabled={teams.current_page === teams.last_page}
                                    onClick={() => applyFilters({ page: teams.current_page + 1 })}
                                    aria-label="Next"
                                >
                                    ›
                                </button>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {/* Add / edit team dialog */}
            <Dialog
                header={editingTeam ? `Edit · ${editingTeam.name}` : 'New team'}
                visible={dialogOpen}
                style={{ width: '32rem' }}
                onHide={() => setDialogOpen(false)}
                modal
                draggable={false}
                footer={
                    <div className="flex justify-end gap-2">
                        <Button label="Cancel" severity="secondary" outlined onClick={() => setDialogOpen(false)} />
                        <Button
                            label={saving ? 'Saving…' : 'Save'}
                            onClick={handleSubmit}
                            disabled={saving || !form.name.trim()}
                            loading={saving}
                        />
                    </div>
                }
            >
                <div className="space-y-4">
                    <div>
                        <label style={{ fontSize: 12, fontWeight: 500, display: 'block', marginBottom: 4 }}>
                            Name *
                        </label>
                        <InputText
                            value={form.name}
                            onChange={(e) => setForm({ ...form, name: e.target.value })}
                            className="w-full"
                            placeholder="Team name"
                        />
                    </div>
                    <div>
                        <label style={{ fontSize: 12, fontWeight: 500, display: 'block', marginBottom: 4 }}>
                            Short name
                        </label>
                        <InputText
                            value={form.short_name}
                            onChange={(e) => setForm({ ...form, short_name: e.target.value })}
                            className="w-full"
                            placeholder="e.g. NAM"
                            maxLength={10}
                        />
                    </div>
                    <div>
                        <label style={{ fontSize: 12, fontWeight: 500, display: 'block', marginBottom: 4 }}>
                            Logo URL
                        </label>
                        <InputText
                            value={form.logo_url}
                            onChange={(e) => setForm({ ...form, logo_url: e.target.value })}
                            className="w-full"
                            placeholder="https://…"
                        />
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label style={{ fontSize: 12, fontWeight: 500, display: 'block', marginBottom: 4 }}>
                                Country
                            </label>
                            <InputText
                                value={form.country}
                                onChange={(e) => setForm({ ...form, country: e.target.value })}
                                className="w-full"
                                placeholder="e.g. Namibia"
                            />
                        </div>
                        <div>
                            <label style={{ fontSize: 12, fontWeight: 500, display: 'block', marginBottom: 4 }}>
                                League
                            </label>
                            <InputText
                                value={form.league}
                                onChange={(e) => setForm({ ...form, league: e.target.value })}
                                className="w-full"
                                placeholder="e.g. Premier League"
                            />
                        </div>
                    </div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                        <InputSwitch
                            checked={form.is_active}
                            onChange={(e) => setForm({ ...form, is_active: e.value ?? false })}
                        />
                        <label style={{ fontSize: 13 }}>
                            Active{' '}
                            <span style={{ fontSize: 11, color: 'var(--cg-fg-3)' }}>
                                · eligible to be drawn into rounds
                            </span>
                        </label>
                    </div>
                </div>
            </Dialog>

            {/* Delete confirmation dialog */}
            <Dialog
                header="Delete team"
                visible={deleteDialogOpen}
                style={{ width: '24rem' }}
                onHide={() => setDeleteDialogOpen(false)}
                modal
                draggable={false}
                footer={
                    <div className="flex justify-end gap-2">
                        <Button label="Cancel" severity="secondary" outlined onClick={() => setDeleteDialogOpen(false)} />
                        <Button label="Delete" severity="danger" onClick={handleDelete} />
                    </div>
                }
            >
                <div style={{ fontSize: 13, lineHeight: 1.5 }}>
                    Delete <strong>{deletingTeam?.name}</strong>? This cannot be undone, and the
                    team will no longer appear in future rounds.
                </div>
            </Dialog>
        </UserLayout>
    );
}
