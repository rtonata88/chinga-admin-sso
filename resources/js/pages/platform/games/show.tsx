import PageHeader from '@/components/acumatica/Common/PageHeader';
import StatusBadge from '@/components/acumatica/Common/StatusBadge';
import UserLayout from '@/layouts/user-layout';
import type { StatusVariant } from '@/types/acumatica';
import { Head, Link, usePage } from '@inertiajs/react';
import { Button } from 'primereact/button';
import { Column } from 'primereact/column';
import { DataTable } from 'primereact/datatable';
import { Dialog } from 'primereact/dialog';
import { Dropdown } from 'primereact/dropdown';
import { InputText } from 'primereact/inputtext';
import { InputTextarea } from 'primereact/inputtextarea';
import { useEffect, useState } from 'react';

interface GameTenant {
    uuid: string;
    name: string;
    slug: string;
    status: string;
    pivot: {
        enabled: boolean;
    };
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
    settings: Record<string, unknown>;
    tenants_count: number;
    tenants: GameTenant[];
    created_at: string;
}

function mapGameStatus(status: string): StatusVariant {
    switch (status) {
        case 'active':
            return 'active';
        case 'development':
            return 'pending';
        default:
            return 'inactive';
    }
}

function mapTenantStatus(status: string): StatusVariant {
    switch (status) {
        case 'active':
            return 'active';
        case 'suspended':
            return 'suspended';
        default:
            return 'inactive';
    }
}

const gameTypeOptions = [
    { label: 'Slots', value: 'slots' },
    { label: 'Table', value: 'table' },
    { label: 'Instant', value: 'instant' },
    { label: 'Other', value: 'other' },
];

const statusOptions = [
    { label: 'Active', value: 'active' },
    { label: 'Inactive', value: 'inactive' },
    { label: 'Development', value: 'development' },
];

