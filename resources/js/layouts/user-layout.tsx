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

const settingsGroup: SidebarNavGroup = {
    title: 'Settings',
    items: [
        { label: 'Profile', icon: 'pi pi-user', href: '/settings/profile' },
        { label: 'Password', icon: 'pi pi-key', href: '/settings/password' },
        { label: 'Two-Factor Auth', icon: 'pi pi-lock', href: '/settings/two-factor' },
        { label: 'Sessions', icon: 'pi pi-desktop', href: '/settings/sessions' },
        { label: 'Security Log', icon: 'pi pi-shield', href: '/settings/security/log' },
        { label: 'Identity (KYC)', icon: 'pi pi-id-card', href: '/settings/kyc' },
        { label: 'Responsible Gaming', icon: 'pi pi-heart', href: '/settings/responsible-gambling' },
        { label: 'Appearance', icon: 'pi pi-palette', href: '/settings/appearance' },
    ],
};

const adminGroup: SidebarNavGroup = {
    title: 'Administration',
    items: [
        { label: 'Admin Dashboard', icon: 'pi pi-th-large', href: '/admin' },
        { label: 'Users', icon: 'pi pi-users', href: '/admin/users' },
        { label: 'KYC Review', icon: 'pi pi-verified', href: '/admin/kyc' },
        { label: 'Voucher Codes', icon: 'pi pi-ticket', href: '/admin/voucher-codes' },
        { label: 'Reports', icon: 'pi pi-chart-bar', href: '/admin/reports' },
        { label: 'Audit Logs', icon: 'pi pi-list', href: '/admin/audit-logs' },
    ],
};

const platformGroup: SidebarNavGroup = {
    title: 'Platform',
    items: [
        { label: 'Platform', icon: 'pi pi-globe', href: '/platform' },
        { label: 'Tenants', icon: 'pi pi-building', href: '/platform/tenants' },
        { label: 'Games', icon: 'pi pi-play', href: '/platform/games' },
        { label: 'Revenue', icon: 'pi pi-dollar', href: '/platform/revenue' },
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
        const groups: SidebarNavGroup[] = [accountGroup, settingsGroup];

        if (isAdmin) {
            groups.push(adminGroup);
        }

        if (isPlatformAdmin) {
            groups.push(platformGroup);
        }

        return groups;
    }, [isAdmin, isPlatformAdmin]);

    return (
        <AppLayout title={title} navigation={navigation}>
            {children}
        </AppLayout>
    );
}
