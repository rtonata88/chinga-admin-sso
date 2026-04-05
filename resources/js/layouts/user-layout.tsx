import AppLayout from '@/components/acumatica/Layout/AppLayout';
import type { SidebarNavGroup } from '@/components/acumatica/Layout/Sidebar';
import { usePage } from '@inertiajs/react';
import { type PropsWithChildren, useMemo } from 'react';

const accountGroup: SidebarNavGroup = {
    title: 'Account',
    items: [
        { label: 'Dashboard', icon: 'pi pi-home', href: '/dashboard' },
    ],
};


const adminGroup: SidebarNavGroup = {
    title: 'Administration',
    items: [
        { label: 'Admin Dashboard', icon: 'pi pi-th-large', href: '/admin' },
        { label: 'Users', icon: 'pi pi-users', href: '/admin/users' },
        { label: 'Wallets', icon: 'pi pi-credit-card', href: '/admin/wallets' },
        { label: 'Wallet Transactions', icon: 'pi pi-arrow-right-arrow-left', href: '/admin/wallet-transactions' },
        { label: 'Voucher Codes', icon: 'pi pi-ticket', href: '/admin/voucher-codes' },
        { label: 'Revenue', icon: 'pi pi-dollar', href: '/admin/revenue' },
        { label: 'Reports', icon: 'pi pi-chart-bar', href: '/admin/reports' },
        { label: 'Audit Logs', icon: 'pi pi-list', href: '/admin/audit-logs' },
    ],
};

const platformGroup: SidebarNavGroup = {
    title: 'Platform',
    items: [
        { label: 'Platform', icon: 'pi pi-globe', href: '/platform' },
        { label: 'Users', icon: 'pi pi-users', href: '/platform/users' },
        { label: 'Tenants', icon: 'pi pi-building', href: '/platform/tenants' },
        { label: 'Games', icon: 'pi pi-play', href: '/platform/games' },
        { label: 'Revenue', icon: 'pi pi-dollar', href: '/platform/revenue' },
    ],
};

const fantasyGroup: SidebarNavGroup = {
    title: 'Chinga Fantasy',
    items: [
        { label: 'Teams', icon: 'pi pi-th-large', href: '/fantasy/teams' },
        { label: 'Rounds', icon: 'pi pi-chart-bar', href: '/fantasy/rounds' },
        { label: 'Settings', icon: 'pi pi-cog', href: '/fantasy/settings' },
    ],
};

interface AuthProps {
    user: unknown;
    roles?: string[];
    is_platform_admin?: boolean;
    is_tenant_admin?: boolean;
}

interface Props {
    title?: string;
}

export default function UserLayout({ children, title }: PropsWithChildren<Props>) {
    const { auth } = usePage<{ auth: AuthProps }>().props;
    const isPlatformAdmin = auth?.is_platform_admin || false;
    const isTenantAdmin = auth?.is_tenant_admin || false;
    const isAdmin = isPlatformAdmin || isTenantAdmin;

    const navigation = useMemo(() => {
        const groups: SidebarNavGroup[] = [accountGroup];

        if (isAdmin) {
            groups.push(adminGroup);
        }

        if (isPlatformAdmin) {
            groups.push(platformGroup);
            groups.push(fantasyGroup);
        }

        return groups;
    }, [isAdmin, isPlatformAdmin]);

    return (
        <AppLayout title={title} navigation={navigation}>
            {children}
        </AppLayout>
    );
}
