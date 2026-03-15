import { useState, useMemo, type ReactNode } from 'react';
import type { FieldsetConfig } from '@/types/acumatica';
import FieldRenderer from './FieldRenderer';

interface Props {
    config: FieldsetConfig;
    data: Record<string, any>;
    errors?: Record<string, string>;
    onChange: (field: string, value: any) => void;
    configMode?: boolean;
    footer?: ReactNode;
}

export default function ConfigurableFieldset({
    config,
    data,
    errors = {},
    onChange,
    configMode = false,
    footer,
}: Props) {
    const [collapsed, setCollapsed] = useState(config.collapsed ?? false);

    const visibleFields = useMemo(
        () =>
            (config.fields || [])
                .filter((f) => f.visible !== false)
                .sort((a, b) => (a.order ?? 0) - (b.order ?? 0)),
        [config.fields]
    );

    const accentColor = config.color || 'var(--acu-fieldset-blue)';

    return (
        <div className="acu-fieldset" style={{ '--fieldset-color': accentColor } as React.CSSProperties}>
            <div className="acu-fieldset-header" onClick={() => setCollapsed((c) => !c)}>
                <div className="acu-fieldset-title">
                    {config.icon && <i className={config.icon} />}
                    <span>{config.label}</span>
                    <span className="text-xs font-normal text-[var(--acu-text-light)] ml-1">
                        ({visibleFields.length})
                    </span>
                </div>
                <i className={`pi ${collapsed ? 'pi-chevron-right' : 'pi-chevron-down'} text-xs text-[var(--acu-text-muted)]`} />
            </div>

            {!collapsed && (
                <div className="acu-fieldset-body">
                    <div className="acu-field-grid">
                        {visibleFields.map((field) => (
                            <div
                                key={field.name}
                                className={`${configMode ? 'acu-field-wrapper' : ''}`}
                                style={{ gridColumn: `span ${field.span || 6}` }}
                            >
                                <FieldRenderer
                                    field={field}
                                    value={data[field.name]}
                                    error={errors[field.name]}
                                    onChange={(val) => onChange(field.name, val)}
                                />
                            </div>
                        ))}
                    </div>

                    {footer && <div className="mt-4 pt-4 border-t border-[var(--acu-border)]">{footer}</div>}
                </div>
            )}
        </div>
    );
}
