// resources/js/pages/fantasy/settings.tsx
//
// Fantasy game settings, brass-on-ink to match /tenant-overview.
// Three panels: Game defaults · Commercial defaults · Tenant
// overrides (collapsible per-tenant). Inputs stay on PrimeReact
// (InputNumber, InputSwitch) — restyling them is out of scope; the
// surrounding chrome and layout get the brass treatment.

import UserLayout from '@/layouts/user-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { Button } from 'primereact/button';
import { InputNumber } from 'primereact/inputnumber';
import { InputSwitch } from 'primereact/inputswitch';
import { Toast } from 'primereact/toast';
import { useEffect, useRef, useState } from 'react';

interface GameSettings {
    min_bet_amount?: number;
    max_bet_amount?: number;
    display_teams?: number;
    winning_teams_count?: number;
    round_betting_seconds?: number;
    round_results_seconds?: number;
    round_dialog_seconds?: number;
    min_jackpot_amount?: number;
    max_jackpot_amount?: number;
    jackpot_percentage?: number;
    default_business_model?: 'reseller' | 'direct';
    default_revenue_share_pct?: number;
    default_tax_pct?: number;
    house_edge_target_pct?: number;
}

interface TenantConfig {
    uuid: string;
    name: string;
    slug: string;
    enabled: boolean;
    custom_settings: GameSettings;
}

interface Props {
    game: { uuid: string; name: string; settings: GameSettings };
    tenants: TenantConfig[];
}

function FieldLabel({ children, hint }: { children: React.ReactNode; hint?: string }) {
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
            {hint && (
                <div style={{ fontSize: 11, color: 'var(--cg-fg-3)', marginTop: 2 }}>{hint}</div>
            )}
        </div>
    );
}

function PanelShell({
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

function GameSettingsForm({
    values,
    onChange,
    prefix,
}: {
    values: GameSettings;
    onChange: (key: keyof GameSettings, value: number | null) => void;
    prefix?: string;
}) {
    const fieldId = (key: string) => (prefix ? `${prefix}-${key}` : key);

    return (
        <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 18 }}>
            <div>
                <FieldLabel>Min bet (NAD)</FieldLabel>
                <InputNumber
                    id={fieldId('min_bet')}
                    value={values.min_bet_amount ?? null}
                    onValueChange={(e) => onChange('min_bet_amount', e.value ?? null)}
                    mode="currency" currency="NAD" locale="en-ZA" minFractionDigits={2} min={1}
                    className="w-full"
                />
            </div>
            <div>
                <FieldLabel>Max bet (NAD)</FieldLabel>
                <InputNumber
                    id={fieldId('max_bet')}
                    value={values.max_bet_amount ?? null}
                    onValueChange={(e) => onChange('max_bet_amount', e.value ?? null)}
                    mode="currency" currency="NAD" locale="en-ZA" minFractionDigits={2} min={1}
                    className="w-full"
                />
            </div>
            <div>
                <FieldLabel>Display teams</FieldLabel>
                <InputNumber
                    id={fieldId('display_teams')}
                    value={values.display_teams ?? null}
                    onValueChange={(e) => onChange('display_teams', e.value ?? null)}
                    min={4} max={100}
                    className="w-full"
                />
            </div>
            <div>
                <FieldLabel hint="How many teams are drawn as winners each round">
                    Winning teams count
                </FieldLabel>
                <InputNumber
                    id={fieldId('winning_teams')}
                    value={values.winning_teams_count ?? null}
                    onValueChange={(e) => onChange('winning_teams_count', e.value ?? null)}
                    min={4} max={50}
                    className="w-full"
                />
            </div>
            <div>
                <FieldLabel>Betting phase (seconds)</FieldLabel>
                <InputNumber
                    id={fieldId('betting_seconds')}
                    value={values.round_betting_seconds ?? null}
                    onValueChange={(e) => onChange('round_betting_seconds', e.value ?? null)}
                    min={10} max={300}
                    className="w-full"
                />
            </div>
            <div>
                <FieldLabel>Results phase (seconds)</FieldLabel>
                <InputNumber
                    id={fieldId('results_seconds')}
                    value={values.round_results_seconds ?? null}
                    onValueChange={(e) => onChange('round_results_seconds', e.value ?? null)}
                    min={5} max={120}
                    className="w-full"
                />
            </div>
            <div>
                <FieldLabel>Dialog phase (seconds)</FieldLabel>
                <InputNumber
                    id={fieldId('dialog_seconds')}
                    value={values.round_dialog_seconds ?? null}
                    onValueChange={(e) => onChange('round_dialog_seconds', e.value ?? null)}
                    min={5} max={120}
                    className="w-full"
                />
            </div>
            <div>
                <FieldLabel>Min jackpot (NAD)</FieldLabel>
                <InputNumber
                    id={fieldId('min_jackpot')}
                    value={values.min_jackpot_amount ?? null}
                    onValueChange={(e) => onChange('min_jackpot_amount', e.value ?? null)}
                    mode="currency" currency="NAD" locale="en-ZA" minFractionDigits={2} min={0}
                    className="w-full"
                />
            </div>
            <div>
                <FieldLabel hint="Jackpot stops growing once this cap is reached">
                    Max jackpot (NAD)
                </FieldLabel>
                <InputNumber
                    id={fieldId('max_jackpot')}
                    value={values.max_jackpot_amount ?? null}
                    onValueChange={(e) => onChange('max_jackpot_amount', e.value ?? null)}
                    mode="currency" currency="NAD" locale="en-ZA" minFractionDigits={2} min={0}
                    className="w-full"
                />
            </div>
            <div>
                <FieldLabel hint="Of each losing deposit-funded bet that feeds the jackpot">
                    Jackpot accrual %
                </FieldLabel>
                <InputNumber
                    id={fieldId('jackpot_pct')}
                    value={values.jackpot_percentage ?? null}
                    onValueChange={(e) => onChange('jackpot_percentage', e.value ?? null)}
                    suffix="%" min={0} max={100}
                    className="w-full"
                />
            </div>
        </div>
    );
}

