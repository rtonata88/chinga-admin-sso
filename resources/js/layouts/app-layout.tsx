// resources/js/layouts/app-layout.tsx
//
// Re-routed to the brass-on-ink Operator Console shell — same nav
// computation as user-layout.tsx, but kept as a separate entry point
// because the existing admin pages import from this path with a
// breadcrumbs prop. We pass breadcrumbs through to OperatorConsoleLayout
// (which renders them in the topbar).

import OperatorConsoleLayout from '@/layouts/operator/operator-console-layout';
import { buildSystemNav, type NavGame } from '@/layouts/operator/system-nav';
import { type BreadcrumbItem } from '@/types';
import { usePage } from '@inertiajs/react';
import { useMemo, type ReactNode } from 'react';

interface AuthProps {
    user: unknown;
    roles?: string[];
    is_platform_admin?: boolean;
    is_tenant_admin?: boolean;
}

interface AppLayoutProps {
    children: ReactNode;
    breadcrumbs?: BreadcrumbItem[];
}

export default function AppLayout({ children, breadcrumbs }: AppLayoutProps) {
    const { auth, games } = usePage<{ auth: AuthProps; games?: NavGame[] }>().props;
    const isPlatformAdmin = !!auth?.is_platform_admin;
    const isTenantAdmin = !!auth?.is_tenant_admin;

    const navGroups = useMemo(
        () => buildSystemNav({ isTenantAdmin, isPlatformAdmin, games: games ?? [] }),
        [isTenantAdmin, isPlatformAdmin, games],
    );

    // BreadcrumbItem in @/types includes optional `href`. Map directly.
    const crumbs = (breadcrumbs ?? []).map((b) => ({ label: b.title, href: b.href }));

    return (
        <OperatorConsoleLayout
            navGroups={navGroups}
            brandSubtitle="Admin Console"
            breadcrumbs={crumbs}
        >
            {children}
        </OperatorConsoleLayout>
    );
}
