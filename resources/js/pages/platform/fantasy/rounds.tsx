import PageHeader from '@/components/acumatica/Common/PageHeader';
import StatusBadge from '@/components/acumatica/Common/StatusBadge';
import UserLayout from '@/layouts/user-layout';
import type { StatusVariant } from '@/types/acumatica';
import { Head } from '@inertiajs/react';
import { Column } from 'primereact/column';
import { DataTable } from 'primereact/datatable';

interface Round {
    id: number;
    round_number: number;
    date: string;
    teams: string;
    total_bets: number;
    total_wagered: number;
    total_paid_out: number;
    status: string;
}

interface Props {
    rounds: Round[];
}

function formatCurrency(amount: number): string {
    return `NAD ${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function mapRoundStatus(status: string): StatusVariant {
    switch (status) {
        case 'completed':
            return 'active';
        case 'in_progress':
            return 'pending';
        case 'cancelled':
            return 'error';
        default:
            return 'inactive';
    }
}

export default function Rounds({ rounds = [] }: Props) {
    const isEmpty = !rounds || rounds.length === 0;

    const roundNumberTemplate = (row: Round) => (
        <span className="font-medium text-sm" style={{ color: 'var(--acu-text)' }}>#{row.round_number}</span>
    );

    const dateTemplate = (row: Round) => (
        <span className="text-sm" style={{ color: 'var(--acu-text-muted)' }}>
            {new Date(row.date).toLocaleDateString()}
        </span>
    );

    const teamsTemplate = (row: Round) => (
        <span className="text-sm" style={{ color: 'var(--acu-text)' }}>{row.teams}</span>
    );

    const betsTemplate = (row: Round) => (
        <span className="text-sm" style={{ color: 'var(--acu-text-muted)' }}>{row.total_bets.toLocaleString()}</span>
    );

    const wageredTemplate = (row: Round) => (
        <span className="text-sm" style={{ color: 'var(--acu-text-muted)' }}>{formatCurrency(row.total_wagered)}</span>
    );

    const paidOutTemplate = (row: Round) => (
        <span className="text-sm" style={{ color: 'var(--acu-text-muted)' }}>{formatCurrency(row.total_paid_out)}</span>
    );

    const statusTemplate = (row: Round) => (
        <StatusBadge status={mapRoundStatus(row.status)} label={row.status} />
    );

    return (
        <UserLayout title="Fantasy Rounds">
            <Head title="Fantasy Rounds" />

            <div className="space-y-8">
                <PageHeader title="Fantasy Rounds" subtitle="Monitor game rounds and results" />

                {isEmpty ? (
                    <div
                        className="rounded-xl p-12 text-center"
                        style={{
                            background: 'var(--acu-surface-card)',
                            border: '1px solid var(--acu-border)',
                        }}
                    >
                        <div
                            className="w-16 h-16 rounded-xl flex items-center justify-center mx-auto mb-4"
                            style={{ background: 'var(--acu-surface-hover)' }}
                        >
                            <i className="pi pi-clock text-2xl" style={{ color: 'var(--acu-text-light)' }} />
                        </div>
                        <h3
                            className="text-lg font-semibold mb-2"
                            style={{ color: 'var(--acu-text)', fontFamily: 'var(--font-display)' }}
                        >
                            No Rounds Yet
                        </h3>
                        <p className="text-sm" style={{ color: 'var(--acu-text-light)', maxWidth: '28rem', margin: '0 auto' }}>
                            Round monitoring will be available once the Fantasy game server integration is complete.
                        </p>
                    </div>
                ) : (
                    <div className="acu-fieldset" style={{ '--fieldset-color': 'var(--acu-fieldset-gold)' } as React.CSSProperties}>
                        <div className="acu-fieldset-header">
                            <div className="acu-fieldset-title">
                                <i className="pi pi-history" />
                                <span>Rounds</span>
                                <span className="text-xs font-normal ml-1" style={{ color: 'var(--acu-text-light)' }}>
                                    ({rounds.length})
                                </span>
                            </div>
                        </div>
                        <div className="acu-fieldset-body p-0">
                            <DataTable
                                value={rounds}
                                size="small"
                                showGridlines={false}
                                emptyMessage="No rounds found"
                            >
                                <Column header="Round #" body={roundNumberTemplate} />
                                <Column header="Date" body={dateTemplate} />
                                <Column header="Teams" body={teamsTemplate} />
                                <Column header="Bets" body={betsTemplate} />
                                <Column header="Wagered" body={wageredTemplate} />
                                <Column header="Paid Out" body={paidOutTemplate} />
                                <Column header="Status" body={statusTemplate} />
                            </DataTable>
                        </div>
                    </div>
                )}
            </div>
        </UserLayout>
    );
}