export default function GameShow() {
    const [game, setGame] = useState<Game | null>(null);
    const [loading, setLoading] = useState(true);

    // Edit dialog
    const [editOpen, setEditOpen] = useState(false);
    const [saving, setSaving] = useState(false);
    const [editForm, setEditForm] = useState({
        name: '',
        description: '',
        type: 'slots',
        status: 'development',
        version: '',
        thumbnail_url: '',
    });

    const { uuid } = usePage<{ uuid: string }>().props;

    const getCsrfToken = () => {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    };

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
            });
    };

    useEffect(() => {
        fetchGame();
    }, [uuid]);

    const openEditDialog = () => {
        if (!game) return;
        setEditForm({
            name: game.name,
            description: game.description || '',
            type: game.type,
            status: game.status,
            version: game.version || '',
            thumbnail_url: game.thumbnail_url || '',
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
                body: JSON.stringify(editForm),
            });
            const data = await response.json();
            if (data.data) {
                setEditOpen(false);
                fetchGame();
            } else {
                alert(data.message || 'Failed to update game');
            }
        } catch (error) {
            console.error('Failed to update game:', error);
            alert('Failed to update game');
        } finally {
            setSaving(false);
        }
    };

    if (loading || !game) {
        return (
            <UserLayout title="Game Details">
                <Head title="Game Details" />
                <div className="text-center py-8">Loading...</div>
            </UserLayout>
        );
    }

    const tenantNameTemplate = (row: GameTenant) => (
        <div>
            <div className="font-medium text-sm text-[var(--acu-text)]">{row.name}</div>
            <div className="text-xs text-[var(--acu-text-light)]">{row.slug}</div>
        </div>
    );

    const tenantStatusTemplate = (row: GameTenant) => (
        <StatusBadge status={mapTenantStatus(row.status)} label={row.status} />
    );

    const tenantEnabledTemplate = (row: GameTenant) => (
        <StatusBadge
            status={row.pivot.enabled ? 'active' : 'inactive'}
            label={row.pivot.enabled ? 'Enabled' : 'Disabled'}
        />
    );

    const tenantActionsTemplate = (row: GameTenant) => (
        <Link href={`/platform/tenants/${row.uuid}`}>
            <Button
                icon="pi pi-eye"
                severity="secondary"
                text
                size="small"
                tooltip="View tenant"
            />
        </Link>
    );

    const editDialogFooter = (
        <div className="flex justify-end gap-2">
            <Button
                label="Cancel"
                icon="pi pi-times"
                severity="secondary"
                outlined
                onClick={() => setEditOpen(false)}
            />
            <Button
                label={saving ? 'Saving...' : 'Save Changes'}
                icon="pi pi-check"
                onClick={handleSaveEdit}
                disabled={saving || !editForm.name}
                loading={saving}
            />
        </div>
    );

    return (
        <UserLayout title={game.name}>
            <Head title={game.name} />

            <div className="space-y-6">
                <PageHeader title={game.name} subtitle={game.slug}>
                    <StatusBadge status={mapGameStatus(game.status)} label={game.status} />
                    <Button
                        label="Edit"
                        icon="pi pi-pencil"
                        severity="secondary"
                        outlined
                        onClick={openEditDialog}
                    />
                    <Button
                        label="Refresh"
                        icon="pi pi-refresh"
                        severity="secondary"
                        outlined
                        onClick={fetchGame}
                    />
                </PageHeader>

                <div className="mb-4">
                    <Link href="/platform/games" className="inline-flex items-center gap-1 text-sm text-[var(--acu-text-muted)] hover:text-[var(--acu-text)]">
                        <i className="pi pi-arrow-left text-xs" />
                        Back to Game Catalog
                    </Link>
                </div>

                {/* Stats */}
                <div className="grid gap-4 md:grid-cols-3">
                    <div className="acu-fieldset">
                        <div className="p-4">
                            <span className="text-xs font-semibold uppercase tracking-wide text-[var(--acu-text-muted)]">Type</span>
                            <div className="text-2xl font-bold text-[var(--acu-text)] capitalize">{game.type}</div>
                        </div>
                    </div>
                    <div className="acu-fieldset">
                        <div className="p-4">
                            <span className="text-xs font-semibold uppercase tracking-wide text-[var(--acu-text-muted)]">Version</span>
                            <div className="text-2xl font-bold text-[var(--acu-text)]">{game.version || '—'}</div>
                        </div>
                    </div>
                    <div className="acu-fieldset">
                        <div className="p-4">
                            <span className="text-xs font-semibold uppercase tracking-wide text-[var(--acu-text-muted)]">Tenants</span>
                            <div className="text-2xl font-bold text-[var(--acu-text)]">{game.tenants_count}</div>
                        </div>
                    </div>
                </div>

                {/* Game Details */}
                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div className="acu-fieldset" style={{ '--fieldset-color': 'var(--acu-fieldset-blue)' } as React.CSSProperties}>
                        <div className="acu-fieldset-header">
                            <div className="acu-fieldset-title">
                                <i className="pi pi-play" />
                                <span>Game Details</span>
                            </div>
                        </div>
                        <div className="acu-fieldset-body space-y-3">
                            <div>
                                <p className="text-sm text-[var(--acu-text-muted)]">Slug</p>
                                <p className="font-medium text-[var(--acu-text)]">{game.slug}</p>
                            </div>
                            <div>
                                <p className="text-sm text-[var(--acu-text-muted)]">Type</p>
                                <p className="font-medium text-[var(--acu-text)] capitalize">{game.type}</p>
                            </div>
                            <div>
                                <p className="text-sm text-[var(--acu-text-muted)]">Version</p>
                                <p className="font-medium text-[var(--acu-text)]">{game.version || '—'}</p>
                            </div>
                            <div>
                                <p className="text-sm text-[var(--acu-text-muted)]">Description</p>
                                <p className="font-medium text-[var(--acu-text)]">{game.description || '—'}</p>
                            </div>
                            <div>
                                <p className="text-sm text-[var(--acu-text-muted)]">Created</p>
                                <p className="font-medium text-[var(--acu-text)]">{new Date(game.created_at).toLocaleDateString()}</p>
                            </div>
                        </div>
                    </div>

                    {game.thumbnail_url && (
                        <div className="acu-fieldset">
                            <div className="acu-fieldset-header">
                                <span className="acu-fieldset-title">Thumbnail</span>
                            </div>
                            <div className="acu-fieldset-body">
                                <img src={game.thumbnail_url} alt={game.name} className="max-w-full rounded" />
                            </div>
                        </div>
                    )}
                </div>

                {/* Tenants using this game */}
                <div className="acu-fieldset" style={{ '--fieldset-color': 'var(--acu-fieldset-blue)' } as React.CSSProperties}>
                    <div className="acu-fieldset-header">
                        <div className="acu-fieldset-title">
                            <i className="pi pi-building" />
                            <span>Assigned Tenants</span>
                            <span className="text-xs font-normal text-[var(--acu-text-light)] ml-1">
                                ({game.tenants?.length || 0})
                            </span>
                        </div>
                    </div>
                    <div className="acu-fieldset-body p-0">
                        <DataTable
                            value={game.tenants || []}
                            size="small"
                            emptyMessage="No tenants assigned"
                            showGridlines={false}
                            dataKey="uuid"
                            stripedRows
                        >
                            <Column header="Tenant" body={tenantNameTemplate} />
                            <Column header="Tenant Status" body={tenantStatusTemplate} />
                            <Column header="Game Enabled" body={tenantEnabledTemplate} />
                            <Column header="Actions" body={tenantActionsTemplate} style={{ width: '5rem' }} />
                        </DataTable>
                    </div>
                </div>
            </div>

            {/* Edit Game Dialog */}
            <Dialog
                header="Edit Game"
                visible={editOpen}
                style={{ width: '32rem' }}
                onHide={() => setEditOpen(false)}
                footer={editDialogFooter}
                modal
                draggable={false}
            >
                <div className="space-y-4">
                    <div className="flex flex-col gap-1">
                        <label htmlFor="edit-name" className="text-sm font-medium text-[var(--acu-text)]">
                            Name *
                        </label>
                        <InputText
                            id="edit-name"
                            value={editForm.name}
                            onChange={(e) => setEditForm({ ...editForm, name: e.target.value })}
                            className="w-full"
                        />
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div className="flex flex-col gap-1">
                            <label htmlFor="edit-type" className="text-sm font-medium text-[var(--acu-text)]">
                                Type
                            </label>
                            <Dropdown
                                id="edit-type"
                                value={editForm.type}
                                options={gameTypeOptions}
                                onChange={(e) => setEditForm({ ...editForm, type: e.value })}
                                className="w-full"
                            />
                        </div>
                        <div className="flex flex-col gap-1">
                            <label htmlFor="edit-status" className="text-sm font-medium text-[var(--acu-text)]">
                                Status
                            </label>
                            <Dropdown
                                id="edit-status"
                                value={editForm.status}
                                options={statusOptions}
                                onChange={(e) => setEditForm({ ...editForm, status: e.value })}
                                className="w-full"
                            />
                        </div>
                    </div>
                    <div className="flex flex-col gap-1">
                        <label htmlFor="edit-version" className="text-sm font-medium text-[var(--acu-text)]">
                            Version
                        </label>
                        <InputText
                            id="edit-version"
                            value={editForm.version}
                            onChange={(e) => setEditForm({ ...editForm, version: e.target.value })}
                            placeholder="e.g., 1.0.0"
                            className="w-full"
                        />
                    </div>
                    <div className="flex flex-col gap-1">
                        <label htmlFor="edit-description" className="text-sm font-medium text-[var(--acu-text)]">
                            Description
                        </label>
                        <InputTextarea
                            id="edit-description"
                            value={editForm.description}
                            onChange={(e) => setEditForm({ ...editForm, description: e.target.value })}
                            rows={3}
                            className="w-full"
                        />
                    </div>
                    <div className="flex flex-col gap-1">
                        <label htmlFor="edit-thumbnail" className="text-sm font-medium text-[var(--acu-text)]">
                            Thumbnail URL
                        </label>
                        <InputText
                            id="edit-thumbnail"
                            value={editForm.thumbnail_url}
                            onChange={(e) => setEditForm({ ...editForm, thumbnail_url: e.target.value })}
                            placeholder="https://..."
                            className="w-full"
                        />
                    </div>
                </div>
            </Dialog>
        </UserLayout>
    );
}
