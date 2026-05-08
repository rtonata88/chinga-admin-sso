// resources/js/layouts/operator/operator-console-layout.tsx
//
// Layout shell for the Chinga Games Operator Console.
// Built per design_handoff_operator_console/README.md — a 248px brass-on-ink
// sidebar plus a 64px topbar, wrapping every operator-console screen.
//
// All visual styling lives in resources/css/operator-console.css and the
// design tokens in resources/css/colors_and_type.css. This component is
// pure structure: brand mark, nav groups, footer, topbar (breadcrumb +
// search + icon buttons), and a slot for the page body.

import { Link, usePage } from '@inertiajs/react';
import {
    Bell,
    Clock,
    Gauge,
    LineChart,
    Receipt,
    ShieldCheck,
    UserSquare2,
    Users,
    Search,
    BarChart3,
    Coins,
    CheckCircle2,
} from 'lucide-react';
import { type ReactNode } from 'react';

interface BreadcrumbCrumb {
    label: string;
    href?: string;
}

interface OperatorConsoleLayoutProps {
    children: ReactNode;
    breadcrumbs?: BreadcrumbCrumb[];
}

interface NavLink {
    label: string;
    href: string;
    icon: typeof Gauge;
    badge?: number;
}

interface NavGroup {
    label: string;
    items: NavLink[];
}

const navGroups: NavGroup[] = [
    {
        label: 'Trading',
        items: [
            { label: 'Dashboard', href: '/operator', icon: Gauge },
            { label: 'Live wagers', href: '/operator/wagers', icon: LineChart, badge: 14 },
            { label: 'Events & markets', href: '/operator/events', icon: Clock },
            { label: 'Voucher queue', href: '/operator/vouchers', icon: Receipt },
        ],
    },
    {
        label: 'Customer',
        items: [
            { label: 'Players', href: '/operator/players', icon: Users },
            { label: 'KYC review', href: '/operator/kyc', icon: UserSquare2 },
            { label: 'AML alerts', href: '/operator/aml', icon: ShieldCheck, badge: 3 },
        ],
    },
    {
        label: 'Finance',
        items: [
            { label: 'Reports', href: '/operator/reports', icon: BarChart3 },
            { label: 'Payouts', href: '/operator/payouts', icon: Coins },
            { label: 'Settlements', href: '/operator/settlements', icon: CheckCircle2 },
        ],
    },
];

function isActive(currentPath: string, href: string): boolean {
    // Exact match for the dashboard root, prefix match otherwise so
    // /operator/wagers?status=flagged still highlights "Live wagers".
    if (href === '/operator') return currentPath === '/operator';
    return currentPath === href || currentPath.startsWith(href + '/');
}

export default function OperatorConsoleLayout({
    children,
    breadcrumbs = [],
}: OperatorConsoleLayoutProps) {
    const { url, props } = usePage<{ auth?: { user?: { name?: string; role?: string; initials?: string } } }>();
    const currentPath = url.split('?')[0];
    const me = props.auth?.user;
    const meName = me?.name ?? 'Operator';
    const meRole = me?.role ?? 'Trading · L1';
    const meInitials = me?.initials ?? meName.slice(0, 2).toUpperCase();

    return (
        <div className="cgo-app">
            <aside className="cgo-rail">
                <div className="cgo-brand">
                    <div className="cgo-brand-mark">C</div>
                    <div>
                        <div className="cgo-brand-name">Chinga Games</div>
                        <div className="cgo-brand-sub">Operator Console</div>
                    </div>
                </div>

                {navGroups.map((group) => (
                    <div className="cgo-nav-group" key={group.label}>
                        <div className="cgo-nav-label">{group.label}</div>
                        {group.items.map((item) => {
                            const Icon = item.icon;
                            const active = isActive(currentPath, item.href);
                            return (
                                <Link
                                    key={item.href}
                                    href={item.href}
                                    className={`cgo-nav-item${active ? ' active' : ''}`}
                                >
                                    <Icon className="cgo-ico" strokeWidth={1.5} />
                                    {item.label}
                                    {item.badge ? (
                                        <span className="cgo-badge">{item.badge}</span>
                                    ) : null}
                                </Link>
                            );
                        })}
                    </div>
                ))}

                <div className="cgo-rail-foot">
                    <div className="cgo-avatar">{meInitials}</div>
                    <div>
                        <div className="cgo-me-name">{meName}</div>
                        <div className="cgo-me-role">{meRole}</div>
                    </div>
                </div>
            </aside>

            <div className="cgo-main">
                <header className="cgo-topbar">
                    <div className="cgo-crumb">
                        {breadcrumbs.length === 0 ? (
                            <b>Operator Console</b>
                        ) : (
                            breadcrumbs.map((crumb, i) => {
                                const isLast = i === breadcrumbs.length - 1;
                                const sep = i > 0 ? <span className="cgo-crumb-sep">/</span> : null;
                                const node = isLast ? <b>{crumb.label}</b> : <span>{crumb.label}</span>;
                                return (
                                    <span key={`${crumb.label}-${i}`}>
                                        {sep}
                                        {crumb.href && !isLast ? <Link href={crumb.href}>{node}</Link> : node}
                                    </span>
                                );
                            })
                        )}
                    </div>

                    <div className="cgo-search">
                        <Search size={14} strokeWidth={1.5} />
                        <input placeholder="Search wagers, players, events…" />
                        <span className="cgo-kbd">⌘K</span>
                    </div>

                    <div className="cgo-top-icons">
                        <button type="button" className="cgo-icon-btn" aria-label="Notifications">
                            <Bell size={14} strokeWidth={1.5} />
                            <span className="cgo-dot" />
                        </button>
                        <button type="button" className="cgo-icon-btn" aria-label="Settings">
                            <Clock size={14} strokeWidth={1.5} />
                        </button>
                    </div>
                </header>

                {children}
            </div>
        </div>
    );
}
