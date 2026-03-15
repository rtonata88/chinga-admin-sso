import { Link, usePage } from '@inertiajs/react';

interface Props {
    title?: string;
    sidebarCollapsed: boolean;
    onToggleSidebar: () => void;
    onOpenMobileSidebar: () => void;
}

export default function TopBar({ title, sidebarCollapsed, onToggleSidebar, onOpenMobileSidebar }: Props) {
    const { auth } = usePage().props as any;

    return (
        <header
            className="flex items-center justify-between h-14 px-4 bg-white border-b"
            style={{ borderColor: 'var(--acu-border)' }}
        >
            <div className="flex items-center gap-3">
                <button
                    className="lg:hidden p-2 rounded hover:bg-gray-100"
                    onClick={onOpenMobileSidebar}
                >
                    <i className="pi pi-bars" />
                </button>

                <button
                    className="hidden lg:block p-2 rounded hover:bg-gray-100"
                    onClick={onToggleSidebar}
                >
                    <i className={`pi ${sidebarCollapsed ? 'pi-angle-double-right' : 'pi-angle-double-left'}`} />
                </button>

                {title && <h1 className="text-lg font-semibold text-[var(--acu-text)]">{title}</h1>}
            </div>

            <div className="flex items-center gap-4">
                <button className="relative p-2 rounded hover:bg-gray-100">
                    <i className="pi pi-bell text-[var(--acu-text-muted)]" />
                </button>

                {auth?.user && (
                    <div className="flex items-center gap-2 text-sm">
                        <div className="w-8 h-8 rounded-full bg-blue-100 flex items-center justify-center text-blue-700 font-semibold">
                            {auth.user.name?.charAt(0).toUpperCase()}
                        </div>
                        <span className="hidden sm:inline text-[var(--acu-text)]">{auth.user.name}</span>
                    </div>
                )}
            </div>
        </header>
    );
}
