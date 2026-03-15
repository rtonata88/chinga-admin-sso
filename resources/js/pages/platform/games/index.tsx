import PageHeader from '@/components/acumatica/Common/PageHeader';
import StatusBadge from '@/components/acumatica/Common/StatusBadge';
import UserLayout from '@/layouts/user-layout';
import type { StatusVariant } from '@/types/acumatica';
import { Head, Link } from '@inertiajs/react';
import { Button } from 'primereact/button';
import { Column } from 'primereact/column';
import { DataTable } from 'primereact/datatable';
import { Dialog } from 'primereact/dialog';
import { Dropdown } from 'primereact/dropdown';
import { InputText } from 'primereact/inputtext';
import { InputTextarea } from 'primereact/inputtextarea';
import { useEffect, useState } from 'react';

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

const typeOptions = [
    { label: 'All Types', value: null },
    { label: 'Slots', value: 'slots' },
    { label: 'Table', value: 'table' },
    { label: 'Instant', value: 'instant' },
    { label: 'Other', value: 'other' },
];

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

export default function GamesIndex() {
    const [games, setGames] = useState<Game[]>([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [typeFilter, setTypeFilter] = useState<string | null>(null);

    // New game dialog
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

    const getCsrfToken = () => {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    };

    const generateSlug = (name: string) => {
        return name
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/(^-|-$)/g, '');
    };

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
                setGames(res.data);
                setLoading(false);
            });
    };

    useEffect(() => {
        fetchGames();
    }, [typeFilter]);

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
                    name: '',
                    slug: '',
                    description: '',
                    type: 'slots',
                    status: 'development',
                    version: '',
                    thumbnail_url: '',
                });
                fetchGames();
            } else {
                alert(data.message || 'Failed to create game');
            }
        } catch (error) {
            console.error('Failed to create game:', error);
            alert('Failed to create game');
        } finally {
            setSaving(false);
        }
    };

    const nameTemplate = (row: Game) => (
        <div>
            <div className="font-medium text-sm text-[var(--acu-text)]">{row.name}</div>
            <div className="text-xs text-[var(--acu-text-light)]">{row.slug}</div>
        </div>
    );

    const typeTemplate = (row: Game) => (
        <span className="text-sm capitalize text-[var(--acu-text)]">{row.type}</span>
    );

    const statusTemplate = (row: Game) => (
        <StatusBadge status={mapGameStatus(row.status)} label={row.status} />
    );

    const tenantsTemplate = (row: Game) => (
        <span className="text-sm text-[var(--acu-text)]">{row.tenants_count}</span>
    );

    const actionsTemplate = (row: Game) => (
        <Link href={`/platform/games/${row.uuid}`}>
            <Button
                icon="pi pi-eye"
                severity="secondary"
                text
                size="small"
                tooltip="View game"
            />
        </Link>
    );

    const dialogFooter = (
        <div className="flex justify-end gap-2">
            <Button
                label="Cancel"
                icon="pi pi-times"
                severity="secondary"
                outlined
                onClick={() => setAddOpen(false)}
            />
            <Button
                label={saving ? 'Creating...' : 'Create Game'}
                icon="pi pi-check"
                onClick={handleAddGame}
                disabled={saving || !formData.name || !formData.slug || !formData.type}
                loading={saving}
            />
        </div>
    );

    return (
        <UserLayout title="Game Catalog">
            <Head title="Game Catalog" />

            <div className="space-y-6">
                <PageHeader title="Game Catalog" subtitle="Manage games available to tenants">
                    <Button
                        label="Refresh"
                        icon="pi pi-refresh"
                        severity="secondary"
                        outlined
                        onClick={fetchGames}
                    />
                    <Button
                        label="New Game"
                        icon="pi pi-plus"
                        onClick={() => setAddOpen(true)}
                    />
                </PageHeader>

                {/* Search & Filter */}
                <div className="acu-fieldset">
                    <div className="acu-fieldset-body">
                        <div className="flex gap-2">
                            <span className="p-input-icon-left">
                                <i className="pi pi-search" />
                                <InputText
                                    placeholder="Search games..."
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    onKeyDown={(e) => e.key === 'Enter' && fetchGames()}
                                    style={{ width: '20rem' }}
                                />
                            </span>
                            <Button
                                label="Search"
                                icon="pi pi-search"
                                onClick={fetchGames}
                            />
                            <Dropdown
                                value={typeFilter}
                                options={typeOptions}
                                onChange={(e) => setTypeFilter(e.value)}
                                placeholder="Filter by type"
                            />
                        </div>
                    </div>
                </div>

                {/* Games Table */}
                <div className="acu-fieldset" style={{ '--fieldset-color': 'var(--acu-fieldset-blue)' } as React.CSSProperties}>
                    <div className="acu-fieldset-header">
                        <div className="acu-fieldset-title">
                            <i className="pi pi-play" />
                            <span>Games</span>
                            <span className="text-xs font-normal text-[var(--acu-text-light)] ml-1">
                                ({games.length})
                            </span>
                        </div>
                    </div>
                    <div className="acu-fieldset-body p-0">
                        <DataTable
                            value={games}
                            loading={loading}
                            size="small"
                            emptyMessage="No games found"
                            showGridlines={false}
                            dataKey="uuid"
                            stripedRows
                        >
                            <Column header="Game" body={nameTemplate} sortable sortField="name" />
                            <Column header="Type" body={typeTemplate} sortable sortField="type" />
                            <Column header="Status" body={statusTemplate} sortable sortField="status" />
                            <Column field="version" header="Version" />
                            <Column header="Tenants" body={tenantsTemplate} sortable sortField="tenants_count" />
                            <Column header="Actions" body={actionsTemplate} style={{ width: '5rem' }} />
                        </DataTable>
                    </div>
                </div>
            </div>

            {/* New Game Dialog */}
            <Dialog
                header="New Game"
                visible={addOpen}
                style={{ width: '32rem' }}
                onHide={() => setAddOpen(false)}
                footer={dialogFooter}
                modal
                draggable={false}
            >
                <p className="text-sm text-[var(--acu-text-muted)] mb-4">
                    Add a new game to the platform catalog
                </p>
                <div className="space-y-4">
                    <div className="flex flex-col gap-1">
                        <label htmlFor="game-name" className="text-sm font-medium text-[var(--acu-text)]">
                            Name *
                        </label>
                        <InputText
                            id="game-name"
                            value={formData.name}
                            onChange={(e) => {
                                const name = e.target.value;
                                setFormData({
                                    ...formData,
                                    name,
                                    slug: formData.slug || generateSlug(name),
                                });
                            }}
                            placeholder="e.g., Lucky Sevens"
                            className="w-full"
                        />
                    </div>
                    <div className="flex flex-col gap-1">
                        <label htmlFor="game-slug" className="text-sm font-medium text-[var(--acu-text)]">
                            Slug *
                        </label>
                        <InputText
                            id="game-slug"
                            value={formData.slug}
                            onChange={(e) =>
                                setFormData({
                                    ...formData,
                                    slug: e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ''),
                                })
                            }
                            placeholder="e.g., lucky-sevens"
                            className="w-full"
                        />
                    </div>
                    <div className="grid grid-cols-2 gap-4">
                        <div className="flex flex-col gap-1">
                            <label htmlFor="game-type" className="text-sm font-medium text-[var(--acu-text)]">
                                Type *
                            </label>
                            <Dropdown
                                id="game-type"
                                value={formData.type}
                                options={gameTypeOptions}
                                onChange={(e) => setFormData({ ...formData, type: e.value })}
                                className="w-full"
                            />
                        </div>
                        <div className="flex flex-col gap-1">
                            <label htmlFor="game-status" className="text-sm font-medium text-[var(--acu-text)]">
                                Status
                            </label>
                            <Dropdown
                                id="game-status"
                                value={formData.status}
                                options={statusOptions}
                                onChange={(e) => setFormData({ ...formData, status: e.value })}
                                className="w-full"
                            />
                        </div>
                    </div>
                    <div className="flex flex-col gap-1">
                        <label htmlFor="game-version" className="text-sm font-medium text-[var(--acu-text)]">
                            Version
                        </label>
                        <InputText
                            id="game-version"
                            value={formData.version}
                            onChange={(e) => setFormData({ ...formData, version: e.target.value })}
                            placeholder="e.g., 1.0.0"
                            className="w-full"
                        />
                    </div>
                    <div className="flex flex-col gap-1">
                        <label htmlFor="game-description" className="text-sm font-medium text-[var(--acu-text)]">
                            Description
                        </label>
                        <InputTextarea
                            id="game-description"
                            value={formData.description}
                            onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                            placeholder="Brief game description..."
                            rows={3}
                            className="w-full"
                        />
                    </div>
                    <div className="flex flex-col gap-1">
                        <label htmlFor="game-thumbnail" className="text-sm font-medium text-[var(--acu-text)]">
                            Thumbnail URL
                        </label>
                        <InputText
                            id="game-thumbnail"
                            value={formData.thumbnail_url}
                            onChange={(e) => setFormData({ ...formData, thumbnail_url: e.target.value })}
                            placeholder="https://..."
                            className="w-full"
                        />
                    </div>
                </div>
            </Dialog>
        </UserLayout>
    );
}
