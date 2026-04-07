import { router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

interface Props {
    title?: string;
    sidebarCollapsed: boolean;
    onToggleSidebar: () => void;
    onOpenMobileSidebar: () => void;
}

export default function TopBar({ title, sidebarCollapsed, onToggleSidebar, onOpenMobileSidebar }: Props) {
    const { auth } = usePage().props as any;
    const [menuOpen, setMenuOpen] = useState(false);
    const menuRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        const handleClickOutside = (e: MouseEvent) => {
            if (menuRef.current && !menuRef.current.contains(e.target as Node)) {
                setMenuOpen(false);
            }
        };
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    const handleLogout = () => {
        router.post('/logout');
    };

    return (
        <header
            className="flex items-center justify-between h-14 px-5"
            style={{
                background: 'var(--acu-surface-card)',
                borderBottom: '1px solid var(--acu-border)',
            }}
        >
            <div className="flex items-center gap-3">
                <button
                    className="lg:hidden p-2 rounded-lg transition-colors"
                    style={{ color: 'var(--acu-text-muted)' }}
                    onClick={onOpenMobileSidebar}
                    onMouseEnter={(e) => (e.currentTarget.style.background = 'var(--acu-surface-hover)')}
                    onMouseLeave={(e) => (e.currentTarget.style.background = 'transparent')}
                >
                    <i className="pi pi-bars" />
                </button>

                <button
                    className="hidden lg:block p-2 rounded-lg transition-colors"
                    style={{ color: 'var(--acu-text-muted)' }}
                    onClick={onToggleSidebar}
                    onMouseEnter={(e) => (e.currentTarget.style.background = 'var(--acu-surface-hover)')}
                    onMouseLeave={(e) => (e.currentTarget.style.background = 'transparent')}
                >
                    <i className={`pi ${sidebarCollapsed ? 'pi-angle-double-right' : 'pi-angle-double-left'} text-sm`} />
                </button>

                {title && (
                    <h1
                        className="text-base font-semibold"
                        style={{
                            fontFamily: 'var(--font-display)',
                            color: 'var(--acu-text)',
                            letterSpacing: '0.01em',
                        }}
                    >
                        {title}
                    </h1>
                )}
            </div>

            <div className="flex items-center gap-2">
                <button
                    className="relative p-2 rounded-lg transition-colors"
                    style={{ color: 'var(--acu-text-muted)' }}
                    onMouseEnter={(e) => (e.currentTarget.style.background = 'var(--acu-surface-hover)')}
                    onMouseLeave={(e) => (e.currentTarget.style.background = 'transparent')}
                >
                    <i className="pi pi-bell text-sm" />
                </button>

                {auth?.user && (
                    <div className="relative" ref={menuRef}>
                        <button
                            className="flex items-center gap-2.5 text-sm py-1.5 px-2 rounded-lg cursor-pointer transition-colors"
                            onClick={() => setMenuOpen((o) => !o)}
                            style={{ fontFamily: 'var(--font-body)' }}
                            onMouseEnter={(e) => (e.currentTarget.style.background = 'var(--acu-surface-hover)')}
                            onMouseLeave={(e) => (e.currentTarget.style.background = 'transparent')}
                        >
                            <div
                                className="w-8 h-8 rounded-lg flex items-center justify-center text-xs font-bold"
                                style={{
                                    background: 'linear-gradient(135deg, rgba(201, 168, 76, 0.2), rgba(201, 168, 76, 0.05))',
                                    color: 'var(--acu-primary)',
                                    border: '1px solid rgba(201, 168, 76, 0.2)',
                                }}
                            >
                                {auth.user.name?.charAt(0).toUpperCase()}
                            </div>
                            <span className="hidden sm:inline" style={{ color: 'var(--acu-text)' }}>
                                {auth.user.name}
                            </span>
                            <i
                                className={`pi pi-angle-${menuOpen ? 'up' : 'down'} text-xs`}
                                style={{ color: 'var(--acu-text-light)' }}
                            />
                        </button>

                        {menuOpen && (
                            <div
                                className="absolute right-0 top-full mt-2 w-60 rounded-xl py-1 z-50"
                                style={{
                                    background: 'var(--acu-surface-elevated)',
                                    border: '1px solid var(--acu-border)',
                                    boxShadow: '0 16px 48px rgba(0, 0, 0, 0.15)',
                                    fontFamily: 'var(--font-body)',
                                }}
                            >
                                <div className="px-4 py-3" style={{ borderBottom: '1px solid var(--acu-border)' }}>
                                    <div className="text-sm font-semibold truncate" style={{ color: 'var(--acu-text)' }}>
                                        {auth.user.name}
                                    </div>
                                    <div className="text-xs truncate mt-0.5" style={{ color: 'var(--acu-text-light)' }}>
                                        {auth.user.email}
                                    </div>
                                </div>
                                <div className="py-1">
                                    {[
                                        { href: '/settings/profile', icon: 'pi-user', label: 'Profile' },
                                        { href: '/settings/password', icon: 'pi-key', label: 'Password' },
                                        { href: '/settings/two-factor', icon: 'pi-lock', label: 'Two-Factor Auth' },
                                        { href: '/settings/sessions', icon: 'pi-desktop', label: 'Sessions' },
                                        { href: '/settings/security/log', icon: 'pi-shield', label: 'Security Log' },
                                        { href: '/settings/appearance', icon: 'pi-palette', label: 'Appearance' },
                                    ].map((item) => (
                                        <a
                                            key={item.href}
                                            href={item.href}
                                            className="flex items-center gap-3 px-4 py-2 text-sm transition-colors"
                                            style={{ color: 'var(--acu-text-muted)' }}
                                            onMouseEnter={(e) => {
                                                e.currentTarget.style.background = 'var(--acu-surface-hover)';
                                                e.currentTarget.style.color = 'var(--acu-text)';
                                            }}
                                            onMouseLeave={(e) => {
                                                e.currentTarget.style.background = 'transparent';
                                                e.currentTarget.style.color = 'var(--acu-text-muted)';
                                            }}
                                        >
                                            <i className={`pi ${item.icon} text-xs`} />
                                            {item.label}
                                        </a>
                                    ))}
                                </div>
                                <div style={{ borderTop: '1px solid var(--acu-border)' }}>
                                    <button
                                        onClick={handleLogout}
                                        className="w-full flex items-center gap-3 px-4 py-2.5 text-sm cursor-pointer transition-colors"
                                        style={{ color: 'var(--acu-danger)' }}
                                        onMouseEnter={(e) => (e.currentTarget.style.background = 'rgba(248, 81, 73, 0.06)')}
                                        onMouseLeave={(e) => (e.currentTarget.style.background = 'transparent')}
                                    >
                                        <i className="pi pi-sign-out text-xs" />
                                        Log out
                                    </button>
                                </div>
                            </div>
                        )}
                    </div>
                )}
            </div>
        </header>
    );
}
