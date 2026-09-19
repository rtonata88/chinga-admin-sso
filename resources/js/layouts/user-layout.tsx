// resources/js/layouts/user-layout.tsx
//
// Re-routed to the brass-on-ink Operator Console shell so the whole
// admin/platform/fantasy surface gets the new design language. Nav
// groups are computed from the auth role so tenant admins see the
// Administration group, platform admins additionally see Platform /
// Fantasy / Operator.
//
// The previous Acumatica AppLayout still exists at
// components/acumatica/Layout/AppLayout.tsx but is no longer referenced
// from anywhere — leaving it in place for now in case any one-off
// page imports it directly.

import { GameHealthBanner } from '@/components/games/GameHealthBanner';
import OperatorConsoleLayout from '@/layouts/operator/operator-console-layout';
import { buildSystemNav, type NavGame } from '@/layouts/operator/system-nav';
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

export default function UserLayout({ children, title: _title }: PropsWithChildren<Props>) {
    const { auth, games } = usePage<{ auth: AuthProps; games?: NavGame[] }>().props;
    const isPlatformAdmin = !!auth?.is_platform_admin;
    const isTenantAdmin = !!auth?.is_tenant_admin;

    const navGroups = useMemo(
        () => buildSystemNav({ isTenantAdmin, isPlatformAdmin, games: games ?? [] }),
        [isTenantAdmin, isPlatformAdmin, games],
    );

    return (
        <OperatorConsoleLayout navGroups={navGroups} brandSubtitle="Admin Console">
            {(isTenantAdmin || isPlatformAdmin) && <GameHealthBanner />}
            {children}
        </OperatorConsoleLayout>
    );
}