export default function Settings({ game, tenants }: Props) {
    const [globalSettings, setGlobalSettings] = useState<GameSettings>(game.settings || {});
    const [tenantStates, setTenantStates] = useState<Record<string, { enabled: boolean; settings: GameSettings }>>(() => {
        const state: Record<string, { enabled: boolean; settings: GameSettings }> = {};
        for (const t of tenants) {
            state[t.uuid] = { enabled: t.enabled, settings: { ...t.custom_settings } };
        }
        return state;
    });
    const [savingGlobal, setSavingGlobal] = useState(false);
    const [savingTenant, setSavingTenant] = useState<string | null>(null);
    const [expanded, setExpanded] = useState<Record<string, boolean>>({});
    const toast = useRef<Toast>(null);

    const { flash } = usePage<{ flash: { success?: string; error?: string } }>().props;

    useEffect(() => {
        if (flash?.success) toast.current?.show({ severity: 'success', summary: 'Saved', detail: flash.success });
        if (flash?.error) toast.current?.show({ severity: 'error', summary: 'Error', detail: flash.error });
    }, [flash]);

    const handleGlobalChange = (key: keyof GameSettings, value: number | null) => {
        setGlobalSettings((prev) => ({ ...prev, [key]: value ?? undefined }));
    };

    const handleSaveGlobal = () => {
        setSavingGlobal(true);
        router.put('/fantasy/settings/global', globalSettings as unknown as Record<string, string>, {
            onSuccess: () => setSavingGlobal(false),
            onError: () => setSavingGlobal(false),
        });
    };

    const handleTenantSettingChange = (uuid: string, key: keyof GameSettings, value: number | null) => {
        setTenantStates((prev) => ({
            ...prev,
            [uuid]: {
                ...prev[uuid],
                settings: { ...prev[uuid].settings, [key]: value ?? undefined },
            },
        }));
    };

    const handleTenantEnabledChange = (uuid: string, enabled: boolean) => {
        setTenantStates((prev) => ({ ...prev, [uuid]: { ...prev[uuid], enabled } }));
    };

    const handleSaveTenant = (uuid: string) => {
        setSavingTenant(uuid);
        const state = tenantStates[uuid];
        const payload = { enabled: state.enabled, custom_settings: state.settings };
        router.put(`/fantasy/settings/tenant/${uuid}`, payload as unknown as Record<string, string>, {
            onSuccess: () => setSavingTenant(null),
            onError: () => setSavingTenant(null),
        });
    };

    const toggle = (uuid: string) => setExpanded((prev) => ({ ...prev, [uuid]: !prev[uuid] }));

    return (
        <UserLayout title="Fantasy settings">
            <Head title="Fantasy settings · Admin" />
            <Toast ref={toast} />

            <div className="cgo-page">
                {/* Page header */}
                <div className="cgo-page-head">
                    <div>
                        <div className="cgo-eyebrow">Fantasy</div>
                        <h1 className="cgo-title">Settings</h1>
                        <div className="cgo-subtitle">
                            Game-wide defaults for{' '}
                            <strong style={{ color: 'var(--cg-fg-1)' }}>{game.name}</strong>{' '}
                            with per-tenant overrides.
                        </div>
                    </div>
                </div>

                {/* Game Defaults */}
                <PanelShell
                    title="Game defaults"
                    description={
                        <>
                            Bet limits, round phase durations, jackpot accrual. These are the
                            <em> base </em> values; each tenant can override individual fields below.
                        </>
                    }
                    action={
                        <button
                            type="button"
                            className="cg-btn cg-btn--primary cg-btn--sm"
                            onClick={handleSaveGlobal}
                            disabled={savingGlobal}
                        >
                            {savingGlobal ? 'Saving…' : 'Save defaults'}
                        </button>
                    }
                >
                    <GameSettingsForm
                        values={globalSettings}
                        onChange={handleGlobalChange}
                        prefix="global"
                    />
                </PanelShell>

                {/* Commercial defaults */}
                <PanelShell
                    title="Commercial defaults · new tenants"
                    description={
                        <>
                            Applied to <strong>newly-created tenants only</strong>. Existing
                            tenants keep their own values from the tenant detail page. Math:{' '}
                            <code style={{ fontFamily: 'var(--cg-mono)', color: 'var(--cg-fg-2)' }}>
                                NGR = GGR − tax
                            </code>
                            ; for resellers{' '}
                            <code style={{ fontFamily: 'var(--cg-mono)', color: 'var(--cg-fg-2)' }}>
                                tenant_share = NGR × revenue_share_pct
                            </code>
                            ; for direct tenants{' '}
                            <code style={{ fontFamily: 'var(--cg-mono)', color: 'var(--cg-fg-2)' }}>
                                tenant_share = 0
                            </code>
                            .
                        </>
                    }
                    action={
                        <button
                            type="button"
                            className="cg-btn cg-btn--primary cg-btn--sm"
                            onClick={handleSaveGlobal}
                            disabled={savingGlobal}
                        >
                            {savingGlobal ? 'Saving…' : 'Save defaults'}
                        </button>
                    }
                >
                    <div style={{ display: 'grid', gridTemplateColumns: 'repeat(2, 1fr)', gap: 18 }}>
                        <div>
                            <FieldLabel>Default business model</FieldLabel>
                            <select
                                value={globalSettings.default_business_model ?? 'reseller'}
                                onChange={(e) =>
                                    setGlobalSettings((prev) => ({
                                        ...prev,
                                        default_business_model: e.target.value as 'reseller' | 'direct',
                                    }))
                                }
                                style={{
                                    width: '100%',
                                    padding: '8px 10px',
                                    fontSize: 13,
                                    background: 'var(--cg-ink-elevated)',
                                    border: '1px solid var(--cg-rule-strong)',
                                    borderRadius: 6,
                                    color: 'var(--cg-fg-1)',
                                }}
                            >
                                <option value="reseller">Reseller (betting shop, gets revenue share)</option>
                                <option value="direct">Direct (platform-sold, 100% to platform)</option>
                            </select>
                        </div>
                        <div>
                            <FieldLabel hint="Tenant's cut of NGR. e.g. 70% → tenant gets 70%, platform 30%.">
                                Default revenue share %
                            </FieldLabel>
                            <InputNumber
                                value={globalSettings.default_revenue_share_pct ?? null}
                                onValueChange={(e) => handleGlobalChange('default_revenue_share_pct', e.value ?? null)}
                                suffix="%" min={0} max={100} minFractionDigits={2}
                                className="w-full"
                            />
                        </div>
                        <div>
                            <FieldLabel hint="Deducted from GGR before the share split.">
                                Default gambling tax %
                            </FieldLabel>
                            <InputNumber
                                value={globalSettings.default_tax_pct ?? null}
                                onValueChange={(e) => handleGlobalChange('default_tax_pct', e.value ?? null)}
                                suffix="%" min={0} max={100} minFractionDigits={2}
                                className="w-full"
                            />
                        </div>
                        <div>
                            <FieldLabel hint="Informational target. e.g. 10% means we expect to keep 10% of every NAD wagered (RTP = 90%).">
                                House edge target %
                            </FieldLabel>
                            <InputNumber
                                value={globalSettings.house_edge_target_pct ?? null}
                                onValueChange={(e) => handleGlobalChange('house_edge_target_pct', e.value ?? null)}
                                suffix="%" min={0} max={100} minFractionDigits={2}
                                className="w-full"
                            />
                        </div>
                    </div>
                </PanelShell>

                {/* Tenant Overrides */}
                {tenants.length > 0 && (
                    <>
                        <div
                            className="cgo-table-bar"
                            style={{
                                borderRadius: '8px 8px 0 0',
                                borderTop: '1px solid var(--cg-rule)',
                                borderLeft: '1px solid var(--cg-rule)',
                                borderRight: '1px solid var(--cg-rule)',
                                marginTop: 0,
                            }}
                        >
                            <div className="cgo-table-bar-title">
                                Tenant overrides · {tenants.length}
                            </div>
                        </div>
                        <div
                            style={{
                                border: '1px solid var(--cg-rule)',
                                borderTop: 0,
                                borderRadius: '0 0 8px 8px',
                                background: 'var(--cg-ink-card)',
                                marginBottom: 32,
                            }}
                        >
                            {tenants.map((tenant, i) => {
                                const state = tenantStates[tenant.uuid];
                                const isOpen = !!expanded[tenant.uuid];
                                const isLast = i === tenants.length - 1;
                                return (
                                    <div
                                        key={tenant.uuid}
                                        style={{ borderBottom: isLast ? 'none' : '1px solid var(--cg-rule)' }}
                                    >
                                        <button
                                            type="button"
                                            onClick={() => toggle(tenant.uuid)}
                                            style={{
                                                all: 'unset',
                                                display: 'flex',
                                                alignItems: 'center',
                                                width: '100%',
                                                padding: '14px 18px',
                                                cursor: 'pointer',
                                                gap: 12,
                                            }}
                                        >
                                            <span
                                                style={{
                                                    width: 18,
                                                    color: 'var(--cg-fg-3)',
                                                    fontFamily: 'var(--cg-mono)',
                                                }}
                                            >
                                                {isOpen ? '−' : '+'}
                                            </span>
                                            <div style={{ flex: 1 }}>
                                                <div className="cgo-name">{tenant.name}</div>
                                                <div className="cgo-uid">{tenant.slug}</div>
                                            </div>
                                            <span className={`cgo-pill ${state?.enabled ? 'live' : 'void'}`}>
                                                {state?.enabled ? 'enabled' : 'disabled'}
                                            </span>
                                        </button>

                                        {isOpen && state && (
                                            <div
                                                style={{
                                                    padding: '4px 18px 22px',
                                                    background: 'var(--cg-ink-elevated)',
                                                    borderTop: '1px solid var(--cg-rule)',
                                                }}
                                            >
                                                <div
                                                    style={{
                                                        display: 'flex',
                                                        alignItems: 'center',
                                                        justifyContent: 'space-between',
                                                        gap: 12,
                                                        padding: '14px 0',
                                                        marginBottom: 8,
                                                    }}
                                                >
                                                    <div style={{ display: 'flex', alignItems: 'center', gap: 12 }}>
                                                        <InputSwitch
                                                            checked={state.enabled}
                                                            onChange={(e) => handleTenantEnabledChange(tenant.uuid, e.value ?? false)}
                                                        />
                                                        <span style={{ fontSize: 13, fontWeight: 500 }}>
                                                            Game enabled for this tenant
                                                        </span>
                                                    </div>
                                                    <button
                                                        type="button"
                                                        className="cg-btn cg-btn--primary cg-btn--sm"
                                                        onClick={() => handleSaveTenant(tenant.uuid)}
                                                        disabled={savingTenant === tenant.uuid}
                                                    >
                                                        {savingTenant === tenant.uuid ? 'Saving…' : 'Save'}
                                                    </button>
                                                </div>
                                                <div
                                                    style={{
                                                        fontSize: 11,
                                                        color: 'var(--cg-fg-3)',
                                                        marginBottom: 14,
                                                    }}
                                                >
                                                    Leave fields empty to fall back to the global defaults above.
                                                </div>
                                                <GameSettingsForm
                                                    values={state.settings}
                                                    onChange={(key, value) => handleTenantSettingChange(tenant.uuid, key, value)}
                                                    prefix={`tenant-${tenant.uuid}`}
                                                />
                                            </div>
                                        )}
                                    </div>
                                );
                            })}
                        </div>
                    </>
                )}

                {tenants.length === 0 && (
                    <PanelShell title="Tenant overrides">
                        <div style={{ textAlign: 'center', color: 'var(--cg-fg-3)', padding: '24px 0' }}>
                            No tenants assigned to this game yet.
                        </div>
                    </PanelShell>
                )}
            </div>

            {/* Form-control polish: swap PrimeReact's flat white surface for
                the brass-on-ink palette so the inputs blend with the rest of
                the page. Local <style> avoids editing the global CSS. */}
            <style>{`
                .cgo-page .p-inputnumber-input,
                .cgo-page .p-inputtext {
                    background: var(--cg-ink-elevated) !important;
                    color: var(--cg-fg-1) !important;
                    border: 1px solid var(--cg-rule-strong) !important;
                    border-radius: 6px !important;
                    font-family: var(--cg-mono) !important;
                    font-feature-settings: 'tnum' 1 !important;
                    padding: 8px 10px !important;
                }
                .cgo-page .p-inputnumber-input:focus,
                .cgo-page .p-inputtext:focus {
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
        </UserLayout>
    );
}
