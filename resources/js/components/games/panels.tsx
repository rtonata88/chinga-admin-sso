// Shared chrome for game admin pages: brass-on-ink panels and field labels,
// lifted from the former fantasy/settings page so every game's settings
// page looks the same.

export function FieldLabel({ children, hint }: { children: React.ReactNode; hint?: string }) {
    return (
        <div style={{ marginBottom: 4 }}>
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

/** Brass restyle of the PrimeReact inputs used inside .cgo-page. */
export function FormControlStyles() {
    return (
        <style>{`
            .cgo-page .p-inputnumber-input,
            .cgo-page .p-inputtext,
            .cgo-page .p-dropdown,
            .cgo-page .p-chips .p-inputtext {
                background: var(--cg-ink-elevated) !important;
                color: var(--cg-fg-1) !important;
                border: 1px solid var(--cg-rule-strong) !important;
                border-radius: 6px !important;
                font-family: var(--cg-mono) !important;
                font-feature-settings: 'tnum' 1 !important;
            }
            .cgo-page .p-inputnumber-input,
            .cgo-page .p-inputtext {
                padding: 8px 10px !important;
            }
            .cgo-page .p-dropdown .p-dropdown-label {
                color: var(--cg-fg-1) !important;
                font-family: var(--cg-mono) !important;
                padding: 8px 10px !important;
            }
            .cgo-page .p-inputnumber-input:focus,
            .cgo-page .p-inputtext:focus,
            .cgo-page .p-dropdown.p-focus {
                border-color: var(--cg-brass) !important;
                box-shadow: none !important;
                outline: none !important;
            }
            .cgo-page .p-inputnumber-button {
                background: var(--cg-ink-elevated) !important;
                border-color: var(--cg-rule-strong) !important;
                color: var(--cg-fg-2) !important;
            }
            .cgo-page .p-inputnumber-button:hover {
                color: var(--cg-brass-hi) !important;
                border-color: var(--cg-brass) !important;
            }
        `}</style>
    );
}
