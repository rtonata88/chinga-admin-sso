import PageHeader from '@/components/acumatica/Common/PageHeader';
import UserLayout from '@/layouts/user-layout';
import { Head } from '@inertiajs/react';
import { Button } from 'primereact/button';
import { Calendar } from 'primereact/calendar';
import { Column } from 'primereact/column';
import { DataTable } from 'primereact/datatable';
import { useEffect, useState } from 'react';

interface RevenueTotals {
    total_bets: number;
    total_wins: number;
    gross_gaming_revenue: number;
    chinga_share: number;
    tenant_share: number;
}

interface GameRevenue {
    game_id: number;
    game: { uuid: string; name: string } | null;
    total_bets: number;
    total_wins: number;
    gross_gaming_revenue: number;
    tenant_share: number;
}

interface RevenueRecord {
    id: number;
    game: { uuid: string; name: string } | null;
    period_type: string;
    period_start: string;
    period_end: string;
    total_bets: number;
    total_wins: number;
    gross_gaming_revenue: number;
    chinga_share: number;
    tenant_share: number;
    status: string;
}

interface StatCardProps {
    icon: string;
    accentColor: string;
    title: string;
    value: string;
}

const currency = (value: number) =>
    `NAD ${Number(value || 0).toLocaleString(undefined, { minimumFractionDigits: 2 })}`;

function StatCard({ icon, accentColor, title, value }: StatCardProps) {
    return (
        <div className="relative overflow-hidden rounded-xl p-5 transition-all duration-300"
            style={{ background: 'var(--acu-surface-card)', border: '1px solid var(--acu-border)' }}
            onMouseEnter={(e) => { e.currentTarget.style.borderColor = `${accentColor}30`; e.currentTarget.style.boxShadow = `0 8px 32px ${accentColor}15`; }}
            onMouseLeave={(e) => { e.currentTarget.style.borderColor = 'var(--acu-border)'; e.currentTarget.style.boxShadow = 'none'; }}>
            <div className="absolute inset-0 opacity-[0.03]" style={{ background: `radial-gradient(circle at top right, ${accentColor}, transparent 70%)` }} />
            <div className="relative">
                <div className="flex items-center justify-between mb-4">
                    <span className="text-[10px] font-semibold uppercase tracking-[0.1em]" style={{ color: 'var(--acu-text-light)', fontFamily: 'var(--font-body)' }}>{title}</span>
                    <div className="w-9 h-9 rounded-lg flex items-center justify-center" style={{ background: `${accentColor}12`, border: `1px solid ${accentColor}20` }}>
                        <i className={`${icon} text-sm`} style={{ color: accentColor }} />
                    </div>
                </div>
                <div className="text-[1.75rem] font-bold leading-none" style={{ color: 'var(--acu-text)', fontFamily: 'var(--font-display)' }}>{value}</div>
            </div>
        </div>
    );
}

