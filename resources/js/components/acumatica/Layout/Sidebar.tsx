import { Link, usePage } from '@inertiajs/react';
import { useState } from 'react';

export interface SidebarNavItem {
    label: string;
    icon: string;
    href: string;
}

export interface SidebarNavGroup {
    title: string;
    items: SidebarNavItem[];
}

interface Props {
    collapsed: boolean;
    mobileOpen: boolean;
    onCloseMobile: () => void;
    navigation: SidebarNavGroup[];
    footerLink?: { label: string; icon: string; href: string };
}

export default function Sidebar({ collapsed, mobileOpen, onCloseMobile, navigation, footerLink }: Props) {
    const footer = footerLink;
    const { url } = usePage();

    const initialExpanded = (): string => {
        for (const group of navigation) {
            const hasActive = group.items.some(
                (item) => url === item.href || url.startsWith(item.href + '/'),
            );
            if (hasActive) return group.title;
        }
        return navigation[0]?.title ?? '';
    };

    const [expandedGroup, setExpandedGroup] = useState<string>(initialExpanded);

    const toggleGroup = (title: string) => {
        setExpandedGroup((prev) => (prev === title ? '' : title));
    };

    const isActive = (href: string) => {
        if (href === '/admin' || href === '/dashboard') return url === href;
        return url === href || url.startsWith(href + '/');
    };

    return (
        <aside
            className={`
                acu-sidebar flex-shrink-0 flex flex-col h-full z-50
                ${collapsed ? 'collapsed' : ''}
                ${mobileOpen ? 'fixed inset-y-0 left-0' : 'hidden lg:flex'}
            `}
        >
            {/* Logo */}
            <div className="flex items-center h-14 px-5 border-b" style={{ borderColor: 'var(--acu-border)' }}>
                <div
                    className="w-7 h-7 rounded-lg flex items-center justify-center"
                    style={{
                        background: 'linear-gradient(135deg, #C9A84C, #E8C468)',
                        boxShadow: '0 2px 8px rgba(201, 168, 76, 0.3)',
                    }}
                >
                    <i className="pi pi-bolt text-xs" style={{ color: '#0D1117' }} />
                </div>
                {!collapsed && (
                    <span
                        className="ml-3 text-sm font-bold tracking-wide truncate"
                        style={{ fontFamily: 'var(--font-display)', color: 'var(--acu-text)' }}
                    >
                        CHINGA GAMES
                    </span>
                )}
            </div>

            {/* Navigation */}
            <nav className="flex-1 overflow-y-auto py-3 px-1">
                {navigation.map((group) => {
                    const isExpanded = expandedGroup === group.title;

                    return (
                        <div key={group.title} className="mb-2">
                            {!collapsed ? (
                                <button
                                    type="button"
                                    onClick={() => toggleGroup(group.title)}
                                    className="flex w-full items-center justify-between px-4 py-2 text-[10px] font-semibold uppercase tracking-[0.12em] transition-colors cursor-pointer"
                                    style={{
                                        fontFamily: 'var(--font-body)',
                                        color: 'var(--acu-text-light)',
                                    }}
                                >
                                    <span>{group.title}</span>
                                    <i
                                        className={`pi pi-chevron-down text-[9px] transition-transform duration-200 ${isExpanded ? '' : '-rotate-90'}`}
                                    />
                                </button>
                            ) : (
                                <div className="my-2 mx-3 border-t" style={{ borderColor: 'var(--acu-border)' }} />
                            )}
                            <div
                                className={`overflow-hidden transition-all duration-200 ${
                                    collapsed || isExpanded ? 'max-h-[500px] opacity-100' : 'max-h-0 opacity-0'
                                }`}
                            >
                                {group.items.map((item) => (
                                    <Link
                                        key={item.href}
                                        href={item.href}
                                        className={`acu-sidebar-link ${isActive(item.href) ? 'active' : ''}`}
                                        onClick={onCloseMobile}
                                    >
                                        <i className={`${item.icon} text-sm`} />
                                        {!collapsed && <span className="truncate">{item.label}</span>}
                                    </Link>
                                ))}
                            </div>
                        </div>
                    );
                })}
            </nav>

            {/* Footer link */}
            {footer && (
                <div className="border-t" style={{ borderColor: 'var(--acu-border)' }}>
                    <Link
                        href={footer.href}
                        className="acu-sidebar-link"
                        onClick={onCloseMobile}
                    >
                        <i className={`${footer.icon} text-sm`} />
                        {!collapsed && <span className="truncate">{footer.label}</span>}
                    </Link>
                </div>
            )}

            {/* Version */}
            <div
                className="p-3 text-center"
                style={{
                    fontFamily: 'var(--font-body)',
                    fontSize: '0.65rem',
                    color: 'var(--acu-text-light)',
                    borderTop: '1px solid var(--acu-border)',
                    letterSpacing: '0.08em',
                }}
            >
                {!collapsed && 'v1.0'}
            </div>
        </aside>
    );
}
