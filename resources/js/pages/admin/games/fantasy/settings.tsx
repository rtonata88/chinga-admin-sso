import PageHeader from '@/components/acumatica/Common/PageHeader';
import UserLayout from '@/layouts/user-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { Accordion, AccordionTab } from 'primereact/accordion';
import { Button } from 'primereact/button';
import { Card } from 'primereact/card';
import { InputNumber } from 'primereact/inputnumber';
import { InputSwitch } from 'primereact/inputswitch';
import { Toast } from 'primereact/toast';
import { useEffect, useRef, useState } from 'react';

interface GameSettings {
    min_bet_amount?: number;
    max_bet_amount?: number;
    display_teams?: number;
    round_betting_seconds?: number;
    round_results_seconds?: number;
    round_dialog_seconds?: number;
    min_jackpot_amount?: number;
    jackpot_percentage?: number;
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

function SettingsForm({
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
        <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div>
                <label className="block text-sm font-medium mb-1" style={{ color: 'var(--acu-text)' }} htmlFor={fieldId('min_bet')}>
                    Min Bet Amount (NAD)
                </label>
                <InputNumber
                    id={fieldId('min_bet')}
                    value={values.min_bet_amount ?? null}
                    onValueChange={(e) => onChange('min_bet_amount', e.value ?? null)}
                    mode="currency"
                    currency="NAD"
                    locale="en-ZA"
                    minFractionDigits={2}
                    min={1}
                    className="w-full"
                />
            </div>
            <div>
                <label className="block text-sm font-medium mb-1" style={{ color: 'var(--acu-text)' }} htmlFor={fieldId('max_bet')}>
                    Max Bet Amount (NAD)
                </label>
                <InputNumber
                    id={fieldId('max_bet')}
                    value={values.max_bet_amount ?? null}
                    onValueChange={(e) => onChange('max_bet_amount', e.value ?? null)}
                    mode="currency"
                    currency="NAD"
                    locale="en-ZA"
                    minFractionDigits={2}
                    min={1}
                    className="w-full"
                />
            </div>
            <div>
                <label className="block text-sm font-medium mb-1" style={{ color: 'var(--acu-text)' }} htmlFor={fieldId('display_teams')}>
                    Display Teams
                </label>
                <InputNumber
                    id={fieldId('display_teams')}
                    value={values.display_teams ?? null}
                    onValueChange={(e) => onChange('display_teams', e.value ?? null)}
                    min={4}
                    max={50}
                    className="w-full"
                />
            </div>
            <div>
                <label className="block text-sm font-medium mb-1" style={{ color: 'var(--acu-text)' }} htmlFor={fieldId('betting_seconds')}>
                    Round Betting (seconds)
                </label>
                <InputNumber
                    id={fieldId('betting_seconds')}
                    value={values.round_betting_seconds ?? null}
                    onValueChange={(e) => onChange('round_betting_seconds', e.value ?? null)}
                    min={10}
                    max={300}
                    className="w-full"
                />
            </div>
            <div>
                <label className="block text-sm font-medium mb-1" style={{ color: 'var(--acu-text)' }} htmlFor={fieldId('results_seconds')}>
                    Round Results (seconds)
                </label>
                <InputNumber
                    id={fieldId('results_seconds')}
                    value={values.round_results_seconds ?? null}
                    onValueChange={(e) => onChange('round_results_seconds', e.value ?? null)}
                    min={5}
                    max={120}
                    className="w-full"
                />
            </div>
            <div>
                <label className="block text-sm font-medium mb-1" style={{ color: 'var(--acu-text)' }} htmlFor={fieldId('dialog_seconds')}>
                    Round Dialog (seconds)
                </label>
                <InputNumber
                    id={fieldId('dialog_seconds')}
                    value={values.round_dialog_seconds ?? null}
                    onValueChange={(e) => onChange('round_dialog_seconds', e.value ?? null)}
                    min={5}
                    max={120}
                    className="w-full"
                />
            </div>
            <div>
                <label className="block text-sm font-medium mb-1" style={{ color: 'var(--acu-text)' }} htmlFor={fieldId('min_jackpot')}>
                    Min Jackpot Amount (NAD)
                </label>
                <InputNumber
                    id={fieldId('min_jackpot')}
                    value={values.min_jackpot_amount ?? null}
                    onValueChange={(e) => onChange('min_jackpot_amount', e.value ?? null)}
                    mode="currency"
                    currency="NAD"
                    locale="en-ZA"
                    minFractionDigits={2}
                    min={0}
                    className="w-full"
                />
            </div>
            <div>
                <label className="block text-sm font-medium mb-1" style={{ color: 'var(--acu-text)' }} htmlFor={fieldId('jackpot_pct')}>
                    Jackpot Percentage (%)
                </label>
                <InputNumber
                    id={fieldId('jackpot_pct')}
                    value={values.jackpot_percentage ?? null}
                    onValueChange={(e) => onChange('jackpot_percentage', e.value ?? null)}
                    suffix="%"
                    min={0}
                    max={100}
                    className="w-full"
                />
            </div>
        </div>
    );
}

export default function Settings({ game, tenants }: Props) {
    const [globalSettings, setGlobalSettings] = useState<GameSettings>(game.settings || {});
    const [tenantStates, setTenantStates] = useState<Record<string, { enabled: boolean; settings: GameSettings }>>(
        () => {
            const state: Record<string, { enabled: boolean; settings: GameSettings }> = {};
            for (const t of tenants) {
                state[t.uuid] = { enabled: t.enabled, settings: { ...t.custom_settings } };
            }
            return state;
        },
    );
    const [savingGlobal, setSavingGlobal] = useState(false);
    const [savingTenant, setSavingTenant] = useState<string | null>(null);
    const toast = useRef<Toast>(null);

    const { flash } = usePage<{ flash: { success?: string; error?: string } }>().props;

    useEffect(() => {
        if (flash?.success) {
            toast.current?.show({ severity: 'success', summary: 'Success', detail: flash.success });
        }
        if (flash?.error) {
            toast.current?.show({ severity: 'error', summary: 'Error', detail: flash.error });
        }
    }, [flash]);

    const handleGlobalChange = (key: keyof GameSettings, value: number | null) => {
        setGlobalSettings((prev) => ({ ...prev, [key]: value ?? undefined }));
    };

    const handleSaveGlobal = () => {
        setSavingGlobal(true);
        router.put('/admin/games/fantasy/settings/global', globalSettings as unknown as Record<string, string>, {
            onSuccess: () => setSavingGlobal(false),
            onError: () => setSavingGlobal(false),
        });
    };

    const handleTenantSettingChange = (tenantUuid: string, key: keyof GameSettings, value: number | null) => {
        setTenantStates((prev) => ({
            ...prev,
            [tenantUuid]: {
                ...prev[tenantUuid],
                settings: { ...prev[tenantUuid].settings, [key]: value ?? undefined },
            },
        }));
    };

    const handleTenantEnabledChange = (tenantUuid: string, enabled: boolean) => {
        setTenantStates((prev) => ({
            ...prev,
            [tenantUuid]: { ...prev[tenantUuid], enabled },
        }));
    };

    const handleSaveTenant = (tenantUuid: string) => {
        setSavingTenant(tenantUuid);
        const state = tenantStates[tenantUuid];
        const payload = {
            enabled: state.enabled,
            custom_settings: state.settings,
        };
        router.put(`/admin/games/fantasy/settings/tenant/${tenantUuid}`, payload as unknown as Record<string, string>, {
            onSuccess: () => setSavingTenant(null),
            onError: () => setSavingTenant(null),
        });
    };

    return (
        <UserLayout title="Fantasy Settings">
            <Head title="Fantasy Settings" />
            <Toast ref={toast} />

            <div className="space-y-8">
                <PageHeader title="Fantasy Settings" subtitle={`Configure settings for ${game.name}`} />

                {/* Global Defaults */}
                <div
                    className="rounded-xl overflow-hidden"
                    style={{
                        background: 'var(--acu-surface-card)',
                        border: '1px solid var(--acu-border)',
                    }}
                >
                    <div
                        className="px-5 py-4 flex items-center justify-between"
                        style={{ borderBottom: '1px solid var(--acu-border)' }}
                    >
                        <div className="flex items-center gap-2">
                            <i className="pi pi-cog" style={{ color: 'var(--acu-primary)' }} />
                            <span className="font-semibold text-sm" style={{ color: 'var(--acu-text)', fontFamily: 'var(--font-display)' }}>
                                Global Defaults
                            </span>
                        </div>
                        <Button
                            label={savingGlobal ? 'Saving...' : 'Save Defaults'}
                            icon="pi pi-check"
                            size="small"
                            onClick={handleSaveGlobal}
                            loading={savingGlobal}
                            disabled={savingGlobal}
                        />
                    </div>
                    <div className="p-5">
                        <SettingsForm
                            values={globalSettings}
                            onChange={handleGlobalChange}
                            prefix="global"
                        />
                    </div>
                </div>

                {/* Tenant Overrides */}
                {tenants.length > 0 && (
                    <div className="acu-fieldset" style={{ '--fieldset-color': 'var(--acu-fieldset-gold)' } as React.CSSProperties}>
                        <div className="acu-fieldset-header">
                            <div className="acu-fieldset-title">
                                <i className="pi pi-building" />
                                <span>Tenant Overrides</span>
                                <span className="text-xs font-normal ml-1" style={{ color: 'var(--acu-text-light)' }}>
                                    ({tenants.length})
                                </span>
                            </div>
                        </div>
                        <div className="acu-fieldset-body p-0">
                            <Accordion multiple>
                                {tenants.map((tenant) => {
                                    const state = tenantStates[tenant.uuid];
                                    return (
                                        <AccordionTab
                                            key={tenant.uuid}
                                            header={
                                                <div className="flex items-center gap-3">
                                                    <span className="text-sm font-medium" style={{ color: 'var(--acu-text)' }}>
                                                        {tenant.name}
                                                    </span>
                                                    <span className="text-xs" style={{ color: 'var(--acu-text-light)' }}>
                                                        ({tenant.slug})
                                                    </span>
                                                    {state?.enabled && (
                                                        <span
                                                            className="text-[10px] font-semibold px-2 py-0.5 rounded"
                                                            style={{ background: 'var(--acu-success, #3FB950)', color: '#fff' }}
                                                        >
                                                            ENABLED
                                                        </span>
                                                    )}
                                                </div>
                                            }
                                        >
                                            {state && (
                                                <div className="space-y-5 p-2">
                                                    <div className="flex items-center justify-between">
                                                        <div className="flex items-center gap-3">
                                                            <InputSwitch
                                                                checked={state.enabled}
                                                                onChange={(e) => handleTenantEnabledChange(tenant.uuid, e.value ?? false)}
                                                            />
                                                            <label className="text-sm font-medium" style={{ color: 'var(--acu-text)' }}>
                                                                Game Enabled
                                                            </label>
                                                        </div>
                                                        <Button
                                                            label={savingTenant === tenant.uuid ? 'Saving...' : 'Save'}
                                                            icon="pi pi-check"
                                                            size="small"
                                                            onClick={() => handleSaveTenant(tenant.uuid)}
                                                            loading={savingTenant === tenant.uuid}
                                                            disabled={savingTenant === tenant.uuid}
                                                        />
                                                    </div>

                                                    <div>
                                                        <p className="text-xs mb-3" style={{ color: 'var(--acu-text-light)' }}>
                                                            Leave fields empty to use the global defaults.
                                                        </p>
                                                        <SettingsForm
                                                            values={state.settings}
                                                            onChange={(key, value) => handleTenantSettingChange(tenant.uuid, key, value)}
                                                            prefix={`tenant-${tenant.uuid}`}
                                                        />
                                                    </div>
                                                </div>
                                            )}
                                        </AccordionTab>
                                    );
                                })}
                            </Accordion>
                        </div>
                    </div>
                )}
            </div>
        </UserLayout>
    );
}
