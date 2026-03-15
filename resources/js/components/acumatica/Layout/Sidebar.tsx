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

    // Auto-expand the group that contains the active link, or the first group
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
            {/* Logo / App name */}
            <div className="flex items-center h-14 px-4 border-b border-white/10">
                <i className="pi pi-th-large text-blue-400 text-lg" />
                {!collapsed && (
                    <span className="ml-3 text-white font-semibold text-sm truncate">
                        Chinga Games SSO
                    </span>
                )}
            </div>

            {/* Navigation */}
            <nav className="flex-1 overflow-y-auto py-2">
                {navigation.map((group) => {
                    const isExpanded = expandedGroup === group.title;

                    return (
                        <div key={group.title} className="mb-1">
                            {!collapsed ? (
                                <button
                                    type="button"
                                    onClick={() => toggleGroup(group.title)}
                                    className="flex w-full items-center justify-between px-4 py-2 text-[11px] font-semibold uppercase tracking-wider text-slate-500 hover:text-slate-300 transition-colors"
                                >
                                    <span>{group.title}</span>
                                    <i
                                        className={`pi pi-chevron-down text-[10px] transition-transform duration-200 ${isExpanded ? '' : '-rotate-90'}`}
                                    />
                                </button>
                            ) : (
                                <div className="my-1 mx-3 border-t border-white/10" />
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
                                        <i className={`${item.icon} text-base`} />
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
                <div className="border-t border-white/10">
                    <Link
                        href={footer.href}
                        className="acu-sidebar-link"
                        onClick={onCloseMobile}
                    >
                        <i className={`${footer.icon} text-base`} />
                        {!collapsed && <span className="truncate">{footer.label}</span>}
                    </Link>
                </div>
            )}

            {/* Footer */}
            <div className="border-t border-white/10 p-3 text-xs text-slate-500 text-center">
                {!collapsed && 'v1.0'}
            </div>
        </aside>
    );
}
