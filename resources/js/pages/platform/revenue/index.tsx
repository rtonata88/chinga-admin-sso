import UserLayout from '@/layouts/user-layout';
import { Head } from '@inertiajs/react';
import { Column } from 'primereact/column';
import { DataTable } from 'primereact/datatable';
import { useEffect, useState } from 'react';

interface RevenueSummary {
    totals: {
        total_bets: number;
        total_wins: number;
        gross_gaming_revenue: number;
        chinga_share: number;
        tenant_share: number;
    };
    per_tenant: Array<{
        tenant_id: number;
        tenant: { uuid: string; name: string };
        total_bets: number;
        gross_gaming_revenue: number;
        chinga_share: number;
        tenant_share: number;
    }>;
}

export default function RevenueIndex() {
    const [summary, setSummary] = useState<RevenueSummary | null>(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        fetch('/api/v1/platform/revenue/summary')
            .then((res) => res.json())
            .then((data) => {
                setSummary(data);
                setLoading(false);
            });
    }, []);

    const currencyTemplate = (value: number) =>
        `NAD ${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}`;

    return (
        <UserLayout title="Revenue">
            <Head title="Revenue" />

            <div className="space-y-6">
                <h1 className="text-2xl font-bold">Revenue Dashboard</h1>

                {loading ? (
                    <div className="text-center py-8">Loading...</div>
                ) : summary ? (
                    <>
                        <div className="grid grid-cols-1 md:grid-cols-5 gap-4">
                            <div className="acu-fieldset">
                                <div className="acu-fieldset-body text-center">
                                    <div className="text-xl font-bold">
                                        {currencyTemplate(summary.totals.total_bets)}
                                    </div>
                                    <div className="text-sm text-muted-foreground">Total Bets</div>
                                </div>
                            </div>
                            <div className="acu-fieldset">
                                <div className="acu-fieldset-body text-center">
                                    <div className="text-xl font-bold">
                                        {currencyTemplate(summary.totals.total_wins)}
                                    </div>
                                    <div className="text-sm text-muted-foreground">Total Wins</div>
                                </div>
                            </div>
                            <div className="acu-fieldset">
                                <div className="acu-fieldset-body text-center">
                                    <div className="text-xl font-bold">
                                        {currencyTemplate(summary.totals.gross_gaming_revenue)}
                                    </div>
                                    <div className="text-sm text-muted-foreground">GGR</div>
                                </div>
                            </div>
                            <div className="acu-fieldset">
                                <div className="acu-fieldset-body text-center">
                                    <div className="text-xl font-bold">
                                        {currencyTemplate(summary.totals.chinga_share)}
                                    </div>
                                    <div className="text-sm text-muted-foreground">Chinga Share</div>
                                </div>
                            </div>
                            <div className="acu-fieldset">
                                <div className="acu-fieldset-body text-center">
                                    <div className="text-xl font-bold">
                                        {currencyTemplate(summary.totals.tenant_share)}
                                    </div>
                                    <div className="text-sm text-muted-foreground">Tenant Share</div>
                                </div>
                            </div>
                        </div>

                        <div className="acu-fieldset">
                            <div className="acu-fieldset-header">
                                <span className="acu-fieldset-title">Revenue by Tenant</span>
                            </div>
                            <div className="acu-fieldset-body">
                                <DataTable value={summary.per_tenant} stripedRows>
                                    <Column
                                        header="Tenant"
                                        body={(row) => row.tenant?.name || '—'}
                                        sortable
                                    />
                                    <Column
                                        header="Total Bets"
                                        body={(row) => currencyTemplate(row.total_bets)}
                                    />
                                    <Column
                                        header="GGR"
                                        body={(row) => currencyTemplate(row.gross_gaming_revenue)}
                                    />
                                    <Column
                                        header="Chinga Share"
                                        body={(row) => currencyTemplate(row.chinga_share)}
                                    />
                                    <Column
                                        header="Tenant Share"
                                        body={(row) => currencyTemplate(row.tenant_share)}
                                    />
                                </DataTable>
                            </div>
                        </div>
                    </>
                ) : null}
            </div>
        </UserLayout>
    );
}
