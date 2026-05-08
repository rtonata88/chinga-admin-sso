// resources/js/layouts/admin/layout.tsx
//
// Re-routed to the brass-on-ink Operator Console shell — same retheme
// path as user-layout.tsx, only without the FantasyHealthBanner since
// not every admin route in this layout cares about it.

import OperatorConsoleLayout from '@/layouts/operator/operator-console-layout';
import { buildSystemNav } from '@/layouts/operator/system-nav';
import { usePage } from '@inertiajs/react';
import { type PropsWithChildren, useMemo } from 'react';

interface AuthProps {
    user: unknown;
    roles?: string[];
    is_platform_admin?: boolean;
    is_tenant_admin?: boolean;
}

interface Props {
    title?: string;
}

export default function AdminLayout({ children, title: _title }: PropsWithChildren<Props>) {
    const { auth } = usePage<{ auth: AuthProps }>().props;
    const isPlatformAdmin = !!auth?.is_platform_admin;
    const isTenantAdmin = !!auth?.is_tenant_admin;

    const navGroups = useMemo(
        () => buildSystemNav({ isTenantAdmin, isPlatformAdmin }),
        [isTenantAdmin, isPlatformAdmin],
    );

    return (
        <OperatorConsoleLayout navGroups={navGroups} brandSubtitle="Admin Console">
            {children}
        </OperatorConsoleLayout>
    );
}
