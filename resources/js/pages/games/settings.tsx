// resources/js/pages/games/settings.tsx
//
// Settings for any game in the catalogue, rendered from its
// settings_schema. Three panels: Game defaults · Commercial defaults ·
// Tenant overrides (collapsible per tenant, sparse). Replaces the
// hardcoded fantasy/settings page; Fantasy is now just one schema.

import UserLayout from '@/layouts/user-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { InputSwitch } from 'primereact/inputswitch';
import { Toast } from 'primereact/toast';
import { useEffect, useMemo, useRef, useState } from 'react';

import { FieldLabel, PanelShell } from '@/components/games/panels';
import { depthRows } from './kulipi-depth';
import {
    SchemaForm,
    keysInGroup,
    overridableKeys,
    type SettingsSchema,
    type SettingsValues,
} from '@/components/games/SchemaForm';

interface TenantConfig {
    uuid: string;
    name: string;
    slug: string;
    enabled: boolean;
    custom_settings: SettingsValues;
}

interface AuditRow {
    id: number;
    at: string;
    by: string | null;
    scope: 'global' | 'tenant';
    tenant: string | null;
    changes: { key: string; before: unknown; after: unknown }[];
    rtp_before: number | null;
    rtp_after: number | null;
}

interface Props {
    game: { uuid: string; name: string; slug: string; settings: SettingsValues; theoretical_rtp?: number | null; expected_winners?: number | null; grid_size?: number | null };
    schema: SettingsSchema;
    tenants: TenantConfig[];
    audits?: AuditRow[];
}

function sparse(values: SettingsValues): SettingsValues {
    const out: SettingsValues = {};
    for (const [k, v] of Object.entries(values)) {
        if (v !== null && v !== undefined && v !== '') out[k] = v;
    }
    return out;
}

