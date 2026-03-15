import { type ReactNode } from 'react';

interface Props {
    title: string;
    subtitle?: string;
    children?: ReactNode;
}

export default function PageHeader({ title, subtitle, children }: Props) {
    return (
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
            <div>
                <h1 className="text-2xl font-bold text-[var(--acu-text)]">{title}</h1>
                {subtitle && (
                    <p className="mt-1 text-sm text-[var(--acu-text-muted)]">{subtitle}</p>
                )}
            </div>
            {children && <div className="flex items-center gap-2">{children}</div>}
        </div>
    );
}
