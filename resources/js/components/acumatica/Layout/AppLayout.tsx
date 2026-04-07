import { useState, type ReactNode } from 'react';
import Sidebar, { type SidebarNavGroup } from './Sidebar';
import TopBar from './TopBar';

interface Props {
    children: ReactNode;
    title?: string;
    navigation: SidebarNavGroup[];
    footerLink?: { label: string; icon: string; href: string };
}

export default function AppLayout({ children, title, navigation, footerLink }: Props) {
    const [sidebarCollapsed, setSidebarCollapsed] = useState(false);
    const [mobileSidebarOpen, setMobileSidebarOpen] = useState(false);

    return (
        <div className="flex h-screen overflow-hidden" style={{ background: 'var(--acu-surface)', fontFamily: 'var(--font-body)' }}>
            {mobileSidebarOpen && (
                <div
                    className="fixed inset-0 z-40 lg:hidden"
                    style={{ background: 'rgba(0, 0, 0, 0.7)', backdropFilter: 'blur(4px)' }}
                    onClick={() => setMobileSidebarOpen(false)}
                />
            )}

            <Sidebar
                collapsed={sidebarCollapsed}
                mobileOpen={mobileSidebarOpen}
                onCloseMobile={() => setMobileSidebarOpen(false)}
                navigation={navigation}
                footerLink={footerLink}
            />

            <div className="flex flex-col flex-1 min-w-0">
                <TopBar
                    title={title}
                    sidebarCollapsed={sidebarCollapsed}
                    onToggleSidebar={() => setSidebarCollapsed((c) => !c)}
                    onOpenMobileSidebar={() => setMobileSidebarOpen(true)}
                />

                <main className="flex-1 overflow-y-auto">
                    <div className="mx-auto max-w-[var(--acu-content-max-width)] px-4 sm:px-6 lg:px-8 py-6 pb-12">
                        {children}
                    </div>
                </main>
            </div>
        </div>
    );
}
