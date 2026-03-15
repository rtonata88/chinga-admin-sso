import PageHeader from '@/components/acumatica/Common/PageHeader';
import StatusBadge from '@/components/acumatica/Common/StatusBadge';
import UserLayout from '@/layouts/user-layout';
import type { StatusVariant } from '@/types/acumatica';
import { Head } from '@inertiajs/react';
import { Button } from 'primereact/button';
import { Column } from 'primereact/column';
import { DataTable } from 'primereact/datatable';
import { Dialog } from 'primereact/dialog';
import { Dropdown } from 'primereact/dropdown';
import { InputText } from 'primereact/inputtext';
import { useEffect, useState } from 'react';

interface KycDocument {
    uuid: string;
    user: {
        uuid: string;
        name: string;
        email: string;
    };
    document_type: string;
    document_type_label: string;
    status: string;
    rejection_reason: string | null;
    created_at: string;
}

interface Stats {
    documents: {
        pending: number;
        approved: number;
        rejected: number;
    };
}

function mapKycStatusToVariant(status: string): StatusVariant {
    const map: Record<string, StatusVariant> = {
        pending: 'pending',
        approved: 'active',
        rejected: 'error',
    };
    return map[status] || 'inactive';
}

interface StatCardProps {
    icon: string;
    iconColor: string;
    title: string;
    value: string | number;
}

function StatCard({ icon, iconColor, title, value }: StatCardProps) {
    return (
        <div className="acu-fieldset">
            <div className="p-4">
                <div className="flex items-center justify-between mb-3">
                    <span className="text-xs font-semibold uppercase tracking-wide text-[var(--acu-text-muted)]">
                        {title}
                    </span>
                    <div
                        className="w-8 h-8 rounded-lg flex items-center justify-center"
                        style={{ backgroundColor: `${iconColor}15`, color: iconColor }}
                    >
                        <i className={`${icon} text-sm`} />
                    </div>
                </div>
                <div className="text-2xl font-bold text-[var(--acu-text)]">{value}</div>
            </div>
        </div>
    );
}

const statusFilterOptions = [
    { label: 'Pending', value: 'pending' },
    { label: 'Approved', value: 'approved' },
    { label: 'Rejected', value: 'rejected' },
];

