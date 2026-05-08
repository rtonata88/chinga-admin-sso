// resources/js/layouts/operator/system-nav.ts
//
// Role-aware nav config for the brass admin shell. The
// OperatorConsoleLayout takes a `navGroups` prop; this is what
// UserLayout / app-layout pass in.
//
// IMPORTANT: groups + items mirror what UserLayout used pre-retheme,
// 1:1. Don't add new menu items here without explicit sign-off — the
// retheme is purely visual; nav structure is owned elsewhere.

import {
    BarChart3,
    Building2,
    Coins,
    Cog,
    DollarSign,
    Gamepad2,
    Gauge,
    Globe,
    History,
    LayoutGrid,
    LineChart,
    ListChecks,
    Ticket,
    Trophy,
    Users,
    Wallet,
} from 'lucide-react';

import type { NavGroup } from './operator-console-layout';

interface BuildSystemNavInput {
    isTenantAdmin?: boolean;
    isPlatformAdmin?: boolean;
}

export function buildSystemNav({ isTenantAdmin, isPlatformAdmin }: BuildSystemNavInput): NavGroup[] {
    const groups: NavGroup[] = [];

    groups.push({
        label: 'Account',
        items: [{ label: 'Dashboard', href: '/dashboard', icon: Gauge }],
    });

    if (isTenantAdmin || isPlatformAdmin) {
        groups.push({
            label: 'Administration',
            items: [
                { label: 'Tenant Overview', href: '/tenant-overview', icon: LayoutGrid },
                { label: 'Users', href: '/admin/users', icon: Users },
                { label: 'Wallets', href: '/admin/wallets', icon: Wallet },
                { label: 'Wallet Transactions', href: '/admin/wallet-transactions', icon: History },
                { label: 'Withdrawals', href: '/admin/withdrawals', icon: Coins },
                { label: 'Voucher Codes', href: '/admin/voucher-codes', icon: Ticket },
                { label: 'Revenue', href: '/admin/revenue', icon: DollarSign },
                { label: 'Reports', href: '/admin/reports', icon: BarChart3 },
                { label: 'Audit Logs', href: '/admin/audit-logs', icon: ListChecks },
            ],
        });
    }

    if (isPlatformAdmin) {
        groups.push({
            label: 'Platform',
            items: [
                { label: 'Platform', href: '/platform', icon: Globe },
                { label: 'Users', href: '/platform/users', icon: Users },
                { label: 'Tenants', href: '/platform/tenants', icon: Building2 },
                { label: 'Games', href: '/platform/games', icon: Gamepad2 },
                { label: 'Revenue', href: '/platform/revenue', icon: DollarSign },
            ],
        });

        groups.push({
            label: 'Chinga Fantasy',
            items: [
                { label: 'Teams', href: '/fantasy/teams', icon: Trophy },
                { label: 'Rounds', href: '/fantasy/rounds', icon: LineChart },
                { label: 'Settings', href: '/fantasy/settings', icon: Cog },
            ],
        });
    }

    return groups;
}
