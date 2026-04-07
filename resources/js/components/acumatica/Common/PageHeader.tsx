import { type ReactNode } from 'react';

interface Props {
    title: string;
    subtitle?: string;
    children?: ReactNode;
}

export default function PageHeader({ title, subtitle, children }: Props) {
    return (
        <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-8">
            <div>
                <h1
                    className="text-2xl font-bold"
                    style={{
                        fontFamily: 'var(--font-display)',
                        color: 'var(--acu-text)',
                        letterSpacing: '-0.01em',
                    }}
                >
                    {title}
                </h1>
                {subtitle && (
                    <p
                        className="mt-1.5 text-sm"
                        style={{ color: 'var(--acu-text-light)', fontFamily: 'var(--font-body)' }}
                    >
                        {subtitle}
                    </p>
                )}
            </div>
            {children && <div className="flex items-center gap-2">{children}</div>}
        </div>
    );
}