export default function Kyc() {
    const [documents, setDocuments] = useState<KycDocument[]>([]);
    const [stats, setStats] = useState<Stats | null>(null);
    const [loading, setLoading] = useState(true);
    const [statusFilter, setStatusFilter] = useState('pending');
    const [selectedDoc, setSelectedDoc] = useState<KycDocument | null>(null);
    const [rejectReason, setRejectReason] = useState('');
    const [processing, setProcessing] = useState(false);

    const fetchDocuments = async () => {
        setLoading(true);
        try {
            const response = await fetch(
                `/api/v1/admin/kyc?status=${statusFilter}`,
                { headers: { Accept: 'application/json' } },
            );
            const data = await response.json();
            if (data.success) {
                setDocuments(data.data);
            }
        } catch (error) {
            console.error('Failed to fetch documents:', error);
        } finally {
            setLoading(false);
        }
    };

    const fetchStats = async () => {
        try {
            const response = await fetch('/api/v1/admin/kyc/stats', {
                headers: { Accept: 'application/json' },
            });
            const data = await response.json();
            if (data.success) {
                setStats(data.data);
            }
        } catch (error) {
            console.error('Failed to fetch stats:', error);
        }
    };

    useEffect(() => {
        fetchDocuments();
        fetchStats();
    }, [statusFilter]);

    const getCsrfToken = () => {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    };

    const handleApprove = async (uuid: string) => {
        setProcessing(true);
        try {
            const response = await fetch(`/api/v1/admin/kyc/${uuid}/approve`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
            });
            const data = await response.json();
            if (data.success) {
                fetchDocuments();
                fetchStats();
                setSelectedDoc(null);
            }
        } catch (error) {
            console.error('Failed to approve:', error);
        } finally {
            setProcessing(false);
        }
    };

    const handleReject = async (uuid: string) => {
        if (!rejectReason) return;

        setProcessing(true);
        try {
            const response = await fetch(`/api/v1/admin/kyc/${uuid}/reject`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': getCsrfToken(),
                },
                body: JSON.stringify({ reason: rejectReason }),
            });
            const data = await response.json();
            if (data.success) {
                fetchDocuments();
                fetchStats();
                setSelectedDoc(null);
                setRejectReason('');
            }
        } catch (error) {
            console.error('Failed to reject:', error);
        } finally {
            setProcessing(false);
        }
    };

    const userTemplate = (row: KycDocument) => (
        <div>
            <div className="font-medium text-sm text-[var(--acu-text)]">{row.user.name}</div>
            <div className="text-xs text-[var(--acu-text-light)]">{row.user.email}</div>
        </div>
    );

    const docTypeTemplate = (row: KycDocument) => (
        <span className="text-sm text-[var(--acu-text)]">{row.document_type_label}</span>
    );

    const statusTemplate = (row: KycDocument) => (
        <StatusBadge status={mapKycStatusToVariant(row.status)} label={row.status} />
    );

    const submittedTemplate = (row: KycDocument) => (
        <span className="text-sm text-[var(--acu-text)]">
            {new Date(row.created_at).toLocaleDateString()}
        </span>
    );

    const actionsTemplate = (row: KycDocument) => (
        <div className="flex gap-1">
            <Button
                icon="pi pi-eye"
                text
                severity="secondary"
                size="small"
                tooltip="View document"
                onClick={() => setSelectedDoc(row)}
            />
            {row.status === 'pending' && (
                <>
                    <Button
                        icon="pi pi-check-circle"
                        text
                        severity="success"
                        size="small"
                        tooltip="Approve"
                        onClick={() => handleApprove(row.uuid)}
                    />
                    <Button
                        icon="pi pi-times-circle"
                        text
                        severity="danger"
                        size="small"
                        tooltip="Reject"
                        onClick={() => setSelectedDoc(row)}
                    />
                </>
            )}
        </div>
    );

    const dialogFooter = (
        <div className="flex justify-end gap-2">
            <Button
                label="Close"
                icon="pi pi-times"
                outlined
                size="small"
                onClick={() => {
                    setSelectedDoc(null);
                    setRejectReason('');
                }}
            />
            {selectedDoc?.status === 'pending' && (
                <>
                    <Button
                        label="Reject"
                        icon="pi pi-times-circle"
                        severity="danger"
                        size="small"
                        onClick={() => selectedDoc && handleReject(selectedDoc.uuid)}
                        disabled={!rejectReason || processing}
                    />
                    <Button
                        label="Approve"
                        icon="pi pi-check-circle"
                        severity="success"
                        size="small"
                        onClick={() => selectedDoc && handleApprove(selectedDoc.uuid)}
                        disabled={processing}
                    />
                </>
            )}
        </div>
    );

    return (
        <UserLayout title="KYC Review">
            <Head title="KYC Review" />

            <div className="space-y-6">
                <PageHeader title="KYC Review" subtitle="Review and verify user documents">
                    <Button
                        label="Refresh"
                        icon="pi pi-refresh"
                        outlined
                        size="small"
                        onClick={() => {
                            fetchDocuments();
                            fetchStats();
                        }}
                    />
                </PageHeader>

                {/* Stats */}
                {stats && (
                    <div className="grid gap-4 md:grid-cols-3">
                        <StatCard
                            icon="pi pi-clock"
                            iconColor="#F59E0B"
                            title="Pending"
                            value={stats.documents.pending}
                        />
                        <StatCard
                            icon="pi pi-check-circle"
                            iconColor="#10B981"
                            title="Approved"
                            value={stats.documents.approved}
                        />
                        <StatCard
                            icon="pi pi-times-circle"
                            iconColor="#EF4444"
                            title="Rejected"
                            value={stats.documents.rejected}
                        />
                    </div>
                )}

                {/* Filter */}
                <div className="acu-fieldset">
                    <div className="acu-fieldset-body">
                        <div className="flex gap-3 items-center">
                            <label className="text-sm font-medium text-[var(--acu-text-muted)]">
                                Status:
                            </label>
                            <Dropdown
                                value={statusFilter}
                                onChange={(e) => setStatusFilter(e.value)}
                                options={statusFilterOptions}
                                className="w-40"
                            />
                        </div>
                    </div>
                </div>

                {/* Documents Table */}
                <div className="acu-fieldset" style={{ '--fieldset-color': 'var(--acu-fieldset-amber)' } as React.CSSProperties}>
                    <div className="acu-fieldset-header">
                        <div className="acu-fieldset-title">
                            <i className="pi pi-file" />
                            <span>Documents</span>
                            <span className="text-xs font-normal text-[var(--acu-text-light)] ml-1">
                                ({documents.length})
                            </span>
                        </div>
                    </div>
                    <div className="acu-fieldset-body p-0">
                        <DataTable
                            value={documents}
                            loading={loading}
                            size="small"
                            showGridlines={false}
                            emptyMessage="No documents found"
                        >
                            <Column header="User" body={userTemplate} />
                            <Column header="Document Type" body={docTypeTemplate} />
                            <Column header="Status" body={statusTemplate} />
                            <Column header="Submitted" body={submittedTemplate} />
                            <Column header="Actions" body={actionsTemplate} style={{ width: '10rem' }} />
                        </DataTable>
                    </div>
                </div>
            </div>

            {/* Review Dialog */}
            <Dialog
                visible={!!selectedDoc}
                onHide={() => {
                    setSelectedDoc(null);
                    setRejectReason('');
                }}
                header="Review Document"
                footer={dialogFooter}
                style={{ width: '32rem' }}
                modal
                draggable={false}
            >
                <div className="space-y-4">
                    <p className="text-sm text-[var(--acu-text-muted)]">
                        {selectedDoc?.document_type_label} for {selectedDoc?.user.name}
                    </p>

                    {selectedDoc?.rejection_reason && (
                        <div className="rounded-md bg-red-50 p-3 text-sm text-red-800">
                            <i className="pi pi-info-circle mr-2" />
                            <strong>Rejection reason:</strong> {selectedDoc.rejection_reason}
                        </div>
                    )}

                    {selectedDoc?.status === 'pending' && (
                        <div className="space-y-2">
                            <label className="text-sm font-medium text-[var(--acu-text)]">
                                Rejection Reason (if rejecting)
                            </label>
                            <InputText
                                value={rejectReason}
                                onChange={(e) => setRejectReason(e.target.value)}
                                placeholder="Enter reason for rejection..."
                                className="w-full"
                            />
                        </div>
                    )}
                </div>
            </Dialog>
        </UserLayout>
    );
}