export default function GameSettings({ game, schema, tenants, audits = [] }: Props) {
    const [globalSettings, setGlobalSettings] = useState<SettingsValues>(game.settings || {});
    const [tenantStates, setTenantStates] = useState<Record<string, { enabled: boolean; settings: SettingsValues }>>(
        () => {
            const state: Record<string, { enabled: boolean; settings: SettingsValues }> = {};
            for (const t of tenants) state[t.uuid] = { enabled: t.enabled, settings: { ...(t.custom_settings || {}) } };
            return state;
        },
    );
    const [savingGlobal, setSavingGlobal] = useState(false);
    const [savingTenant, setSavingTenant] = useState<string | null>(null);
    const [expanded, setExpanded] = useState<Record<string, boolean>>({});
    const toast = useRef<Toast>(null);

    const { flash, errors } = usePage<{ flash: { success?: string; error?: string }; errors: Record<string, string> }>().props;

    useEffect(() => {
        if (flash?.success) toast.current?.show({ severity: 'success', summary: 'Saved', detail: flash.success });
        if (flash?.error) toast.current?.show({ severity: 'error', summary: 'Error', detail: flash.error });
    }, [flash]);

    useEffect(() => {
        const messages = Object.values(errors || {});
        if (messages.length > 0) {
            toast.current?.show({ severity: 'error', summary: 'Not saved', detail: messages.join(' '), life: 6000 });
        }
    }, [errors]);

    const gameKeys = useMemo(() => keysInGroup(schema, 'game'), [schema]);
    const commercialKeys = useMemo(() => keysInGroup(schema, 'commercial'), [schema]);
    const tenantKeys = useMemo(() => overridableKeys(schema), [schema]);
    const base = `/platform/games/${game.uuid}/settings`;

    const handleGlobalChange = (key: string, value: unknown) =>
        setGlobalSettings((prev) => ({ ...prev, [key]: value }));

    const handleSaveGlobal = () => {
        setSavingGlobal(true);
        router.put(`${base}/global`, globalSettings as unknown as Record<string, never>, {
            preserveScroll: true,
            onFinish: () => setSavingGlobal(false),
        });
    };

    const handleTenantSettingChange = (uuid: string, key: string, value: unknown) =>
        setTenantStates((prev) => ({
            ...prev,
            [uuid]: { ...prev[uuid], settings: { ...prev[uuid].settings, [key]: value } },
        }));

    const handleTenantEnabledChange = (uuid: string, enabled: boolean) =>
        setTenantStates((prev) => ({ ...prev, [uuid]: { ...prev[uuid], enabled } }));

    const handleSaveTenant = (uuid: string) => {
        setSavingTenant(uuid);
        const state = tenantStates[uuid];
        router.put(
            `${base}/tenant/${uuid}`,
            { enabled: state.enabled, custom_settings: sparse(state.settings) } as unknown as Record<string, never>,
            { preserveScroll: true, onFinish: () => setSavingTenant(null) },
        );
    };

    const toggle = (uuid: string) => setExpanded((prev) => ({ ...prev, [uuid]: !prev[uuid] }));

    const saveButton = (
        <button type="button" className="cg-btn cg-btn--primary cg-btn--sm" onClick={handleSaveGlobal} disabled={savingGlobal}>
            {savingGlobal ? 'Saving…' : 'Save defaults'}
        </button>
    );

    return (
        <UserLayout title={`${game.name} settings`}>
            <Head title={`${game.name} settings · Admin`} />
            <Toast ref={toast} />

            <div className="cgo-page">
                <div className="cgo-page-head">
                    <div>
                        <div className="cgo-eyebrow">{game.name}</div>
                        <h1 className="cgo-title">Settings</h1>
                        <div className="cgo-subtitle">
                            Game-wide defaults for <strong style={{ color: 'var(--cg-fg-1)' }}>{game.name}</strong> with
                            per-tenant overrides. Fields come from the game&apos;s settings schema.
                        </div>
                        {(typeof game.theoretical_rtp === 'number' || typeof game.expected_winners === 'number') && (
                            <div className="cgo-stat-strip">
                                {typeof game.theoretical_rtp === 'number' && (
                                    <div className="cgo-stat" data-testid="theoretical-rtp">
                                        <div className="cgo-stat-label">{game.slug === 'kulipi-kuna' ? 'Return to player at every level' : 'Theoretical RTP'}</div>
                                        <div className="cgo-stat-value">{(game.theoretical_rtp * 100).toFixed(2)}%</div>
                                        <div className="cgo-stat-note">House edge is the only setting that changes it.</div>
                                    </div>
                                )}
                                {typeof game.expected_winners === 'number' && (
                                    <div className="cgo-stat" data-testid="expected-winners">
                                        <div className="cgo-stat-label">Average winners per round</div>
                                        <div className="cgo-stat-value">
                                            {game.expected_winners.toFixed(1)} <span className="cgo-stat-of">of {game.grid_size ?? '?'}</span>
                                        </div>
                                        <div className="cgo-stat-note">
                                            Set by grid size, tier weights and house edge. Every team is drawn independently, so the
                                            count varies round to round.
                                        </div>
                                    </div>
                                )}
                            </div>
                        )}
                        {game.slug === 'kulipi-kuna' && (
                            <div data-testid="kulipi-depth" style={{ marginTop: 14 }}>
                                <div className="cgo-stat-label" style={{ marginBottom: 6 }}>How deep each stake can climb</div>
                                <table className="cgo-wagers" style={{ maxWidth: 520 }}>
                                    <thead>
                                        <tr>
                                            <th>Stake</th>
                                            <th className="cgo-r">Deepest level</th>
                                            <th className="cgo-r">That level pays</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {depthRows(globalSettings).map((r) => (
                                            <tr key={r.stake}>
                                                <td>N${r.stake}</td>
                                                <td className="cgo-r">{r.depth === 0 ? 'none' : r.depth}</td>
                                                <td className="cgo-r">{r.pays}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                                <div className="cgo-stat-note">Updates as you edit. A ladder&apos;s depth is fixed when it starts; a payout is never cut.</div>
                            </div>
                        )}
                    </div>
                </div>

                {gameKeys.length > 0 && (
                    <PanelShell
                        title="Game defaults"
                        description={
                            <>
                                These are the <em>base</em> values the engine reads at the top of every round; each tenant can
                                override individual fields below.
                            </>
                        }
                        action={saveButton}
                    >
                        <SchemaForm schema={schema} keys={gameKeys} values={globalSettings} onChange={handleGlobalChange} prefix="global" />
                    </PanelShell>
                )}

                {commercialKeys.length > 0 && (
                    <PanelShell
                        title="Commercial defaults · new tenants"
                        description={
                            <>
                                Applied to <strong>newly-created tenants only</strong>. Existing tenants keep their own values from
                                the tenant detail page. Math:{' '}
                                <code style={{ fontFamily: 'var(--cg-mono)', color: 'var(--cg-fg-2)' }}>NGR = GGR − tax</code>; for
                                resellers{' '}
                                <code style={{ fontFamily: 'var(--cg-mono)', color: 'var(--cg-fg-2)', overflowWrap: 'anywhere' }}>
                                    tenant_share = NGR × revenue_share_pct
                                </code>
                                ; for direct tenants{' '}
                                <code style={{ fontFamily: 'var(--cg-mono)', color: 'var(--cg-fg-2)' }}>tenant_share = 0</code>.
                            </>
                        }
                        action={saveButton}
                    >
                        <SchemaForm
                            schema={schema}
                            keys={commercialKeys}
                            values={globalSettings}
                            onChange={handleGlobalChange}
                            prefix="commercial"
                        />
                    </PanelShell>
                )}

                {gameKeys.length === 0 && commercialKeys.length === 0 && (
                    <PanelShell title="Game defaults">
                        <div style={{ textAlign: 'center', color: 'var(--cg-fg-3)', padding: '24px 0' }}>
                            This game has no settings schema yet. Add one on the game&apos;s catalogue page.
                        </div>
                    </PanelShell>
                )}

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
                            <div className="cgo-table-bar-title">Tenant overrides · {tenants.length}</div>
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
                                    <div key={tenant.uuid} style={{ borderBottom: isLast ? 'none' : '1px solid var(--cg-rule)' }}>
                                        <button
                                            type="button"
                                            onClick={() => toggle(tenant.uuid)}
                                            style={{
                                                all: 'unset',
                                                boxSizing: 'border-box',
                                                display: 'flex',
                                                alignItems: 'center',
                                                width: '100%',
                                                padding: '14px 18px',
                                                cursor: 'pointer',
                                                gap: 12,
                                            }}
                                        >
                                            <span style={{ width: 18, color: 'var(--cg-fg-3)', fontFamily: 'var(--cg-mono)', textAlign: 'center' }}>
                                                {isOpen ? '−' : '+'}
                                            </span>
                                            <div style={{ flex: 1, minWidth: 0, overflowWrap: 'anywhere' }}>
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
                                                        flexWrap: 'wrap',
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
                                                        <span style={{ fontSize: 13, fontWeight: 500 }}>Game enabled for this tenant</span>
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
                                                <div style={{ fontSize: 11, color: 'var(--cg-fg-3)', marginBottom: 14 }}>
                                                    Leave fields empty (or on &quot;Inherit global&quot;) to fall back to the global defaults above.
                                                </div>
                                                {tenantKeys.length > 0 ? (
                                                    <SchemaForm
                                                        schema={schema}
                                                        keys={tenantKeys}
                                                        values={state.settings}
                                                        onChange={(key, value) => handleTenantSettingChange(tenant.uuid, key, value)}
                                                        prefix={`tenant-${tenant.uuid}`}
                                                        sparse
                                                    />
                                                ) : (
                                                    <FieldLabel>No per-tenant settings for this game</FieldLabel>
                                                )}
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

                <PanelShell title="History" description={<>The latest settings changes for this game, newest first. Every save is recorded with the RTP before and after.</>}>
                    {audits.length === 0 ? (
                        <div style={{ fontSize: 13, color: 'var(--cg-fg-3)' }}>No changes recorded yet.</div>
                    ) : (
                        <table className="cgo-wagers" data-testid="settings-history">
                            <thead>
                                <tr>
                                    <th style={{ width: 170 }}>When</th>
                                    <th style={{ width: 160 }}>Who</th>
                                    <th style={{ width: 170 }}>Where</th>
                                    <th>What changed</th>
                                    <th className="cgo-r" style={{ width: 150 }}>RTP</th>
                                </tr>
                            </thead>
                            <tbody>
                                {audits.map((a) => (
                                    <tr key={a.id}>
                                        <td><span className="cgo-uid">{new Date(a.at).toLocaleString('en-GB')}</span></td>
                                        <td>{a.by ?? '—'}</td>
                                        <td>{a.scope === 'global' ? 'All tenants' : (a.tenant ?? 'A tenant')}</td>
                                        <td style={{ fontSize: 12 }}>
                                            {a.changes.map((c) => (
                                                <div key={c.key}>
                                                    <span className="cgo-uid">{c.key}</span> {JSON.stringify(c.before) ?? '—'} → {JSON.stringify(c.after) ?? '—'}
                                                </div>
                                            ))}
                                        </td>
                                        <td className="cgo-r">
                                            {a.rtp_before === a.rtp_after
                                                ? a.rtp_after === null ? '—' : `${(a.rtp_after * 100).toFixed(2)}%`
                                                : `${a.rtp_before === null ? '—' : (a.rtp_before * 100).toFixed(2) + '%'} → ${a.rtp_after === null ? '—' : (a.rtp_after * 100).toFixed(2) + '%'}`}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    )}
                </PanelShell>
            </div>

        </UserLayout>
    );
}
