// Shared chrome for game admin pages: brass-on-ink panels and field labels,
// lifted from the former fantasy/settings page so every game's settings
// page looks the same.

export function FieldLabel({ children, hint }: { children: React.ReactNode; hint?: string }) {
    return (
        <div className="cgo-field-label" style={{ marginBottom: 6 }}>
            <div
                style={{
                    fontSize: 10,
                    textTransform: 'uppercase',
                    letterSpacing: '0.16em',
                    color: 'var(--cg-fg-3)',
                    fontWeight: 600,
                }}
            >
                {children}
            </div>
            {hint && <div style={{ fontSize: 11, color: 'var(--cg-fg-3)', marginTop: 2 }}>{hint}</div>}
        </div>
    );
}

export function PanelShell({
    title,
    description,
    action,
    children,
}: {
    title: string;
    description?: React.ReactNode;
    action?: React.ReactNode;
    children: React.ReactNode;
}) {
    return (
        <div
            style={{
                border: '1px solid var(--cg-rule)',
                borderRadius: 8,
                overflow: 'hidden',
                background: 'var(--cg-ink-card)',
                marginBottom: 16,
            }}
        >
            <div className="cgo-table-bar">
                <div className="cgo-table-bar-title">{title}</div>
                {action}
            </div>
            <div style={{ padding: 20 }}>
                {description && (
                    <div style={{ fontSize: 12, color: 'var(--cg-fg-3)', marginBottom: 18, lineHeight: 1.55 }}>
                        {description}
                    </div>
                )}
                {children}
            </div>
        </div>
    );
}
