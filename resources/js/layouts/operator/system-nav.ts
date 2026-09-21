// resources/js/layouts/operator/system-nav.ts
//
// Role-aware nav config for the brass admin shell. The
// OperatorConsoleLayout takes a `navGroups` prop; this is what
// UserLayout / app-layout pass in.
//
// IMPORTANT: groups + items mirror what UserLayout used pre-retheme,
// 1:1. Don't add new menu items here without explicit sign-off — the
// retheme is purely visual; nav structure is owned elsewhere.
//
// Game groups are the exception: one group per game in the shared
// `games` prop (the enabled catalogue), for platform admins. Every game
// gets its schema-driven Settings page; game-specific pages are listed
// per slug in GAME_EXTRAS. Tenant admins get no game group, matching the
// pre-P7 behaviour (the /fantasy/* routes are platform-admin only).

import {
    Activity,
    BarChart3,
    Building2,
    Coins,
    Cog,
    DollarSign,
    Gamepad2,
    Gauge,
    History,
    Landmark,
    LayoutGrid,
    LineChart,
    ListChecks,
    MapPin,
    Percent,
    Ticket,
    Trophy,
    Users,
    Wallet,
} from 'lucide-react';

import type { NavGroup, NavLink } from './operator-console-layout';

export interface NavGame {
    uuid: string;
    name: string;
    slug: string;
    status?: string;
    launch_url?: string | null;
}

interface BuildSystemNavInput {
    isTenantAdmin?: boolean;
    isPlatformAdmin?: boolean;
    games?: NavGame[];
}

/** Game-specific admin pages, by slug. */
const GAME_EXTRAS: Record<string, NavLink[]> = {
    'chinga-fantasy': [
        { label: 'Teams', href: '/fantasy/teams', icon: Trophy },
        { label: 'Rounds', href: '/fantasy/rounds', icon: LineChart },
    ],
    // Vrrr Pha consoles (M5): round history with the seed audit, live exposure, realised RTP.
    'vrrr-pha': [
        { label: 'Rounds', href: '/vrrr-pha/rounds', icon: LineChart },
        { label: 'Exposure', href: '/vrrr-pha/exposure', icon: Activity },
        { label: 'RTP', href: '/vrrr-pha/rtp', icon: Percent },
    ],
};

export function gameNavGroup(game: NavGame): NavGroup {
    return {
        label: game.name,
        items: [
            ...(GAME_EXTRAS[game.slug] ?? []),
            { label: 'Settings', href: `/platform/games/${game.uuid}/settings`, icon: Cog },
        ],
    };
}

export function buildSystemNav({ isTenantAdmin, isPlatformAdmin, games = [] }: BuildSystemNavInput): NavGroup[] {
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
        );
        if (isPlatformAdmin) {
            // Player liability vs bank balance: what the business may take out.
            adminItems.push({ label: 'Treasury', href: '/platform/treasury', icon: Landmark });
        }
        adminItems.push(
            { label: 'Reports', href: '/admin/reports', icon: BarChart3 },
            { label: 'Audit Logs', href: '/admin/audit-logs', icon: ListChecks },
        );
        groups.push({ label: 'Administration', items: adminItems });
    }

    if (isPlatformAdmin) {
        for (const game of games) {
            groups.push(gameNavGroup(game));
        }
    }

    return groups;
}