export default function TenantRevenue() {
    const [totals, setTotals] = useState<RevenueTotals | null>(null);
    const [perGame, setPerGame] = useState<GameRevenue[]>([]);
    const [records, setRecords] = useState<RevenueRecord[]>([]);
    const [loading, setLoading] = useState(true);
    const [recordsLoading, setRecordsLoading] = useState(true);
    const [dateRange, setDateRange] = useState<(Date | null)[]>([
        new Date(new Date().getFullYear(), new Date().getMonth(), 1),
        new Date(),
    ]);

    const fromStr = dateRange[0]?.toISOString().split('T')[0] || '';
    const toStr = dateRange[1]?.toISOString().split('T')[0] || '';

    const fetchSummary = async () => {
        setLoading(true);
        try {
            const params = new URLSearchParams();
            if (fromStr) params.append('from', fromStr);
            if (toStr) params.append('to', toStr);

            const res = await fetch(`/api/v1/admin/revenue/summary?${params}`, {
                headers: { Accept: 'application/json' },
            });
            const data = await res.json();
            if (data.success) {
                setTotals(data.data.totals);
                setPerGame(data.data.per_game);
            }
        } catch (error) {
            console.error('Failed to fetch revenue summary:', error);
        } finally {
            setLoading(false);
        }
    };

    const fetchRecords = async () => {
        setRecordsLoading(true);
        try {
            const params = new URLSearchParams();
            if (fromStr) params.append('from', fromStr);
            if (toStr) params.append('to', toStr);

            const res = await fetch(`/api/v1/admin/revenue?${params}`, {
                headers: { Accept: 'application/json' },
            });
            const data = await res.json();
            if (data.success) {
                setRecords(data.data);
            }
        } catch (error) {
            console.error('Failed to fetch revenue records:', error);
        } finally {
            setRecordsLoading(false);
        }
    };

    useEffect(() => {
        fetchSummary();
        fetchRecords();
    }, []);

    const handleFilter = () => {
        fetchSummary();
        fetchRecords();
    };

    const statCards = totals
        ? [
              { title: 'Total Bets', value: currency(totals.total_bets), icon: 'pi pi-arrow-down', accentColor: '#58A6FF' },
              { title: 'Total Wins', value: currency(totals.total_wins), icon: 'pi pi-arrow-up', accentColor: '#3FB950' },
              { title: 'Gross Gaming Revenue', value: currency(totals.gross_gaming_revenue), icon: 'pi pi-chart-line', accentColor: '#BC8CFF' },
              { title: 'Your Share', value: currency(totals.tenant_share), icon: 'pi pi-wallet', accentColor: '#3FB950' },
              { title: 'Platform Share', value: currency(totals.chinga_share), icon: 'pi pi-building', accentColor: '#D29922' },
          ]
        : [];

    return (
        <UserLayout title="Revenue">
            <Head title="Revenue" />

            <div className="space-y-8">
                <PageHeader title="Revenue" subtitle="Track your gaming revenue and earnings" />

                {/* Date Filter */}
                <div className="rounded-xl p-4" style={{ background: 'var(--acu-surface-card)', border: '1px solid var(--acu-border)' }}>
                    <div className="flex items-center gap-2 mb-3">
                        <i className="pi pi-filter text-sm" style={{ color: 'var(--acu-text-light)' }} />
                        <span className="text-[10px] font-semibold uppercase tracking-[0.1em]" style={{ color: 'var(--acu-text-light)', fontFamily: 'var(--font-body)' }}>Date Range</span>
                    </div>
                    <div className="flex flex-wrap gap-3 items-end">
                        <Calendar
                            value={dateRange as Date[]}
                            onChange={(e) => setDateRange(e.value as (Date | null)[])}
                            selectionMode="range"
                            dateFormat="yy-mm-dd"
                            placeholder="Select date range"
                            showIcon
                            className="w-72"
                        />
                        <Button
                            label="Apply"
                            icon="pi pi-check"
                            size="small"
                            onClick={handleFilter}
                        />
                    </div>
                </div>

                {/* Summary Cards */}
                {loading ? (
                    <div className="text-center py-8" style={{ color: 'var(--acu-text-muted)' }}>Loading...</div>
                ) : (
                    <>
                        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-5">
                            {statCards.map((card) => (
                                <StatCard
                                    key={card.title}
                                    icon={card.icon}
                                    accentColor={card.accentColor}
                                    title={card.title}
                                    value={card.value}
                                />
                            ))}
                        </div>

                        {/* Revenue by Game */}
                        {perGame.length > 0 && (
                            <div className="rounded-xl overflow-hidden" style={{ background: 'var(--acu-surface-card)', border: '1px solid var(--acu-border)' }}>
                                <div className="px-5 py-4 flex items-center gap-2" style={{ borderBottom: '1px solid var(--acu-border)' }}>
                                    <div className="w-8 h-8 rounded-lg flex items-center justify-center" style={{ background: '#BC8CFF12', border: '1px solid #BC8CFF20' }}>
                                        <i className="pi pi-play text-sm" style={{ color: '#BC8CFF' }} />
                                    </div>
                                    <span className="text-sm font-semibold" style={{ color: 'var(--acu-text)', fontFamily: 'var(--font-display)' }}>Revenue by Game</span>
                                </div>
                                <div>
                                    <DataTable value={perGame} size="small" showGridlines={false}>
                                        <Column header="Game" body={(row: GameRevenue) => row.game?.name || 'Unknown'} />
                                        <Column header="Total Bets" body={(row: GameRevenue) => currency(row.total_bets)} />
                                        <Column header="Total Wins" body={(row: GameRevenue) => currency(row.total_wins)} />
                                        <Column header="GGR" body={(row: GameRevenue) => currency(row.gross_gaming_revenue)} />
                                        <Column header="Your Share" body={(row: GameRevenue) => currency(row.tenant_share)} />
                                    </DataTable>
                                </div>
                            </div>
                        )}
                    </>
                )}

                {/* Detailed Records */}
                <div className="rounded-xl overflow-hidden" style={{ background: 'var(--acu-surface-card)', border: '1px solid var(--acu-border)' }}>
                    <div className="px-5 py-4 flex items-center gap-2" style={{ borderBottom: '1px solid var(--acu-border)' }}>
                        <div className="w-8 h-8 rounded-lg flex items-center justify-center" style={{ background: '#58A6FF12', border: '1px solid #58A6FF20' }}>
                            <i className="pi pi-list text-sm" style={{ color: '#58A6FF' }} />
                        </div>
                        <span className="text-sm font-semibold" style={{ color: 'var(--acu-text)', fontFamily: 'var(--font-display)' }}>Revenue Records</span>
                    </div>
                    <div>
                        <DataTable
                            value={records}
                            loading={recordsLoading}
                            size="small"
                            showGridlines={false}
                            emptyMessage="No revenue records found for the selected period"
                        >
                            <Column header="Period" body={(row: RevenueRecord) => `${row.period_start} — ${row.period_end}`} />
                            <Column header="Game" body={(row: RevenueRecord) => row.game?.name || 'Unknown'} />
                            <Column header="Bets" body={(row: RevenueRecord) => currency(row.total_bets)} />
                            <Column header="Wins" body={(row: RevenueRecord) => currency(row.total_wins)} />
                            <Column header="GGR" body={(row: RevenueRecord) => currency(row.gross_gaming_revenue)} />
                            <Column header="Your Share" body={(row: RevenueRecord) => currency(row.tenant_share)} />
                            <Column
                                header="Status"
                                body={(row: RevenueRecord) => (
                                    <span
                                        className="text-xs px-2 py-0.5 rounded-md font-medium"
                                        style={
                                            row.status === 'confirmed'
                                                ? { background: '#3FB95018', color: '#3FB950', border: '1px solid #3FB95030' }
                                                : { background: '#D2992218', color: '#D29922', border: '1px solid #D2992230' }
                                        }
                                    >
                                        {row.status}
                                    </span>
                                )}
                            />
                        </DataTable>
                    </div>
                </div>
            </div>
        </UserLayout>
    );
}
