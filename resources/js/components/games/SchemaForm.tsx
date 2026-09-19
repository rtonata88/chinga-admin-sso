// Renders a settings form from a game's settings_schema (the JSON Schema
// subset in App\Support\SettingsSchema). Types map to PrimeReact inputs:
//   number | integer -> InputNumber (currency / percent via x-format)
//   boolean          -> InputSwitch, or an Inherit/On/Off dropdown when sparse
//   string + enum    -> Dropdown (labels via x-enum-labels)
//   string           -> InputText
//   array of string  -> Chips
// `sparse` is the per-tenant override mode: an empty value means "inherit".

import { Chips } from 'primereact/chips';
import { Dropdown } from 'primereact/dropdown';
import { InputNumber } from 'primereact/inputnumber';
import { InputSwitch } from 'primereact/inputswitch';
import { InputText } from 'primereact/inputtext';

import { FieldLabel } from './panels';

export type SchemaPropertyType = 'number' | 'integer' | 'boolean' | 'string' | 'array';

export interface SchemaProperty {
    type: SchemaPropertyType;
    title?: string;
    description?: string;
    minimum?: number;
    maximum?: number;
    multipleOf?: number;
    enum?: string[];
    default?: unknown;
    items?: { type: 'string'; enum?: string[] };
    'x-group'?: 'game' | 'commercial';
    'x-tenant-overridable'?: boolean;
    'x-format'?: 'currency' | 'percent';
    'x-enum-labels'?: Record<string, string>;
}

export interface SettingsSchema {
    type?: 'object';
    required?: string[];
    properties: Record<string, SchemaProperty>;
}

export type SettingsValues = Record<string, unknown>;

export function schemaKeys(schema: SettingsSchema): string[] {
    return Object.keys(schema.properties ?? {});
}

export function keysInGroup(schema: SettingsSchema, group: 'game' | 'commercial'): string[] {
    return schemaKeys(schema).filter((k) => (schema.properties[k]['x-group'] ?? 'game') === group);
}

export function overridableKeys(schema: SettingsSchema): string[] {
    return schemaKeys(schema).filter((k) => schema.properties[k]['x-tenant-overridable'] !== false);
}

export function schemaDefaults(schema: SettingsSchema): SettingsValues {
    const out: SettingsValues = {};
    for (const [k, p] of Object.entries(schema.properties ?? {})) {
        if (p.default !== undefined) out[k] = p.default;
    }
    return out;
}

function humanise(key: string): string {
    return key.replace(/_/g, ' ').replace(/^\w/, (c) => c.toUpperCase());
}

const INHERIT_OPTIONS = [
    { label: 'Inherit global', value: 'inherit' },
    { label: 'Enabled', value: 'true' },
    { label: 'Disabled', value: 'false' },
];

export function SchemaForm({
    schema,
    keys,
    values,
    onChange,
    prefix,
    sparse = false,
}: {
    schema: SettingsSchema;
    keys: string[];
    values: SettingsValues;
    onChange: (key: string, value: unknown) => void;
    prefix?: string;
    sparse?: boolean;
}) {
    const fieldId = (key: string) => (prefix ? `${prefix}-${key}` : key);

    return (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 18 }}>
            {keys.map((key) => {
                const p = schema.properties[key];
                if (!p) return null;
                const label = p.title ?? humanise(key);
                const value = values[key];
                const id = fieldId(key);

                let control: React.ReactNode;
                switch (p.type) {
                    case 'number':
                    case 'integer': {
                        const isInt = p.type === 'integer';
                        const numberProps =
                            p['x-format'] === 'currency'
                                ? { mode: 'currency' as const, currency: 'NAD', locale: 'en-ZA', minFractionDigits: 2 }
                                : p['x-format'] === 'percent'
                                  ? { suffix: '%', minFractionDigits: isInt ? 0 : 2 }
                                  : { minFractionDigits: isInt ? 0 : 2, maxFractionDigits: isInt ? 0 : 6 };
                        control = (
                            <InputNumber
                                inputId={id}
                                value={typeof value === 'number' ? value : null}
                                onValueChange={(e) => onChange(key, e.value ?? null)}
                                min={p.minimum}
                                max={p.maximum}
                                step={p.multipleOf}
                                useGrouping={false}
                                className="w-full"
                                {...numberProps}
                            />
                        );
                        break;
                    }
                    case 'boolean':
                        control = sparse ? (
                            <Dropdown
                                inputId={id}
                                value={value === true ? 'true' : value === false ? 'false' : 'inherit'}
                                options={INHERIT_OPTIONS}
                                onChange={(e) => onChange(key, e.value === 'inherit' ? null : e.value === 'true')}
                                className="w-full"
                            />
                        ) : (
                            <div style={{ display: 'flex', alignItems: 'center', gap: 10, paddingTop: 4 }}>
                                <InputSwitch
                                    inputId={id}
                                    checked={value === true}
                                    onChange={(e) => onChange(key, e.value ?? false)}
                                />
                                <span style={{ fontSize: 12, color: 'var(--cg-fg-2)' }}>
                                    {value === true ? 'Enabled' : 'Disabled'}
                                </span>
                            </div>
                        );
                        break;
                    case 'string':
                        if (p.enum) {
                            const options = [
                                ...(sparse ? [{ label: 'Inherit global', value: '' }] : []),
                                ...p.enum.map((v) => ({ label: p['x-enum-labels']?.[v] ?? v, value: v })),
                            ];
                            control = (
                                <Dropdown
                                    inputId={id}
                                    value={typeof value === 'string' ? value : ''}
                                    options={options}
                                    onChange={(e) => onChange(key, e.value === '' ? null : e.value)}
                                    className="w-full"
                                />
                            );
                        } else {
                            control = (
                                <InputText
                                    id={id}
                                    value={typeof value === 'string' ? value : ''}
                                    onChange={(e) => onChange(key, e.target.value === '' ? null : e.target.value)}
                                    className="w-full"
                                />
                            );
                        }
                        break;
                    case 'array':
                        control = (
                            <Chips
                                inputId={id}
                                value={Array.isArray(value) ? (value as string[]) : []}
                                onChange={(e) => onChange(key, e.value && e.value.length > 0 ? e.value : null)}
                                separator=","
                                allowDuplicate={false}
                                className="w-full"
                            />
                        );
                        break;
                }

                return (
                    <div key={key}>
                        <FieldLabel hint={p.description}>{label}</FieldLabel>
                        {control}
                    </div>
                );
            })}
        </div>
    );
}
