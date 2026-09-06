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
    History,
    LayoutGrid,
    LineChart,
    ListChecks,
    MapPin,
    Ticket,
    Trophy,
    Users,
    Wallet,
} from 'lucide-react';

import type { NavGroup, NavLink } from './operator-console-layout';

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

    // Administration — items flow in order of data dependence: the
    // entities at the top are foundational (tenants, games, users),
    // the ones at the bottom are derived/aggregate views (revenue,
    // reports, audit). Tenant Overview leads off as the dashboard
    // entry point. Tenants + Games are platform-admin only since
    // they're cross-tenant entities.
    if (isTenantAdmin || isPlatformAdmin) {
        const adminItems: NavLink[] = [
            { label: 'Tenant Overview', href: '/tenant-overview', icon: LayoutGrid },
        ];
        if (isPlatformAdmin) {
            adminItems.push(
                { label: 'Tenants', href: '/platform/tenants', icon: Building2 },
                { label: 'Games', href: '/platform/games', icon: Gamepad2 },
            );
        }
        adminItems.push(
            { label: 'Users', href: '/admin/users', icon: Users },
            { label: 'Venues', href: '/admin/venues', icon: MapPin },
            { label: 'Voucher Codes', href: '/admin/voucher-codes', icon: Ticket },
            { label: 'Wallets', href: '/admin/wallets', icon: Wallet },
            { label: 'Wallet Transactions', href: '/admin/wallet-transactions', icon: History },
            { label: 'Withdrawals', href: '/admin/withdrawals', icon: Coins },
            { label: 'Revenue', href: '/admin/revenue', icon: DollarSign },
            { label: 'Reports', href: '/admin/reports', icon: BarChart3 },
            { label: 'Audit Logs', href: '/admin/audit-logs', icon: ListChecks },
        );
        groups.push({ label: 'Administration', items: adminItems });
    }

    if (isPlatformAdmin) {
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
