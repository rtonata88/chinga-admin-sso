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

            /* Field grid: two columns; the control sits at the bottom of its cell so
               a row's inputs line up whatever the length of the hints above them. */
            .cgo-page .cgo-field-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                column-gap: 24px;
                row-gap: 20px;
            }
            @media (max-width: 860px) {
                .cgo-page .cgo-field-grid { grid-template-columns: minmax(0, 1fr); }
            }
            .cgo-page .cgo-field {
                display: flex;
                flex-direction: column;
                min-width: 0;
            }
            .cgo-page .cgo-field .cgo-field-label { flex: 1 1 auto; }
            .cgo-page .cgo-field .p-inputnumber,
            .cgo-page .cgo-field .p-dropdown,
            .cgo-page .cgo-field .p-chips,
            .cgo-page .cgo-field > .p-inputtext {
                width: 100%;
            }
            .cgo-page .cgo-field .p-inputnumber-input { width: 100%; }
            .cgo-page .cgo-switch-row {
                display: flex;
                align-items: center;
                gap: 10px;
                min-height: 38px;
                font-size: 12px;
                color: var(--cg-fg-2);
            }

            /* Switch: ink track, brass when on. The lara theme's knob was sized for a
               light page and sat below the track here. */
            .cgo-page .p-inputswitch {
                width: 40px !important;
                height: 22px !important;
                display: inline-block;
                position: relative;
                flex: none;
                vertical-align: middle;
            }
            .cgo-page .p-inputswitch .p-inputswitch-slider {
                background: var(--cg-ink-elevated) !important;
                border: 1px solid var(--cg-rule-strong) !important;
                border-radius: 11px !important;
                box-shadow: none !important;
                position: absolute;
                inset: 0;
                transition: background var(--cg-dur) var(--cg-ease), border-color var(--cg-dur) var(--cg-ease);
            }
            .cgo-page .p-inputswitch .p-inputswitch-slider::before {
                width: 14px !important;
                height: 14px !important;
                left: 3px !important;
                top: 50% !important;
                margin-top: -7px !important;
                border-radius: 50% !important;
                background: var(--cg-fg-3) !important;
                box-shadow: none !important;
                transform: none !important;
                transition: left var(--cg-dur) var(--cg-ease), background var(--cg-dur) var(--cg-ease);
            }
            .cgo-page .p-inputswitch.p-highlight .p-inputswitch-slider,
            .cgo-page .p-inputswitch.p-inputswitch-checked .p-inputswitch-slider {
                background: var(--cg-brass) !important;
                border-color: var(--cg-brass) !important;
            }
            .cgo-page .p-inputswitch.p-highlight .p-inputswitch-slider::before,
            .cgo-page .p-inputswitch.p-inputswitch-checked .p-inputswitch-slider::before {
                left: 21px !important;
                transform: none !important;
                background: var(--cg-ink) !important;
            }
            .cgo-page .p-inputswitch:not(.p-disabled):hover .p-inputswitch-slider {
                border-color: var(--cg-brass) !important;
            }
            .cgo-page .p-inputswitch.p-focus .p-inputswitch-slider {
                box-shadow: 0 0 0 2px var(--cg-brass-wash) !important;
            }

            /* Chips (list fields such as allowed countries): one input-shaped box,
               mono brass tokens with a clear remove target. */
            .cgo-page .p-chips { display: block; }
            .cgo-page .p-chips .p-chips-multiple-container {
                display: flex !important;
                flex-wrap: wrap;
                align-items: center;
                gap: 6px;
                width: 100%;
                min-height: 38px;
                padding: 5px 8px !important;
                margin: 0 !important;
                list-style: none;
            }
            .cgo-page .p-chips .p-chips-multiple-container.p-focus,
            .cgo-page .p-chips .p-chips-multiple-container:focus-within {
                border-color: var(--cg-brass) !important;
                box-shadow: none !important;
            }
            .cgo-page .p-chips .p-chips-token {
                display: inline-flex !important;
                align-items: center;
                gap: 6px;
                margin: 0 !important;
                padding: 2px 6px 2px 8px !important;
                border-radius: 4px !important;
                background: var(--cg-brass-wash) !important;
                color: var(--cg-brass-hi) !important;
                font-family: var(--cg-mono);
                font-size: 12px;
                letter-spacing: 0.04em;
                line-height: 1.5;
            }
            .cgo-page .p-chips .p-chips-token .p-chips-token-label { margin: 0 !important; }
            .cgo-page .p-chips .p-chips-token .p-chips-token-icon {
                width: 12px;
                height: 12px;
                margin: 0 !important;
                color: var(--cg-brass-hi);
                cursor: pointer;
            }
            .cgo-page .p-chips .p-chips-input-token {
                flex: 1 1 72px;
                padding: 0 !important;
                margin: 0 !important;
            }
            .cgo-page .p-chips .p-chips-input-token input {
                width: 100%;
                padding: 4px 2px !important;
                background: transparent !important;
                border: 0 !important;
                outline: none !important;
                box-shadow: none !important;
                color: var(--cg-fg-1);
                font-family: var(--cg-mono);
                font-size: 13px;
            }
        `}</style>
    );
}
