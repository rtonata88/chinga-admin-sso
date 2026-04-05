import PageHeader from '@/components/acumatica/Common/PageHeader';
import StatusBadge from '@/components/acumatica/Common/StatusBadge';
import UserLayout from '@/layouts/user-layout';
import type { StatusVariant } from '@/types/acumatica';
import { Head, router, usePage } from '@inertiajs/react';
import { Button } from 'primereact/button';
import { Column } from 'primereact/column';
import { DataTable } from 'primereact/datatable';
import { Dialog } from 'primereact/dialog';
import { InputSwitch } from 'primereact/inputswitch';
import { InputText } from 'primereact/inputtext';
import { Tag } from 'primereact/tag';
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

export default function Teams({ teams, filters }: Props) {
    const [search, setSearch] = useState(filters.search || '');
    const [dialogOpen, setDialogOpen] = useState(false);
    const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
    const [editingTeam, setEditingTeam] = useState<Team | null>(null);
    const [deletingTeam, setDeletingTeam] = useState<Team | null>(null);
    const [form, setForm] = useState(emptyTeam);
    const [saving, setSaving] = useState(false);
    const toast = useRef<Toast>(null);

    const { flash } = usePage<{ flash: { success?: string; error?: string } }>().props;

    useEffect(() => {
        if (flash?.success) {
            toast.current?.show({ severity: 'success', summary: 'Success', detail: flash.success });
        }
        if (flash?.error) {
            toast.current?.show({ severity: 'error', summary: 'Error', detail: flash.error });
        }
    }, [flash]);

    const handleSearch = () => {
        router.get('/platform/games/fantasy/teams', { search: search || undefined }, { preserveState: true });
    };

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
            router.put(`/platform/games/fantasy/teams/${editingTeam.uuid}`, data, {
                onSuccess: () => { setDialogOpen(false); setSaving(false); },
                onError: () => setSaving(false),
            });
        } else {
            router.post('/platform/games/fantasy/teams', data, {
                onSuccess: () => { setDialogOpen(false); setSaving(false); },
                onError: () => setSaving(false),
            });
        }
    };

    const handleDelete = () => {
        if (!deletingTeam) return;
        router.delete(`/platform/games/fantasy/teams/${deletingTeam.uuid}`, {
            onSuccess: () => setDeleteDialogOpen(false),
        });
    };

    const nameTemplate = (row: Team) => (
        <div className="flex items-center gap-3">
            {row.logo_url ? (
                <img src={row.logo_url} alt={row.name} className="w-8 h-8 rounded object-cover" />
            ) : (
                <div
                    className="w-8 h-8 rounded flex items-center justify-center text-xs font-bold"
                    style={{ background: 'var(--acu-surface-hover)', color: 'var(--acu-text-light)' }}
                >
                    {row.short_name || row.name.substring(0, 2).toUpperCase()}
                </div>
            )}
            <div>
                <div className="font-medium text-sm" style={{ color: 'var(--acu-text)' }}>{row.name}</div>
                {row.short_name && (
                    <div className="text-xs" style={{ color: 'var(--acu-text-light)' }}>{row.short_name}</div>
                )}
            </div>
        </div>
    );

    const countryTemplate = (row: Team) => (
        <span className="text-sm" style={{ color: 'var(--acu-text-muted)' }}>{row.country || '\u2014'}</span>
    );

    const leagueTemplate = (row: Team) => (
        <span className="text-sm" style={{ color: 'var(--acu-text-muted)' }}>{row.league || '\u2014'}</span>
    );

    const statusTemplate = (row: Team) => (
        <StatusBadge
            status={row.is_active ? 'active' : ('inactive' as StatusVariant)}
            label={row.is_active ? 'Active' : 'Inactive'}
        />
    );

    const actionsTemplate = (row: Team) => (
        <div className="flex gap-1">
            <Button
                icon="pi pi-pencil"
                text
                severity="secondary"
                size="small"
                tooltip="Edit team"
                onClick={() => openEditDialog(row)}
            />
            <Button
                icon="pi pi-trash"
                text
                severity="danger"
                size="small"
                tooltip="Delete team"
                onClick={() => openDeleteDialog(row)}
            />
        </div>
    );

    return (
        <UserLayout title="Fantasy Teams">
            <Head title="Fantasy Teams" />
            <Toast ref={toast} />

            <div className="space-y-8">
                <PageHeader title="Fantasy Teams" subtitle="Manage teams available for the Fantasy game">
                    <Button
                        label="Add Team"
                        icon="pi pi-plus"
                        size="small"
                        onClick={openCreateDialog}
                    />
                </PageHeader>

                {/* Filters */}
                <div
                    className="rounded-xl p-4"
                    style={{
                        background: 'var(--acu-surface-card)',
                        border: '1px solid var(--acu-border)',
                    }}
                >
                    <div className="flex flex-wrap gap-3 items-end">
                        <div className="flex flex-1 gap-2">
                            <span className="p-input-icon-left flex-1" style={{ maxWidth: '24rem' }}>
                                <i className="pi pi-search" />
                                <InputText
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    onKeyDown={(e) => e.key === 'Enter' && handleSearch()}
                                    placeholder="Search by name, country, league..."
                                    className="w-full"
                                />
                            </span>
                            <Button label="Search" icon="pi pi-search" size="small" onClick={handleSearch} />
                        </div>
                    </div>
                </div>

                {/* Teams Table */}
                <div className="acu-fieldset" style={{ '--fieldset-color': 'var(--acu-fieldset-gold)' } as React.CSSProperties}>
                    <div className="acu-fieldset-header">
                        <div className="acu-fieldset-title">
                            <i className="pi pi-flag" />
                            <span>Teams</span>
                            <span className="text-xs font-normal ml-1" style={{ color: 'var(--acu-text-light)' }}>
                                ({teams.total})
                            </span>
                        </div>
                    </div>
                    <div className="acu-fieldset-body p-0">
                        <DataTable
                            value={teams.data}
                            size="small"
                            showGridlines={false}
                            emptyMessage="No teams found"
                        >
                            <Column header="Team" body={nameTemplate} />
                            <Column header="Country" body={countryTemplate} />
                            <Column header="League" body={leagueTemplate} />
                            <Column header="Status" body={statusTemplate} />
                            <Column header="" body={actionsTemplate} style={{ width: '6rem' }} />
                        </DataTable>

                        {/* Pagination */}
                        {teams.last_page > 1 && (
                            <div
                                className="flex items-center justify-between px-5 py-3"
                                style={{ borderTop: '1px solid var(--acu-border)' }}
                            >
                                <span className="text-xs" style={{ color: 'var(--acu-text-light)', fontFamily: 'var(--font-body)' }}>
                                    Page {teams.current_page} of {teams.last_page}
                                </span>
                                <div className="flex gap-2">
                                    <Button
                                        label="Previous"
                                        icon="pi pi-angle-left"
                                        outlined
                                        size="small"
                                        disabled={teams.current_page === 1}
                                        onClick={() => router.get('/platform/games/fantasy/teams', { ...filters, page: teams.current_page - 1 }, { preserveState: true })}
                                    />
                                    <Button
                                        label="Next"
                                        icon="pi pi-angle-right"
                                        iconPos="right"
                                        outlined
                                        size="small"
                                        disabled={teams.current_page === teams.last_page}
                                        onClick={() => router.get('/platform/games/fantasy/teams', { ...filters, page: teams.current_page + 1 }, { preserveState: true })}
                                    />
                                </div>
                            </div>
                        )}
                    </div>
                </div>
            </div>

            {/* Add/Edit Dialog */}
            <Dialog
                header={editingTeam ? 'Edit Team' : 'Add Team'}
                visible={dialogOpen}
                style={{ width: '32rem' }}
                onHide={() => setDialogOpen(false)}
                modal
                draggable={false}
                footer={
                    <div className="flex justify-end gap-2">
                        <Button
                            label="Cancel"
                            icon="pi pi-times"
                            severity="secondary"
                            outlined
                            onClick={() => setDialogOpen(false)}
                        />
                        <Button
                            label={saving ? 'Saving...' : 'Save'}
                            icon="pi pi-check"
                            onClick={handleSubmit}
                            disabled={saving || !form.name.trim()}
                            loading={saving}
                        />
                    </div>
                }
            >
                <div className="space-y-4">
                    <div>
                        <label className="block text-sm font-medium mb-1" style={{ color: 'var(--acu-text)' }}>Name *</label>
                        <InputText
                            value={form.name}
                            onChange={(e) => setForm({ ...form, name: e.target.value })}
                            className="w-full"
                            placeholder="Team name"
                        />
                    </div>
                    <div>
                        <label className="block text-sm font-medium mb-1" style={{ color: 'var(--acu-text)' }}>Short Name</label>
                        <InputText
                            value={form.short_name}
                            onChange={(e) => setForm({ ...form, short_name: e.target.value })}
                            className="w-full"
                            placeholder="e.g. NAM"
                            maxLength={10}
                        />
                    </div>
                    <div>
                        <label className="block text-sm font-medium mb-1" style={{ color: 'var(--acu-text)' }}>Logo URL</label>
                        <InputText
                            value={form.logo_url}
                            onChange={(e) => setForm({ ...form, logo_url: e.target.value })}
                            className="w-full"
                            placeholder="https://..."
                        />
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div>
                            <label className="block text-sm font-medium mb-1" style={{ color: 'var(--acu-text)' }}>Country</label>
                            <InputText
                                value={form.country}
                                onChange={(e) => setForm({ ...form, country: e.target.value })}
                                className="w-full"
                                placeholder="e.g. Namibia"
                            />
                        </div>
                        <div>
                            <label className="block text-sm font-medium mb-1" style={{ color: 'var(--acu-text)' }}>League</label>
                            <InputText
                                value={form.league}
                                onChange={(e) => setForm({ ...form, league: e.target.value })}
                                className="w-full"
                                placeholder="e.g. Premier League"
                            />
                        </div>
                    </div>
                    <div className="flex items-center gap-3">
                        <InputSwitch
                            checked={form.is_active}
                            onChange={(e) => setForm({ ...form, is_active: e.value ?? false })}
                        />
                        <label className="text-sm" style={{ color: 'var(--acu-text)' }}>Active</label>
                    </div>
                </div>
            </Dialog>

            {/* Delete Confirmation Dialog */}
            <Dialog
                header="Delete Team"
                visible={deleteDialogOpen}
                style={{ width: '24rem' }}
                onHide={() => setDeleteDialogOpen(false)}
                modal
                draggable={false}
                footer={
                    <div className="flex justify-end gap-2">
                        <Button
                            label="Cancel"
                            icon="pi pi-times"
                            severity="secondary"
                            outlined
                            onClick={() => setDeleteDialogOpen(false)}
                        />
                        <Button
                            label="Delete"
                            icon="pi pi-trash"
                            severity="danger"
                            onClick={handleDelete}
                        />
                    </div>
                }
            >
                <div className="flex items-center gap-3">
                    <i className="pi pi-exclamation-triangle text-2xl" style={{ color: 'var(--acu-danger, #F85149)' }} />
                    <span className="text-sm" style={{ color: 'var(--acu-text)' }}>
                        Are you sure you want to delete <strong>{deletingTeam?.name}</strong>? This action cannot be undone.
                    </span>
                </div>
            </Dialog>
        </UserLayout>
    );
}
