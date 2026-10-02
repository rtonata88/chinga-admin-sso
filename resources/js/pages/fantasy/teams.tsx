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
    name: string;
    short_name: string | null;
    colour: string;
    active: boolean;
    position: number | null;
}

interface Props {
    teams: { data: Team[]; current_page: number; last_page: number; total: number };
    filters: { search?: string; active?: string };
    error?: string | null;
}

const emptyTeam = {
    name: '',
    short_name: '',
    colour: '#E4002B',
    active: true,
    position: '' as string | number,
};

/*
 * Phone-width tap targets. The brass shell's controls are 28–32px; below
 * sm they grow to 40px. Important (!) because the shell's unlayered CSS
 * (`all: unset`, fixed heights) otherwise wins over Tailwind utilities.
 */
const TAP = 'max-sm:min-h-10!';
const TAP_SQUARE = 'max-sm:min-h-10! max-sm:min-w-10!';

const STATUS_FILTERS = [
    { label: 'All', value: '' },
    { label: 'Active', value: 'true' },
    { label: 'Inactive', value: 'false' },
];

export default function Teams({ teams, filters, error = null }: Props) {
    const [search, setSearch] = useState(filters.search || '');
    const [activeFilter, setActiveFilter] = useState(filters.active ?? '');
    const [dialogOpen, setDialogOpen] = useState(false);
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [editingTeam, setEditingTeam] = useState<Team | null>(null);
    const [deletingTeam, setDeletingTeam] = useState<Team | null>(null);
    const [form, setForm] = useState(emptyTeam);
    const [saving, setSaving] = useState(false);
    const toast = useRef<Toast>(null);
    const colourInput = useRef<HTMLInputElement>(null);

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
            colour: team.colour,
            active: team.active,
            position: team.position ?? '',
        });
        setDialogOpen(true);
    };

    const openDeleteDialog = (team: Team) => {
        setDeletingTeam(team);
        setDeleteDialogOpen(true);
    };

    const handleSubmit = () => {
        setSaving(true);
        const data = { ...form };

        if (editingTeam) {
            router.put(`/fantasy/teams/${editingTeam.id}`, data, {
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
        router.delete(`/fantasy/teams/${deletingTeam.id}`, {
            onSuccess: () => setDeleteDialogOpen(false),
        });
    };

    return (
        <UserLayout title="Fantasy teams">
            <Head title="Fantasy teams · Admin" />
            <Toast ref={toast} />

            <div className="cgo-page">
                {error && (
                    <div style={{ background: 'var(--cg-ink-card)', border: '1px solid var(--cg-neg)', borderRadius: 6, padding: 14, marginBottom: 18, fontSize: 13, color: 'var(--cg-fg-1)' }}>
                        <strong style={{ color: 'var(--cg-neg)' }}>Error:</strong> {error}
                    </div>
                )}
                {/* Page header */}
                <div className="cgo-page-head max-sm:flex-wrap">
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
                            className={`cg-btn cg-btn--ghost cg-btn--sm ${TAP}`}
                            onClick={() => router.reload({ only: ['teams'] })}
                        >
                            Refresh
                        </button>
                        <button
                            type="button"
                            className={`cg-btn cg-btn--primary cg-btn--sm ${TAP}`}
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
                            className={`cgo-chip${activeFilter === f.value ? ' active' : ''} ${TAP}`}
                            onClick={() => { setActiveFilter(f.value); applyFilters({ active: f.value, page: 1 }); }}
                        >
                            {f.label}
                        </button>
                    ))}
                    {/* Below sm the search drops to its own full-width row. */}
                    <div className="cgo-right max-sm:w-full">
                        <label className={`cgo-input max-sm:min-w-0 max-sm:flex-1 ${TAP}`}>
                            <input
                                type="text"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                onKeyDown={(e) => e.key === 'Enter' && submitSearch()}
                                placeholder="Search name or short name…"
                                className="max-sm:min-w-0! sm:min-w-[260px]!"
                            />
                        </label>
                        <button
                            type="button"
                            className={`cg-btn cg-btn--ghost cg-btn--sm ${TAP}`}
                            onClick={submitSearch}
                        >
                            Search
                        </button>
                    </div>
                </div>

                {/* Teams table */}
                <div className="cgo-table-wrap" style={{ borderRadius: '0 0 8px 8px', borderTop: 0 }}>
                    <table className="cgo-wagers">
                        <thead>
                            <tr>
                                <th style={{ width: 70 }}>Tile</th>
                                <th className="sm:min-w-[240px]">Team</th>
                                {/* Colour is low priority on a phone: the badge's left border already shows it. */}
                                <th className="max-sm:hidden" style={{ minWidth: 120 }}>Colour</th>
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
                                    <tr key={t.id}>
                                        <td className="cgo-mono" data-testid={`tile-${t.id}`}>{t.position === null ? '—' : String(t.position).padStart(2, '0')}</td>
                                        <td>
                                            <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                                                <span className="cgo-team-badge" style={{ borderLeftColor: t.colour }} aria-hidden>
                                                    {(t.short_name || t.name.substring(0, 2)).toUpperCase()}
                                                </span>
                                                <div>
                                                    <div className="cgo-name">{t.name}</div>
                                                    {t.short_name && (
                                                        <div className="cgo-uid">{t.short_name}</div>
                                                    )}
                                                </div>
                                            </div>
                                        </td>
                                        <td className="max-sm:hidden">
                                            <span style={{ fontSize: 12, color: 'var(--cg-fg-2)', fontFamily: 'var(--cg-mono)' }}>
                                                <span aria-hidden style={{ display: 'inline-block', width: 10, height: 10, borderRadius: 2, background: t.colour, marginRight: 6, verticalAlign: 'middle' }} />
                                                {t.colour}
                                            </span>
                                        </td>
                                        <td>
                                            <span className={`cgo-pill ${t.active ? 'live' : 'void'}`}>
                                                {t.active ? 'active' : 'inactive'}
                                            </span>
                                        </td>
                                        <td>
                                            <div style={{ display: 'flex', gap: 4, justifyContent: 'flex-end' }}>
                                                <button
                                                    type="button"
                                                    className={`cgo-row-action ${TAP_SQUARE}`}
                                                    aria-label="Edit team"
                                                    title="Edit team"
                                                    onClick={() => openEditDialog(t)}
                                                >
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M12 20h9" /><path d="M16.5 3.5a2.121 2.121 0 1 1 3 3L7 19l-4 1 1-4z" /></svg>
                                                </button>
                                                <button
                                                    type="button"
                                                    className={`cgo-row-action ${TAP_SQUARE}`}
                                                    aria-label="Deactivate team"
                                                    title="Deactivate team"
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
                                    className={TAP_SQUARE}
                                    disabled={teams.current_page === 1}
                                    onClick={() => applyFilters({ page: teams.current_page - 1 })}
                                    aria-label="Previous"
                                >
                                    ‹
                                </button>
                                <button type="button" className={`curr ${TAP_SQUARE}`} disabled>
                                    {teams.current_page}
                                </button>
                                <button
                                    type="button"
                                    className={TAP_SQUARE}
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
                className="cgo-dialog"
                style={{ width: '32rem', maxWidth: '96vw' }}
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
                <div>
                    <div className="cgo-field">
                        <label className="cgo-field-label" htmlFor="team-name">Name</label>
                        <InputText
                            id="team-name"
                            value={form.name}
                            onChange={(e) => setForm({ ...form, name: e.target.value })}
                            className="w-full"
                            placeholder="Team name"
                            autoFocus
                        />
                    </div>
                    <div className="cgo-field-grid" style={{ marginTop: 16 }}>
                        <div className="cgo-field">
                            <label className="cgo-field-label" htmlFor="team-short">Short name</label>
                            <div className="cgo-field-hint">Up to 6 characters, shown on the tile</div>
                            <InputText
                                id="team-short"
                                value={form.short_name}
                                onChange={(e) => setForm({ ...form, short_name: e.target.value.toUpperCase() })}
                                className="w-full"
                                placeholder="KATKI"
                                maxLength={6}
                            />
                        </div>
                        <div className="cgo-field">
                            <label className="cgo-field-label" htmlFor="team-tile">Tile</label>
                            <div className="cgo-field-hint">1 to grid size in the fixed layout; blank means no tile</div>
                            <InputText
                                id="team-tile"
                                value={String(form.position)}
                                onChange={(e) => setForm({ ...form, position: e.target.value.replace(/[^0-9]/g, '') })}
                                className="w-full"
                                placeholder="—"
                                inputMode="numeric"
                            />
                        </div>
                    </div>
                    <div className="cgo-field" style={{ marginTop: 16 }}>
                        <label className="cgo-field-label" htmlFor="team-colour">Colour</label>
                        <div className="cgo-colour-row">
                            <button
                                type="button"
                                className="cgo-swatch"
                                aria-label="Pick a colour"
                                title="Pick a colour"
                                onClick={() => colourInput.current?.click()}
                            >
                                <span style={{ background: form.colour }} />
                            </button>
                            <input
                                ref={colourInput}
                                type="color"
                                aria-label="Colour"
                                tabIndex={-1}
                                value={/^#[0-9A-Fa-f]{6}$/.test(form.colour) ? form.colour : '#000000'}
                                onChange={(e) => setForm({ ...form, colour: e.target.value.toUpperCase() })}
                            />
                            <InputText
                                id="team-colour"
                                value={form.colour}
                                onChange={(e) => setForm({ ...form, colour: e.target.value.toUpperCase() })}
                                className="w-full"
                                placeholder="#E4002B"
                                maxLength={7}
                            />
                        </div>
                    </div>
                    <div className="cgo-switch-row" style={{ marginTop: 18 }}>
                        <InputSwitch
                            inputId="team-active"
                            checked={form.active}
                            onChange={(e) => setForm({ ...form, active: e.value ?? false })}
                        />
                        <label htmlFor="team-active" style={{ fontSize: 13, color: 'var(--cg-fg-1)' }}>
                            Active{' '}
                            <span style={{ fontSize: 11, color: 'var(--cg-fg-3)' }}>· eligible to be drawn into rounds</span>
                        </label>
                    </div>
                </div>
            </Dialog>

            {/* Delete confirmation dialog */}
            <Dialog
                header="Deactivate team"
                visible={deleteDialogOpen}
                className="cgo-dialog"
                style={{ width: '26rem', maxWidth: '96vw' }}
                onHide={() => setDeleteDialogOpen(false)}
                modal
                draggable={false}
                footer={
                    <div className="flex justify-end gap-2">
                        <Button label="Cancel" severity="secondary" outlined onClick={() => setDeleteDialogOpen(false)} />
                        <Button label="Deactivate" severity="danger" onClick={handleDelete} />
                    </div>
                }
            >
                <div style={{ fontSize: 13, lineHeight: 1.6, color: 'var(--cg-fg-2)' }}>
                    Deactivate <strong style={{ color: 'var(--cg-fg-1)' }}>{deletingTeam?.name}</strong>? It stays in the history of the
                    rounds it was dealt into and will not be dealt into new ones. You can reactivate it later.
                </div>
            </Dialog>
        </UserLayout>
    );
}
