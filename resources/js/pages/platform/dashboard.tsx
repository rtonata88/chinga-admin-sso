import UserLayout from '@/layouts/user-layout';
import { Head } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface DashboardData {
    total_tenants: number;
    active_tenants: number;
    total_players: number;
    total_venues: number;
    revenue_this_month: number;
    chinga_share_this_month: number;
    recent_tenants: Array<{
        uuid: string;
        name: string;
        slug: string;
        status: string;
        created_at: string;
    }>;
}

export default function PlatformDashboard() {
    const [data, setData] = useState<DashboardData | null>(null);
    const [loading, setLoading] = useState(true);

    useEffect(() => {
        fetch('/api/v1/platform/dashboard')
            .then((res) => res.json())
            .then((res) => {
                setData(res.data);
                setLoading(false);
            });
    }, []);

    return (
        <UserLayout title="Platform Dashboard">
            <Head title="Platform Dashboard" />

            <div className="space-y-6">
                <h1 className="text-2xl font-bold">Platform Dashboard</h1>

                {loading ? (
                    <div className="text-center py-8">Loading...</div>
                ) : data ? (
                    <>
                        <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div className="acu-fieldset">
                                <div className="acu-fieldset-header">
                                    <span className="acu-fieldset-title">Tenants</span>
                                </div>
                                <div className="acu-fieldset-body">
                                    <div className="text-3xl font-bold">{data.active_tenants}</div>
                                    <div className="text-sm text-muted-foreground">
                                        {data.total_tenants} total
                                    </div>
                                </div>
                            </div>

                            <div className="acu-fieldset">
                                <div className="acu-fieldset-header">
                                    <span className="acu-fieldset-title">Players</span>
                                </div>
                                <div className="acu-fieldset-body">
                                    <div className="text-3xl font-bold">{data.total_players}</div>
                                    <div className="text-sm text-muted-foreground">across all tenants</div>
                                </div>
                            </div>

                            <div className="acu-fieldset">
                                <div className="acu-fieldset-header">
                                    <span className="acu-fieldset-title">Venues</span>
                                </div>
                                <div className="acu-fieldset-body">
                                    <div className="text-3xl font-bold">{data.total_venues}</div>
                                    <div className="text-sm text-muted-foreground">active venues</div>
                                </div>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div className="acu-fieldset">
                                <div className="acu-fieldset-header">
                                    <span className="acu-fieldset-title">Revenue This Month</span>
                                </div>
                                <div className="acu-fieldset-body">
                                    <div className="text-3xl font-bold">
                                        NAD {Number(data.revenue_this_month).toLocaleString()}
                                    </div>
                                    <div className="text-sm text-muted-foreground">
                                        Chinga share: NAD {Number(data.chinga_share_this_month).toLocaleString()}
                                    </div>
                                </div>
                            </div>

                            <div className="acu-fieldset">
                                <div className="acu-fieldset-header">
                                    <span className="acu-fieldset-title">Recent Tenants</span>
                                </div>
                                <div className="acu-fieldset-body">
                                    {data.recent_tenants.length === 0 ? (
                                        <div className="text-sm text-muted-foreground">No tenants yet</div>
                                    ) : (
                                        <ul className="space-y-2">
                                            {data.recent_tenants.map((tenant) => (
                                                <li key={tenant.uuid} className="flex justify-between items-center">
                                                    <a
                                                        href={`/platform/tenants/${tenant.uuid}`}
                                                        className="font-medium hover:underline"
                                                    >
                                                        {tenant.name}
                                                    </a>
                                                    <span
                                                        className={`text-xs px-2 py-1 rounded ${
                                                            tenant.status === 'active'
                                                                ? 'bg-green-100 text-green-800'
                                                                : tenant.status === 'suspended'
                                                                  ? 'bg-yellow-100 text-yellow-800'
                                                                  : 'bg-red-100 text-red-800'
                                                        }`}
                                                    >
                                                        {tenant.status}
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            </div>
                        </div>
                    </>
                ) : null}
            </div>
        </UserLayout>
    );
}
