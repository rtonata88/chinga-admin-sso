// resources/js/pages/platform/tenants/show.tsx
//
// Tenant detail, brass-on-ink to match /tenant-overview. Header
// (with status pill + actions) → KPI strip → Company / Configuration
// info panels (with inline commercial-terms editor) → Operator
// integration (settings, recent calls, movements needing attention) →
// Assigned Games table → Venues table. Dialogs (Add venue, Manage games)
// stay on PrimeReact for their form state.

import { KpiCard, formatCount } from '@/components/operator/kpi-card';
import UserLayout from '@/layouts/user-layout';
import { useClipboard } from '@/hooks/use-clipboard';
import { csrfHeader } from '@/lib/csrf';
import { formatMoney, useCurrencies } from '@/lib/money';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Button } from 'primereact/button';
import { Dialog } from 'primereact/dialog';
import { InputText } from 'primereact/inputtext';
import { Toast } from 'primereact/toast';
import { Fragment, useEffect, useRef, useState } from 'react';

interface Tenant {
    id: number;
    uuid: string;
    name: string;
    slug: string;
    legal_name: string;
    registration_number: string;
    license_number: string;
    contact_email: string;
    contact_phone: string;
    logo_url: string;
    domain: string;
    country_code: string;
    currency: string;
    timezone: string;
    status: string;
    revenue_share_pct: number;
    business_model: 'reseller' | 'direct';
    tax_pct: number;
    users_count: number;
    venues_count: number;
    voucher_codes_count: number;
    created_at: string;
}

interface TenantAdmin {
    uuid: string;
    name: string;
    email: string;
    status: string;
    last_login_at: string | null;
}

interface AssignedGame {
    uuid: string;
    name: string;
    slug: string;
    type: string;
    status: string;
    pivot: { enabled: boolean; custom_settings: unknown };
}

interface AvailableGame {
    uuid: string;
    name: string;
    slug: string;
    type: string;
    status: string;
}

interface Venue {
    uuid: string;
    name: string;
    slug: string;
    city: string;
    country_code: string;
    status: string;
    staff_count: number;
    terminals_count: number;
    voucher_codes_count: number;
}

function statusPill(status: string): string {
    switch (status) {
        case 'active': return 'live';
        case 'suspended': return 'flagged';
        case 'inactive':
        case 'closed': return 'void';
        default: return 'void';
    }
}

interface InfoItem {
    label: string;
    value: React.ReactNode;
    span?: number;
    mono?: boolean;
}

// Column span per item on the 12-column panel grid. Desktop spans are
// unchanged; below sm the narrow (3/4) items widen to half a row so
// mono values like timezones are not crushed onto a phone screen.
function spanClass(span: number): string {
    switch (span) {
        case 12: return 'col-span-12';
        case 4: return 'col-span-6 sm:col-span-4';
        case 3: return 'col-span-6 sm:col-span-3';
        default: return 'col-span-6';
    }
}

function InfoPanel({ title, items, action }: { title: string; items: InfoItem[]; action?: React.ReactNode }) {
    return (
        <div style={{ border: '1px solid var(--cg-rule)', borderRadius: 8, overflow: 'hidden', background: 'var(--cg-ink-card)' }}>
            <div className="cgo-table-bar">
                <div className="cgo-table-bar-title">{title}</div>
                {action}
            </div>
            <div
                className="grid grid-cols-12"
                style={{ gap: '14px 18px', padding: '18px' }}
            >
                {items.map((it, i) => (
                    <div key={i} className={`${spanClass(it.span ?? 6)} min-w-0`}>
                        <div
                            style={{
                                fontSize: 10,
                                textTransform: 'uppercase',
                                letterSpacing: '0.16em',
                                color: 'var(--cg-fg-3)',
                                fontWeight: 600,
                                marginBottom: 4,
                            }}
                        >
                            {it.label}
                        </div>
                        <div
                            style={{
                                fontSize: 13,
                                color: 'var(--cg-fg-1)',
                                fontFamily: it.mono ? 'var(--cg-mono)' : undefined,
                                fontFeatureSettings: it.mono ? "'tnum' 1" : undefined,
                                wordBreak: 'break-word',
                            }}
                        >
                            {it.value}
                        </div>
                    </div>
                ))}
            </div>
        </div>
    );
}

// ── Operator integration ───────────────────────────────────────────────
// The console form of `php artisan operators:setup`. The secret comes back
// once, from create and rotate, and is shown in a dialog that forgets it on
// close; no read ever returns it.

interface IntegrationSummary {
    operator_players: number;
    calls_in_24h: number;
    calls_out_24h: number;
    refused_24h: number;
    movements_attention: number;
}

type WalletMode = 'hosted' | 'seamless';
type IntegrationStatus = 'active' | 'suspended';

interface Integration {
    operator_key: string;
    wallet_mode: WalletMode;
    wallet_base_url: string | null;
    allowed_ips: string[];
    embed_origins: string[];
    status: IntegrationStatus;
    secret_rotated_at: string | null;
    previous_secret_valid_until: string | null;
    created_at: string | null;
    summary: IntegrationSummary;
}

interface OperatorCallRow {
    id: number;
    created_at: string;
    direction: 'in' | 'out';
    endpoint: string;
    reference: string | null;
    http_status: number | null;
    outcome: string;
    duration_ms: number | null;
    error: string | null;
}

interface OperatorMovementRow {
    id: number;
    created_at: string;
    updated_at: string;
    kind: 'debit' | 'credit' | 'rollback';
    reference: string;
    debit_reference: string | null;
    amount: string;
    currency: string;
    status: string;
    error_code: string | null;
    attempts: number;
    next_attempt_at: string | null;
    external_player_id: string | null;
}

interface IntegrationForm {
    wallet_mode: WalletMode;
    wallet_base_url: string;
    embed_origins: string;
    allowed_ips: string;
    status: IntegrationStatus;
}

type FieldErrors = Record<string, string[]>;

type Notify = (severity: 'success' | 'info' | 'warn' | 'error', summary: string, detail: string) => void;

const REFUSED_OUTCOMES = ['unknown_operator', 'bad_signature', 'operator_inactive', 'ip_not_allowed'];

function formatDateTime(iso: string | null): string {
    if (!iso) return '—';
    return new Intl.DateTimeFormat('en-GB', {
        day: '2-digit', month: 'short', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    }).format(new Date(iso));
}

function outcomePill(outcome: string): string {
    if (outcome === 'ok') return 'live';
    if (REFUSED_OUTCOMES.includes(outcome)) return 'flagged';
    return 'pending';
}

function lines(text: string): string[] {
    return text.split(/[\n,]/).map((s) => s.trim()).filter((s) => s !== '');
}

/** The first message for a field, including its list items (embed_origins.0, …). */
function fieldError(errors: FieldErrors, field: string): string | null {
    const key = Object.keys(errors).find((k) => k === field || k.startsWith(`${field}.`));
    return key ? errors[key][0] ?? null : null;
}

const fieldStyle: React.CSSProperties = {
    width: '100%',
    background: 'var(--cg-ink-card)',
    border: '1px solid var(--cg-rule-strong)',
    borderRadius: 4,
    color: 'var(--cg-fg-1)',
    padding: '6px 8px',
    fontSize: 12,
};

const monoFieldStyle: React.CSSProperties = { ...fieldStyle, fontFamily: 'var(--cg-mono)' };

function FormRow({ label, error, hint, children }: { label: string; error: string | null; hint?: React.ReactNode; children: React.ReactNode }) {
    return (
        <div style={{ display: 'grid', gap: 4 }}>
            <span style={{ fontSize: 11, color: 'var(--cg-fg-3)' }}>{label}</span>
            {children}
            {hint && !error && <small style={{ fontSize: 11, color: 'var(--cg-fg-3)' }}>{hint}</small>}
            {error && <small style={{ fontSize: 11, color: 'var(--cg-neg)' }}>{error}</small>}
        </div>
    );
}

const tableBarStyle: React.CSSProperties = {
    borderRadius: '8px 8px 0 0',
    borderTop: '1px solid var(--cg-rule)',
    borderLeft: '1px solid var(--cg-rule)',
    borderRight: '1px solid var(--cg-rule)',
    marginTop: 0,
};

function IntegrationPanel({ tenantUuid, notify }: { tenantUuid: string; notify: Notify }) {
    const base = `/api/v1/platform/tenants/${tenantUuid}/integration`;
    const currencyOf = useCurrencies();
    const [, copy] = useClipboard();

    const [integration, setIntegration] = useState<Integration | null>(null);
    const [loading, setLoading] = useState(true);
    const [calls, setCalls] = useState<OperatorCallRow[]>([]);
    const [movements, setMovements] = useState<OperatorMovementRow[]>([]);

    const [busy, setBusy] = useState(false);
    const [confirmingRotate, setConfirmingRotate] = useState(false);
    const [shownSecret, setShownSecret] = useState<{ secret: string; reason: 'created' | 'rotated' } | null>(null);

    const [editing, setEditing] = useState(false);
    const [form, setForm] = useState<IntegrationForm>({ wallet_mode: 'hosted', wallet_base_url: '', embed_origins: '', allowed_ips: '', status: 'active' });
    const [errors, setErrors] = useState<FieldErrors>({});

    const jsonHeaders = (): Record<string, string> => ({
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...csrfHeader(),
    });

    const load = async () => {
        setLoading(true);
        try {
            const res = await fetch(base, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const body: { data: Integration | null } = await res.json();
            setIntegration(body.data);
            if (body.data) {
                const [callsRes, movementsRes] = await Promise.all([
                    fetch(`${base}/calls?limit=50`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' }),
                    fetch(`${base}/movements?status=retrying,abandoned`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' }),
                ]);
                const callsBody: { data: OperatorCallRow[] } = await callsRes.json();
                const movementsBody: { data: OperatorMovementRow[] } = await movementsRes.json();
                setCalls(callsBody.data ?? []);
                setMovements(movementsBody.data ?? []);
            }
        } catch (error) {
            console.error('Failed to load operator integration:', error);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        void load();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [tenantUuid]);

    const handleCreate = async () => {
        setBusy(true);
        try {
            const res = await fetch(base, { method: 'POST', headers: jsonHeaders(), credentials: 'same-origin', body: JSON.stringify({}) });
            const body: { data?: Integration; secret?: string; message?: string } = await res.json();
            if (res.ok && body.data && body.secret) {
                setIntegration(body.data);
                setShownSecret({ secret: body.secret, reason: 'created' });
                void load();
            } else {
                notify('error', 'Error', body.message ?? 'Failed to set up the integration.');
            }
        } catch {
            notify('error', 'Error', 'Failed to set up the integration.');
        } finally {
            setBusy(false);
        }
    };

    const handleRotate = async () => {
        setBusy(true);
        try {
            const res = await fetch(`${base}/rotate-secret`, { method: 'POST', headers: jsonHeaders(), credentials: 'same-origin' });
            const body: { data?: Integration; secret?: string; message?: string } = await res.json();
            if (res.ok && body.data && body.secret) {
                setIntegration(body.data);
                setShownSecret({ secret: body.secret, reason: 'rotated' });
                setConfirmingRotate(false);
            } else {
                notify('error', 'Error', body.message ?? 'Failed to rotate the secret.');
            }
        } catch {
            notify('error', 'Error', 'Failed to rotate the secret.');
        } finally {
            setBusy(false);
        }
    };

    const startEdit = () => {
        if (!integration) return;
        setForm({
            wallet_mode: integration.wallet_mode,
            wallet_base_url: integration.wallet_base_url ?? '',
            embed_origins: integration.embed_origins.join('\n'),
            allowed_ips: integration.allowed_ips.join('\n'),
            status: integration.status,
        });
        setErrors({});
        setConfirmingRotate(false);
        setEditing(true);
    };

    const handleSave = async () => {
        setBusy(true);
        setErrors({});
        try {
            const res = await fetch(base, {
                method: 'PUT',
                headers: jsonHeaders(),
                credentials: 'same-origin',
                body: JSON.stringify({
                    wallet_mode: form.wallet_mode,
                    wallet_base_url: form.wallet_base_url.trim() || null,
                    embed_origins: lines(form.embed_origins),
                    allowed_ips: lines(form.allowed_ips),
                    status: form.status,
                }),
            });
            const body: { data?: Integration; message?: string; errors?: FieldErrors } = await res.json();
            if (res.ok && body.data) {
                const originsChanged = body.data.embed_origins.join('\n') !== (integration?.embed_origins ?? []).join('\n');
                setIntegration(body.data);
                setEditing(false);
                notify(
                    'success',
                    'Saved',
                    originsChanged
                        ? 'Integration updated. Embed origins changed: update the game hosts’ nginx line.'
                        : 'Integration updated.',
                );
            } else {
                setErrors(body.errors ?? {});
                notify('error', 'Not saved', body.message ?? 'Failed to update the integration.');
            }
        } catch {
            notify('error', 'Error', 'Failed to update the integration.');
        } finally {
            setBusy(false);
        }
    };

    const copyText = async (text: string, what: string) => {
        const ok = await copy(text);
        notify(ok ? 'info' : 'warn', ok ? 'Copied' : 'Copy failed', ok ? `${what} copied to clipboard.` : `Select the ${what.toLowerCase()} and copy it by hand.`);
    };

    const nginxHint = (
        <>
            After changing embed origins, put the line printed by{' '}
            <code style={{ fontFamily: 'var(--cg-mono)', color: 'var(--cg-brass-hi)' }}>php artisan operators:frame-origins --nginx</code>{' '}
            into the game hosts&apos; nginx config and reload it, or the games will not load in the operator&apos;s frame.
        </>
    );

    if (loading && !integration) {
        return (
            <InfoPanel
                title="Operator integration"
                items={[{ label: 'Status', value: <span style={{ color: 'var(--cg-fg-3)' }}>Loading…</span>, span: 12 }]}
            />
        );
    }

    const secretDialog = (
        <Dialog
            header={shownSecret?.reason === 'created' ? 'Integration created' : 'Secret rotated'}
            visible={shownSecret !== null}
            style={{ width: '30rem', maxWidth: 'calc(100vw - 2rem)' }}
            onHide={() => setShownSecret(null)}
            modal
            draggable={false}
            footer={
                <div className="flex justify-end gap-2">
                    <Button label="I have saved it" onClick={() => setShownSecret(null)} />
                </div>
            }
        >
            {shownSecret && (
                <div className="space-y-4">
                    <p style={{ fontSize: 12, color: 'var(--cg-neg)', fontWeight: 600 }}>
                        You won&apos;t see this secret again. Copy it now and give it to the operator over a secure channel.
                    </p>
                    {shownSecret.reason === 'rotated' && (
                        <p style={{ fontSize: 12, color: 'var(--cg-fg-3)' }}>
                            The previous secret keeps verifying for 24 hours, so the operator can switch without downtime.
                        </p>
                    )}
                    <div style={{ background: 'var(--cg-ink-elevated)', border: '1px solid var(--cg-rule)', borderRadius: 6, padding: 14 }}>
                        <div className="cgo-uid" style={{ marginBottom: 4 }}>Signing secret</div>
                        <code style={{ display: 'block', fontFamily: 'var(--cg-mono)', fontSize: 12, color: 'var(--cg-brass-hi)', wordBreak: 'break-all' }}>
                            {shownSecret.secret}
                        </code>
                    </div>
                    {integration && (
                        <div className="cgo-uid">
                            Operator key (X-Chinga-Operator): <span style={{ fontFamily: 'var(--cg-mono)' }}>{integration.operator_key}</span>
                        </div>
                    )}
                    <button
                        type="button"
                        className="cg-btn cg-btn--ghost cg-btn--sm"
                        onClick={() => void copyText(shownSecret.secret, 'Secret')}
                        style={{ width: '100%', justifyContent: 'center' }}
                    >
                        Copy secret
                    </button>
                </div>
            )}
        </Dialog>
    );

    if (!integration) {
        return (
            <>
                <InfoPanel
                    title="Operator integration"
                    action={
                        <button type="button" className="cg-btn cg-btn--primary cg-btn--sm" onClick={() => void handleCreate()} disabled={busy}>
                            {busy ? 'Setting up…' : 'Set up integration'}
                        </button>
                    }
                    items={[{
                        label: 'Not set up',
                        span: 12,
                        value: (
                            <span style={{ color: 'var(--cg-fg-3)' }}>
                                This tenant has no operator integration. Setting one up issues an operator key and a signing secret
                                (hosted wallet, active); the secret is shown once.
                            </span>
                        ),
                    }]}
                />
                {secretDialog}
            </>
        );
    }

    const s = integration.summary;
    const actions = (
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
            {!editing && (
                <button type="button" className="cg-btn cg-btn--text cg-btn--sm" onClick={startEdit} disabled={busy}>
                    Edit
                </button>
            )}
            {!confirmingRotate && (
                <button type="button" className="cg-btn cg-btn--ghost cg-btn--sm" onClick={() => setConfirmingRotate(true)} disabled={busy}>
                    Rotate secret
                </button>
            )}
        </div>
    );

    const rotateConfirm: InfoItem = {
        label: 'Rotate secret?',
        span: 12,
        value: (
            <div
                style={{
                    display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap',
                    border: '1px solid var(--cg-brass)', background: 'var(--cg-brass-wash)', borderRadius: 6, padding: '10px 12px',
                }}
            >
                <span style={{ flex: '1 1 260px', color: 'var(--cg-fg-2)', fontSize: 12 }}>
                    A new secret is issued and shown once. The current one keeps verifying for 24 hours; after that, the
                    operator&apos;s calls signed with it are refused.
                </span>
                <div style={{ display: 'flex', gap: 8 }}>
                    <button type="button" className="cg-btn cg-btn--text cg-btn--sm" onClick={() => setConfirmingRotate(false)} disabled={busy}>
                        Cancel
                    </button>
                    <button type="button" className="cg-btn cg-btn--primary cg-btn--sm" onClick={() => void handleRotate()} disabled={busy}>
                        {busy ? 'Rotating…' : 'Yes, rotate'}
                    </button>
                </div>
            </div>
        ),
    };

    const editItem: InfoItem = {
        label: 'Edit integration',
        span: 12,
        value: (
            <div style={{ display: 'grid', gap: 12, paddingTop: 4 }}>
                <div className="grid grid-cols-1 sm:grid-cols-2" style={{ gap: 12 }}>
                    <FormRow label="Wallet mode" error={fieldError(errors, 'wallet_mode')} hint="Changes only while no operator player holds money in the current mode.">
                        <select
                            value={form.wallet_mode}
                            onChange={(e) => setForm({ ...form, wallet_mode: e.target.value as WalletMode })}
                            disabled={busy}
                            style={fieldStyle}
                        >
                            <option value="hosted">Hosted (money in our wallet)</option>
                            <option value="seamless">Seamless (money at the operator)</option>
                        </select>
                    </FormRow>
                    <FormRow label="Status" error={fieldError(errors, 'status')}>
                        <select
                            value={form.status}
                            onChange={(e) => setForm({ ...form, status: e.target.value as IntegrationStatus })}
                            disabled={busy}
                            style={fieldStyle}
                        >
                            <option value="active">Active (accept calls)</option>
                            <option value="suspended">Suspended (refuse calls)</option>
                        </select>
                    </FormRow>
                </div>
                <FormRow
                    label={form.wallet_mode === 'seamless' ? 'Operator wallet URL *' : 'Operator wallet URL'}
                    error={fieldError(errors, 'wallet_base_url')}
                    hint="https, the base of the operator's wallet API. Required for seamless."
                >
                    <InputText
                        value={form.wallet_base_url}
                        onChange={(e) => setForm({ ...form, wallet_base_url: e.target.value })}
                        placeholder="https://wallet.operator.com/chinga"
                        disabled={busy}
                        style={monoFieldStyle}
                    />
                </FormRow>
                <div className="grid grid-cols-1 sm:grid-cols-2" style={{ gap: 12 }}>
                    <FormRow label="Embed origins (one per line)" error={fieldError(errors, 'embed_origins')} hint="scheme://host[:port], no path.">
                        <textarea
                            rows={3}
                            value={form.embed_origins}
                            onChange={(e) => setForm({ ...form, embed_origins: e.target.value })}
                            placeholder="https://www.operator.com"
                            disabled={busy}
                            style={monoFieldStyle}
                        />
                    </FormRow>
                    <FormRow label="Allowed IPs (one per line)" error={fieldError(errors, 'allowed_ips')} hint="Addresses or CIDR ranges. Empty = any.">
                        <textarea
                            rows={3}
                            value={form.allowed_ips}
                            onChange={(e) => setForm({ ...form, allowed_ips: e.target.value })}
                            placeholder="196.44.0.0/16"
                            disabled={busy}
                            style={monoFieldStyle}
                        />
                    </FormRow>
                </div>
                <div style={{ fontSize: 11, color: 'var(--cg-fg-3)' }}>{nginxHint}</div>
                <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
                    <button type="button" className="cg-btn cg-btn--text cg-btn--sm" onClick={() => setEditing(false)} disabled={busy}>
                        Cancel
                    </button>
                    <button type="button" className="cg-btn cg-btn--primary cg-btn--sm" onClick={() => void handleSave()} disabled={busy}>
                        {busy ? 'Saving…' : 'Save'}
                    </button>
                </div>
            </div>
        ),
    };

    const items: InfoItem[] = [
        {
            label: 'Wallet mode',
            span: 3,
            value: <span className={`cgo-pill ${integration.wallet_mode === 'seamless' ? 'settled' : 'live'}`}>{integration.wallet_mode}</span>,
        },
        { label: 'Status', span: 3, value: <span className={`cgo-pill ${statusPill(integration.status)}`}>{integration.status}</span> },
        {
            label: 'Operator key (X-Chinga-Operator)',
            span: 6,
            value: (
                <span style={{ display: 'inline-flex', alignItems: 'center', gap: 8, flexWrap: 'wrap' }}>
                    <span style={{ fontFamily: 'var(--cg-mono)', wordBreak: 'break-all' }}>{integration.operator_key}</span>
                    <button type="button" className="cg-btn cg-btn--text cg-btn--sm" onClick={() => void copyText(integration.operator_key, 'Operator key')}>
                        Copy
                    </button>
                </span>
            ),
        },
        { label: 'Wallet URL', span: 12, mono: true, value: integration.wallet_base_url || '—' },
        {
            label: 'Embed origins',
            span: 6,
            mono: true,
            value: integration.embed_origins.length ? integration.embed_origins.map((o) => <div key={o}>{o}</div>) : '—',
        },
        {
            label: 'Allowed IPs',
            span: 6,
            mono: true,
            value: integration.allowed_ips.length ? integration.allowed_ips.map((ip) => <div key={ip}>{ip}</div>) : 'any',
        },
        {
            label: 'Last secret rotation',
            span: 6,
            value: (
                <>
                    {integration.secret_rotated_at ? formatDateTime(integration.secret_rotated_at) : 'Never (issued at set-up)'}
                    {integration.previous_secret_valid_until && (
                        <div style={{ fontSize: 11, color: 'var(--cg-fg-3)' }}>
                            Previous secret verifies until {formatDateTime(integration.previous_secret_valid_until)}
                        </div>
                    )}
                </>
            ),
        },
        {
            label: 'Last 24 hours',
            span: 6,
            value: (
                <span style={{ color: 'var(--cg-fg-3)', fontSize: 12 }}>
                    <span style={{ color: 'var(--cg-fg-1)', fontFamily: 'var(--cg-mono)' }}>{formatCount(s.operator_players)}</span> operator players ·{' '}
                    <span style={{ color: 'var(--cg-fg-1)', fontFamily: 'var(--cg-mono)' }}>{formatCount(s.calls_in_24h)}</span> in /{' '}
                    <span style={{ color: 'var(--cg-fg-1)', fontFamily: 'var(--cg-mono)' }}>{formatCount(s.calls_out_24h)}</span> out ·{' '}
                    <span style={{ color: s.refused_24h > 0 ? 'var(--cg-neg)' : 'var(--cg-fg-1)', fontFamily: 'var(--cg-mono)' }}>{formatCount(s.refused_24h)}</span> refused ·{' '}
                    <span style={{ color: s.movements_attention > 0 ? 'var(--cg-neg)' : 'var(--cg-fg-1)', fontFamily: 'var(--cg-mono)' }}>{formatCount(s.movements_attention)}</span> movements need attention
                </span>
            ),
        },
    ];
    if (!editing) items.push({ label: 'Frame headers', span: 12, value: <span style={{ fontSize: 12, color: 'var(--cg-fg-3)' }}>{nginxHint}</span> });

    return (
        <div style={{ marginBottom: 24 }}>
            <div style={{ marginBottom: 16 }}>
                <InfoPanel
                    title="Operator integration"
                    action={actions}
                    items={[...(confirmingRotate ? [rotateConfirm] : []), ...(editing ? [editItem] : []), ...items]}
                />
            </div>

            {/* Recent operator calls */}
            <div className="cgo-table-bar" style={tableBarStyle}>
                <div className="cgo-table-bar-title">Recent operator calls · {calls.length}</div>
                <button type="button" className="cg-btn cg-btn--text cg-btn--sm" onClick={() => void load()} disabled={loading}>
                    {loading ? 'Refreshing…' : 'Refresh'}
                </button>
            </div>
            <div className="cgo-table-wrap cgo-table-wrap--scroll" style={{ borderRadius: '0 0 8px 8px', marginBottom: 16 }}>
                <table className="cgo-wagers">
                    <thead>
                        <tr>
                            <th style={{ width: 170 }}>Time</th>
                            <th style={{ width: 60 }}>Dir</th>
                            <th style={{ minWidth: 120 }}>Endpoint</th>
                            <th style={{ minWidth: 140 }}>Reference</th>
                            <th style={{ minWidth: 160 }}>Status</th>
                            <th className="cgo-r" style={{ width: 70 }}>ms</th>
                        </tr>
                    </thead>
                    <tbody>
                        {calls.length === 0 ? (
                            <tr>
                                <td colSpan={6} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>No signed calls yet.</td>
                            </tr>
                        ) : (
                            calls.map((c) => (
                                <tr key={c.id}>
                                    <td><span className="cgo-uid">{formatDateTime(c.created_at)}</span></td>
                                    <td><span className="cgo-uid">{c.direction}</span></td>
                                    <td><span style={{ fontFamily: 'var(--cg-mono)', fontSize: 12 }}>{c.endpoint}</span></td>
                                    <td><span className="cgo-uid">{c.reference ?? '—'}</span></td>
                                    <td>
                                        <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, flexWrap: 'wrap' }}>
                                            <span className="cgo-uid">{c.http_status ?? '—'}</span>
                                            <span className={`cgo-pill ${outcomePill(c.outcome)}`} title={c.error ?? undefined}>{c.outcome}</span>
                                        </span>
                                    </td>
                                    <td className="cgo-r"><span className="cgo-odds">{c.duration_ms ?? '—'}</span></td>
                                </tr>
                            ))
                        )}
                    </tbody>
                </table>
            </div>

            {/* Seamless movements needing attention */}
            <div className="cgo-table-bar" style={tableBarStyle}>
                <div className="cgo-table-bar-title">Movements needing attention · {movements.length}</div>
            </div>
            <div className="cgo-table-wrap cgo-table-wrap--scroll" style={{ borderRadius: '0 0 8px 8px' }}>
                <table className="cgo-wagers">
                    <thead>
                        <tr>
                            <th style={{ width: 170 }}>Updated</th>
                            <th style={{ width: 80 }}>Kind</th>
                            <th style={{ minWidth: 110 }}>Player</th>
                            <th style={{ minWidth: 140 }}>Reference</th>
                            <th className="cgo-r" style={{ width: 120 }}>Amount</th>
                            <th style={{ minWidth: 140 }}>Status</th>
                            <th className="cgo-r" style={{ width: 80 }}>Attempts</th>
                            <th style={{ width: 170 }}>Next attempt</th>
                        </tr>
                    </thead>
                    <tbody>
                        {movements.length === 0 ? (
                            <tr>
                                <td colSpan={8} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>
                                    Nothing retrying or abandoned.
                                </td>
                            </tr>
                        ) : (
                            movements.map((m) => {
                                const profile = currencyOf(m.currency);
                                return (
                                    <tr key={m.id}>
                                        <td><span className="cgo-uid">{formatDateTime(m.updated_at)}</span></td>
                                        <td><span className="cgo-uid">{m.kind}</span></td>
                                        <td><span className="cgo-uid">{m.external_player_id ?? '—'}</span></td>
                                        <td><span className="cgo-uid">{m.reference}</span></td>
                                        <td className="cgo-r">
                                            <span className="cgo-odds">{profile ? formatMoney(m.amount, profile) : `${m.currency} ${m.amount}`}</span>
                                        </td>
                                        <td>
                                            <span style={{ display: 'inline-flex', alignItems: 'center', gap: 6, flexWrap: 'wrap' }}>
                                                <span className={`cgo-pill ${m.status === 'abandoned' ? 'flagged' : 'pending'}`}>{m.status}</span>
                                                {m.error_code && <span className="cgo-uid">{m.error_code}</span>}
                                            </span>
                                        </td>
                                        <td className="cgo-r"><span className="cgo-odds">{m.attempts}</span></td>
                                        <td><span className="cgo-uid">{m.status === 'retrying' ? formatDateTime(m.next_attempt_at) : '—'}</span></td>
                                    </tr>
                                );
                            })
                        )}
                    </tbody>
                </table>
            </div>

            {secretDialog}
        </div>
    );
}

const generateSlug = (name: string) =>
    name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');

export default function TenantShow() {
    const [tenant, setTenant] = useState<Tenant | null>(null);
    const [loading, setLoading] = useState(true);
    const [venues, setVenues] = useState<Venue[]>([]);
    const [venuesLoading, setVenuesLoading] = useState(true);

    const [addVenueOpen, setAddVenueOpen] = useState(false);
    const [saving, setSaving] = useState(false);
    const [formData, setFormData] = useState({
        name: '',
        slug: '',
        address_line_1: '',
        city: '',
        country_code: 'NA',
        phone: '',
        email: '',
    });

    const toast = useRef<Toast>(null);
    const [assignedGames, setAssignedGames] = useState<AssignedGame[]>([]);
    const [gamesLoading, setGamesLoading] = useState(true);
    const [manageGamesOpen, setManageGamesOpen] = useState(false);
    const [allGames, setAllGames] = useState<{ assigned: AssignedGame[]; available: AvailableGame[] }>({ assigned: [], available: [] });
    const [selectedGameUuids, setSelectedGameUuids] = useState<string[]>([]);
    const [dialogLoading, setDialogLoading] = useState(false);
    const [syncing, setSyncing] = useState(false);

    const [editingCommercial, setEditingCommercial] = useState(false);
    const [revenueInput, setRevenueInput] = useState('');
    const [taxInput, setTaxInput] = useState('');
    const [businessModelInput, setBusinessModelInput] = useState<'reseller' | 'direct'>('reseller');
    const [savingCommercial, setSavingCommercial] = useState(false);

    // Tenant admins panel
    const [admins, setAdmins] = useState<TenantAdmin[]>([]);
    const [adminsLoading, setAdminsLoading] = useState(true);
    const [addAdminOpen, setAddAdminOpen] = useState(false);
    const [savingAdmin, setSavingAdmin] = useState(false);
    const [adminForm, setAdminForm] = useState({ name: '', email: '', password: '' });

    const { uuid } = usePage<{ uuid: string }>().props;

    const csrfHeaders = (): Record<string, string> => ({
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...csrfHeader(),
    });

    const fetchAdmins = async (tenantId: number) => {
        setAdminsLoading(true);
        try {
            const params = new URLSearchParams({
                tenant_id: String(tenantId),
                role: 'tenant_admin',
                per_page: '100',
            });
            const response = await fetch(`/api/v1/platform/users?${params}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const data = await response.json();
            if (data.success) {
                setAdmins(
                    data.data.map((u: { uuid: string; name: string; email: string; status: string; last_login_at: string | null }) => ({
                        uuid: u.uuid,
                        name: u.name,
                        email: u.email,
                        status: u.status,
                        last_login_at: u.last_login_at,
                    }))
                );
            }
        } catch (error) {
            console.error('Failed to fetch tenant admins:', error);
        } finally {
            setAdminsLoading(false);
        }
    };

    const handleCreateAdmin = async () => {
        if (!tenant) return;
        setSavingAdmin(true);
        try {
            // Step 1: create the user attached to this tenant.
            const createRes = await fetch('/api/v1/platform/users', {
                method: 'POST',
                headers: csrfHeaders(),
                credentials: 'same-origin',
                body: JSON.stringify({
                    name: adminForm.name,
                    email: adminForm.email,
                    password: adminForm.password,
                    tenant_id: tenant.id,
                }),
            });
            const created = await createRes.json();
            if (!createRes.ok || !created.success) {
                toast.current?.show({
                    severity: 'error',
                    summary: 'Error',
                    detail: created.message || 'Failed to create user.',
                });
                setSavingAdmin(false);
                return;
            }

            // Step 2: assign tenant_admin role for this tenant.
            const roleRes = await fetch(`/api/v1/platform/users/${created.data.uuid}/roles`, {
                method: 'POST',
                headers: csrfHeaders(),
                credentials: 'same-origin',
                body: JSON.stringify({ role: 'tenant_admin', tenant_id: tenant.id }),
            });
            const role = await roleRes.json();
            if (!roleRes.ok || !role.success) {
                toast.current?.show({
                    severity: 'warn',
                    summary: 'User created, role failed',
                    detail: role.message || 'User exists but tenant_admin role was not assigned.',
                });
            } else {
                toast.current?.show({
                    severity: 'success',
                    summary: 'Admin added',
                    detail: `${adminForm.name} can now sign in as a tenant admin.`,
                });
            }

            setAddAdminOpen(false);
            setAdminForm({ name: '', email: '', password: '' });
            fetchAdmins(tenant.id);
        } catch {
            toast.current?.show({ severity: 'error', summary: 'Error', detail: 'Failed to add admin.' });
        } finally {
            setSavingAdmin(false);
        }
    };

    const handleRevokeAdmin = async (admin: TenantAdmin) => {
        if (!tenant) return;
        if (!confirm(`Revoke tenant admin role from ${admin.name}? Their account stays; they just lose admin access.`)) return;
        try {
            const params = new URLSearchParams({ tenant_id: String(tenant.id) });
            const response = await fetch(`/api/v1/platform/users/${admin.uuid}/roles/tenant_admin?${params}`, {
                method: 'DELETE',
                headers: csrfHeaders(),
                credentials: 'same-origin',
            });
            const data = await response.json();
            if (response.ok && data.success) {
                toast.current?.show({ severity: 'success', summary: 'Revoked', detail: 'Tenant admin role removed.' });
                fetchAdmins(tenant.id);
            } else {
                toast.current?.show({ severity: 'error', summary: 'Error', detail: data.message || 'Failed to revoke role.' });
            }
        } catch {
            toast.current?.show({ severity: 'error', summary: 'Error', detail: 'Failed to revoke role.' });
        }
    };

    const fetchVenues = async () => {
        setVenuesLoading(true);
        try {
            const response = await fetch(`/api/v1/platform/tenants/${uuid}/venues`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const data = await response.json();
            if (data.success) setVenues(data.data);
        } catch (error) {
            console.error('Failed to fetch venues:', error);
        } finally {
            setVenuesLoading(false);
        }
    };

    const fetchAssignedGames = async () => {
        setGamesLoading(true);
        try {
            const response = await fetch(`/api/v1/platform/tenants/${uuid}/games`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const data = await response.json();
            setAssignedGames(data.assigned || []);
        } catch (error) {
            console.error('Failed to fetch games:', error);
        } finally {
            setGamesLoading(false);
        }
    };

    const openManageGamesDialog = async () => {
        setManageGamesOpen(true);
        setDialogLoading(true);
        try {
            const response = await fetch(`/api/v1/platform/tenants/${uuid}/games`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            const data = await response.json();
            setAllGames({ assigned: data.assigned || [], available: data.available || [] });
            setSelectedGameUuids((data.assigned || []).map((g: AssignedGame) => g.uuid));
        } catch (error) {
            console.error('Failed to fetch games:', error);
            toast.current?.show({ severity: 'error', summary: 'Error', detail: 'Failed to load games.' });
        } finally {
            setDialogLoading(false);
        }
    };

    const handleSyncGames = async () => {
        setSyncing(true);
        try {
            const response = await fetch(`/api/v1/platform/tenants/${uuid}/games`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    ...csrfHeader(),
                },
                body: JSON.stringify({
                    games: selectedGameUuids.map((gameUuid) => {
                        const existing = allGames.assigned.find((g) => g.uuid === gameUuid);
                        return { uuid: gameUuid, enabled: existing ? existing.pivot.enabled : true };
                    }),
                }),
            });
            if (response.ok) {
                setManageGamesOpen(false);
                fetchAssignedGames();
                toast.current?.show({ severity: 'success', summary: 'Saved', detail: 'Game assignments updated.' });
            } else {
                const data = await response.json();
                toast.current?.show({ severity: 'error', summary: 'Error', detail: data.message || 'Failed to update games.' });
            }
        } catch {
            toast.current?.show({ severity: 'error', summary: 'Error', detail: 'Failed to update games.' });
        } finally {
            setSyncing(false);
        }
    };

    const handleStartEditCommercial = () => {
        if (!tenant) return;
        setRevenueInput(String(tenant.revenue_share_pct ?? ''));
        setTaxInput(String(tenant.tax_pct ?? ''));
        setBusinessModelInput(tenant.business_model ?? 'reseller');
        setEditingCommercial(true);
    };

    const handleSaveCommercial = async () => {
        const revenue = Number(revenueInput);
        const tax = Number(taxInput);
        if (!Number.isFinite(revenue) || revenue < 0 || revenue > 100) {
            toast.current?.show({ severity: 'warn', summary: 'Invalid', detail: 'Revenue share must be 0–100.' });
            return;
        }
        if (!Number.isFinite(tax) || tax < 0 || tax > 100) {
            toast.current?.show({ severity: 'warn', summary: 'Invalid', detail: 'Tax must be 0–100.' });
            return;
        }

        setSavingCommercial(true);
        try {
            const response = await fetch(`/api/v1/platform/tenants/${uuid}`, {
                method: 'PUT',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    ...csrfHeader(),
                },
                body: JSON.stringify({
                    revenue_share_pct: revenue,
                    tax_pct: tax,
                    business_model: businessModelInput,
                }),
            });
            const data = await response.json();
            if (response.ok) {
                setTenant((prev) => prev ? {
                    ...prev,
                    revenue_share_pct: Number(data.data.revenue_share_pct),
                    tax_pct: Number(data.data.tax_pct),
                    business_model: data.data.business_model,
                } : prev);
                setEditingCommercial(false);
                toast.current?.show({ severity: 'success', summary: 'Saved', detail: 'Commercial terms updated.' });
            } else {
                const errors = data.errors ?? {};
                const msg = errors.revenue_share_pct?.[0] ?? errors.tax_pct?.[0] ?? errors.business_model?.[0] ?? data.message ?? 'Failed to update.';
                toast.current?.show({ severity: 'error', summary: 'Error', detail: msg });
            }
        } catch {
            toast.current?.show({ severity: 'error', summary: 'Error', detail: 'Failed to update commercial terms.' });
        } finally {
            setSavingCommercial(false);
        }
    };

    const handleToggleEnabled = async (game: AssignedGame) => {
        const newEnabled = !game.pivot.enabled;
        try {
            const response = await fetch(`/api/v1/platform/tenants/${uuid}/games/${game.uuid}`, {
                method: 'PUT',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    ...csrfHeader(),
                },
                body: JSON.stringify({ enabled: newEnabled }),
            });
            if (response.ok) {
                setAssignedGames((prev) =>
                    prev.map((g) => g.uuid === game.uuid ? { ...g, pivot: { ...g.pivot, enabled: newEnabled } } : g)
                );
            } else {
                toast.current?.show({ severity: 'error', summary: 'Error', detail: 'Failed to toggle game.' });
            }
        } catch {
            toast.current?.show({ severity: 'error', summary: 'Error', detail: 'Failed to toggle game.' });
        }
    };

    useEffect(() => {
        fetch(`/api/v1/platform/tenants/${uuid}`)
            .then((res) => res.json())
            .then((res) => {
                setTenant(res.data);
                setLoading(false);
                if (res.data?.id) fetchAdmins(res.data.id);
            });
        fetchVenues();
        fetchAssignedGames();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [uuid]);

    const handleAddVenue = async () => {
        setSaving(true);
        try {
            const response = await fetch(`/api/v1/platform/tenants/${uuid}/venues`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    ...csrfHeader(),
                },
                body: JSON.stringify(formData),
            });
            const data = await response.json();
            if (data.success) {
                setAddVenueOpen(false);
                setFormData({ name: '', slug: '', address_line_1: '', city: '', country_code: 'NA', phone: '', email: '' });
                fetchVenues();
                toast.current?.show({ severity: 'success', summary: 'Created', detail: 'Venue created.' });
            } else {
                toast.current?.show({ severity: 'error', summary: 'Error', detail: data.message || 'Failed to create venue.' });
            }
        } catch (error) {
            console.error('Failed to create venue:', error);
            toast.current?.show({ severity: 'error', summary: 'Error', detail: 'Failed to create venue.' });
        } finally {
            setSaving(false);
        }
    };

    if (loading || !tenant) {
        return (
            <UserLayout title="Tenant">
                <Head title="Loading…" />
                <div className="cgo-page">
                    <div style={{ color: 'var(--cg-fg-3)', padding: '40px 0' }}>Loading tenant…</div>
                </div>
            </UserLayout>
        );
    }

    const activeGames = assignedGames.filter((g) => g.pivot.enabled).length;

    const companyItems: InfoItem[] = [
        { label: 'Slug', value: tenant.slug, mono: true, span: 6 },
        { label: 'Legal name', value: tenant.legal_name || '—', span: 6 },
        { label: 'Registration', value: tenant.registration_number || '—', mono: true, span: 6 },
        { label: 'License', value: tenant.license_number || '—', mono: true, span: 6 },
        { label: 'Contact email', value: tenant.contact_email, span: 6 },
        { label: 'Phone', value: tenant.contact_phone || '—', mono: true, span: 6 },
    ];

    const configItems: InfoItem[] = [
        { label: 'Country', value: tenant.country_code, mono: true, span: 4 },
        { label: 'Currency', value: tenant.currency, mono: true, span: 4 },
        { label: 'Timezone', value: tenant.timezone, mono: true, span: 4 },
        { label: 'Custom domain', value: tenant.domain || '—', mono: true, span: 12 },
        {
            label: 'Commercial terms',
            span: 12,
            value: editingCommercial ? (
                <div style={{ display: 'grid', gap: 8, paddingTop: 4 }}>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                        <span style={{ width: 110, fontSize: 11, color: 'var(--cg-fg-3)' }}>Business model</span>
                        <select
                            value={businessModelInput}
                            onChange={(e) => setBusinessModelInput(e.target.value as 'reseller' | 'direct')}
                            disabled={savingCommercial}
                            style={{
                                flex: 1,
                                background: 'var(--cg-ink-card)',
                                border: '1px solid var(--cg-rule-strong)',
                                borderRadius: 4,
                                color: 'var(--cg-fg-1)',
                                padding: '4px 8px',
                                fontSize: 12,
                            }}
                        >
                            <option value="reseller">Reseller (revenue share)</option>
                            <option value="direct">Direct (100% to platform)</option>
                        </select>
                    </div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                        <span style={{ width: 110, fontSize: 11, color: 'var(--cg-fg-3)' }}>Revenue share %</span>
                        <InputText
                            type="number"
                            value={revenueInput}
                            onChange={(e) => setRevenueInput(e.target.value)}
                            min={0} max={100} step={0.1}
                            disabled={savingCommercial || businessModelInput === 'direct'}
                            style={{ width: 100 }}
                        />
                    </div>
                    <div style={{ display: 'flex', alignItems: 'center', gap: 8 }}>
                        <span style={{ width: 110, fontSize: 11, color: 'var(--cg-fg-3)' }}>Gambling tax %</span>
                        <InputText
                            type="number"
                            value={taxInput}
                            onChange={(e) => setTaxInput(e.target.value)}
                            min={0} max={100} step={0.1}
                            disabled={savingCommercial}
                            style={{ width: 100 }}
                        />
                    </div>
                    <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8, paddingTop: 4 }}>
                        <button
                            type="button"
                            className="cg-btn cg-btn--text cg-btn--sm"
                            onClick={() => setEditingCommercial(false)}
                            disabled={savingCommercial}
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            className="cg-btn cg-btn--primary cg-btn--sm"
                            onClick={() => void handleSaveCommercial()}
                            disabled={savingCommercial}
                        >
                            {savingCommercial ? 'Saving…' : 'Save'}
                        </button>
                    </div>
                </div>
            ) : (
                <div style={{ display: 'flex', alignItems: 'center', gap: 16, flexWrap: 'wrap' }}>
                    <span className={`cgo-pill ${tenant.business_model === 'direct' ? 'settled' : 'live'}`}>
                        {tenant.business_model === 'direct' ? 'Direct' : 'Reseller'}
                    </span>
                    <span style={{ color: 'var(--cg-fg-3)' }}>
                        Revenue share:{' '}
                        <span style={{ color: 'var(--cg-fg-1)', fontFamily: 'var(--cg-mono)' }}>
                            {tenant.business_model === 'direct' ? 'n/a' : `${tenant.revenue_share_pct}%`}
                        </span>
                    </span>
                    <span style={{ color: 'var(--cg-fg-3)' }}>
                        Gambling tax:{' '}
                        <span style={{ color: 'var(--cg-fg-1)', fontFamily: 'var(--cg-mono)' }}>
                            {tenant.tax_pct ?? 0}%
                        </span>
                    </span>
                    <button
                        type="button"
                        className="cg-btn cg-btn--text cg-btn--sm"
                        style={{ marginLeft: 'auto' }}
                        onClick={handleStartEditCommercial}
                    >
                        Edit
                    </button>
                </div>
            ),
        },
    ];

    return (
        <UserLayout title={tenant.name}>
            <Head title={`${tenant.name} · Tenant`} />
            <Toast ref={toast} />

            <div className="cgo-page">
                {/* Page header */}
                <div className="cgo-page-head max-sm:flex-wrap">
                    <div>
                        <div className="cgo-eyebrow">Platform · Tenant</div>
                        <h1 className="cgo-title">{tenant.name}</h1>
                        <div className="cgo-subtitle" style={{ display: 'flex', alignItems: 'center', gap: 10, flexWrap: 'wrap' }}>
                            <span className="cgo-uid min-w-0 break-all" style={{ fontFamily: 'var(--cg-mono)' }}>
                                {tenant.slug}.sso.chingagames.com
                            </span>
                            <span className={`cgo-pill ${statusPill(tenant.status)}`}>
                                {tenant.status}
                            </span>
                        </div>
                    </div>
                    <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>
                        <button
                            type="button"
                            className="cg-btn cg-btn--ghost cg-btn--sm"
                            onClick={() => router.visit('/platform/tenants')}
                        >
                            ← Back
                        </button>
                        <button
                            type="button"
                            className="cg-btn cg-btn--ghost cg-btn--sm"
                            onClick={openManageGamesDialog}
                        >
                            Manage games
                        </button>
                        <button
                            type="button"
                            className="cg-btn cg-btn--primary cg-btn--sm"
                            onClick={() => setAddVenueOpen(true)}
                        >
                            + Add venue
                        </button>
                    </div>
                </div>

                {/* KPI strip */}
                <div className="cgo-kpis">
                    <KpiCard label="Players" value={formatCount(tenant.users_count)} meta="registered" />
                    <KpiCard label="Venues" value={formatCount(tenant.venues_count)} meta="branded" />
                    <KpiCard label="Voucher codes" value={formatCount(tenant.voucher_codes_count)} meta="lifetime issued" />
                    <KpiCard
                        label="Active games"
                        value={formatCount(activeGames)}
                        brass
                        meta={`of ${assignedGames.length} assigned`}
                    />
                </div>

                {/* Company + Configuration */}
                <div
                    className="grid grid-cols-1 lg:grid-cols-2"
                    style={{ gap: 16, marginBottom: 24 }}
                >
                    <InfoPanel title="Company" items={companyItems} />
                    <InfoPanel title="Configuration" items={configItems} />
                </div>

                {/* Operator integration (platform admins only: the whole page is) */}
                <IntegrationPanel
                    tenantUuid={uuid}
                    notify={(severity, summary, detail) => toast.current?.show({ severity, summary, detail })}
                />

                {/* Tenant admins */}
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
                        Tenant admins · {admins.length}
                    </div>
                    <button
                        type="button"
                        className="cg-btn cg-btn--primary cg-btn--sm"
                        onClick={() => setAddAdminOpen(true)}
                    >
                        + Add admin
                    </button>
                </div>
                <div
                    className="cgo-table-wrap"
                    style={{ borderRadius: '0 0 8px 8px', marginBottom: 24 }}
                >
                    <table className="cgo-wagers">
                        <thead>
                            <tr>
                                <th style={{ minWidth: 220 }}>Name</th>
                                <th style={{ minWidth: 220 }}>Email</th>
                                <th style={{ width: 100 }}>Status</th>
                                <th style={{ width: 180 }}>Last login</th>
                                <th style={{ width: 80 }} />
                            </tr>
                        </thead>
                        <tbody>
                            {adminsLoading ? (
                                <tr>
                                    <td colSpan={5} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>
                                        Loading…
                                    </td>
                                </tr>
                            ) : admins.length === 0 ? (
                                <tr>
                                    <td colSpan={5} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>
                                        No tenant admins yet. Click <strong style={{ color: 'var(--cg-fg-2)' }}>+ Add admin</strong> to create the first one.
                                    </td>
                                </tr>
                            ) : (
                                admins.map((a) => (
                                    <tr key={a.uuid}>
                                        <td>
                                            <div className="cgo-name">{a.name}</div>
                                        </td>
                                        <td>
                                            <span style={{ fontSize: 12, color: 'var(--cg-fg-2)' }}>
                                                {a.email}
                                            </span>
                                        </td>
                                        <td>
                                            <span className={`cgo-pill ${statusPill(a.status)}`}>
                                                {a.status}
                                            </span>
                                        </td>
                                        <td>
                                            <span className="cgo-uid">
                                                {a.last_login_at
                                                    ? new Intl.DateTimeFormat('en-GB', {
                                                          day: '2-digit', month: 'short', year: 'numeric',
                                                          hour: '2-digit', minute: '2-digit',
                                                      }).format(new Date(a.last_login_at))
                                                    : 'Never'}
                                            </span>
                                        </td>
                                        <td>
                                            <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
                                                <button
                                                    type="button"
                                                    className="cgo-row-action"
                                                    aria-label="Revoke tenant admin role"
                                                    title="Revoke tenant admin role"
                                                    style={{ color: 'var(--cg-neg)', borderColor: 'var(--cg-neg)' }}
                                                    onClick={() => handleRevokeAdmin(a)}
                                                >
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><circle cx="12" cy="12" r="10" /><line x1="4.93" y1="4.93" x2="19.07" y2="19.07" /></svg>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Assigned games */}
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
                        Assigned games · {assignedGames.length}
                    </div>
                </div>
                <div
                    className="cgo-table-wrap"
                    style={{ borderRadius: '0 0 8px 8px', marginBottom: 24 }}
                >
                    <table className="cgo-wagers">
                        <thead>
                            <tr>
                                <th style={{ minWidth: 220 }}>Game</th>
                                <th style={{ width: 100 }}>Status</th>
                                <th style={{ width: 100 }}>Enabled</th>
                            </tr>
                        </thead>
                        <tbody>
                            {gamesLoading ? (
                                <tr>
                                    <td colSpan={3} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>
                                        Loading…
                                    </td>
                                </tr>
                            ) : assignedGames.length === 0 ? (
                                <tr>
                                    <td colSpan={3} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>
                                        No games assigned. Click <strong style={{ color: 'var(--cg-fg-2)' }}>Manage games</strong> above.
                                    </td>
                                </tr>
                            ) : (
                                assignedGames.map((g) => (
                                    <tr key={g.uuid}>
                                        <td>
                                            <div className="cgo-name">{g.name}</div>
                                            <div className="cgo-uid">{g.type} · {g.slug}</div>
                                        </td>
                                        <td>
                                            <span className={`cgo-pill ${g.status === 'active' ? 'live' : 'void'}`}>
                                                {g.status}
                                            </span>
                                        </td>
                                        <td>
                                            <button
                                                type="button"
                                                role="switch"
                                                aria-checked={g.pivot.enabled}
                                                onClick={() => handleToggleEnabled(g)}
                                                style={{
                                                    position: 'relative',
                                                    display: 'inline-flex',
                                                    alignItems: 'center',
                                                    height: 22,
                                                    width: 40,
                                                    border: 'none',
                                                    borderRadius: 999,
                                                    cursor: 'pointer',
                                                    background: g.pivot.enabled ? 'var(--cg-brass)' : 'var(--cg-rule-strong)',
                                                    transition: 'background 120ms ease',
                                                    padding: 0,
                                                }}
                                            >
                                                <span
                                                    style={{
                                                        display: 'inline-block',
                                                        height: 16,
                                                        width: 16,
                                                        borderRadius: '50%',
                                                        background: '#fff',
                                                        boxShadow: '0 1px 2px rgba(0,0,0,0.25)',
                                                        transform: g.pivot.enabled ? 'translateX(21px)' : 'translateX(3px)',
                                                        transition: 'transform 120ms ease',
                                                    }}
                                                />
                                            </button>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Venues */}
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
                        Venues · {venues.length}
                    </div>
                </div>
                <div
                    className="cgo-table-wrap cgo-table-wrap--scroll"
                    style={{ borderRadius: '0 0 8px 8px', marginBottom: 32 }}
                >
                    <table className="cgo-wagers">
                        <thead>
                            <tr>
                                <th style={{ minWidth: 220 }}>Venue</th>
                                <th style={{ minWidth: 160 }}>Location</th>
                                <th style={{ width: 110 }}>Status</th>
                                <th className="cgo-r" style={{ width: 80 }}>Staff</th>
                                <th className="cgo-r" style={{ width: 100 }}>Terminals</th>
                                <th style={{ width: 80 }} />
                            </tr>
                        </thead>
                        <tbody>
                            {venuesLoading ? (
                                <tr>
                                    <td colSpan={6} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>
                                        Loading…
                                    </td>
                                </tr>
                            ) : venues.length === 0 ? (
                                <tr>
                                    <td colSpan={6} style={{ textAlign: 'center', color: 'var(--cg-fg-3)' }}>
                                        No venues yet. Click <strong style={{ color: 'var(--cg-fg-2)' }}>+ Add venue</strong> above.
                                    </td>
                                </tr>
                            ) : (
                                venues.map((v) => (
                                    <tr key={v.uuid}>
                                        <td>
                                            <div className="cgo-name">{v.name}</div>
                                            <div className="cgo-uid">{v.slug}</div>
                                        </td>
                                        <td>
                                            <span style={{ fontSize: 12, color: 'var(--cg-fg-2)' }}>
                                                {v.city}, {v.country_code}
                                            </span>
                                        </td>
                                        <td>
                                            <span className={`cgo-pill ${statusPill(v.status)}`}>
                                                {v.status}
                                            </span>
                                        </td>
                                        <td className="cgo-r">
                                            <span className="cgo-odds">{v.staff_count}</span>
                                        </td>
                                        <td className="cgo-r">
                                            <span className="cgo-odds">{v.terminals_count}</span>
                                        </td>
                                        <td>
                                            <div style={{ display: 'flex', justifyContent: 'flex-end' }}>
                                                <Link
                                                    href={`/platform/tenants/${uuid}/venues/${v.uuid}`}
                                                    className="cgo-row-action"
                                                    aria-label="View venue"
                                                    title="View venue"
                                                >
                                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8S1 12 1 12z" /><circle cx="12" cy="12" r="3" /></svg>
                                                </Link>
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </div>

            {/* Add venue dialog (PrimeReact) */}
            <Dialog
                header={`Add venue · ${tenant.name}`}
                visible={addVenueOpen}
                style={{ width: '28rem', maxWidth: 'calc(100vw - 2rem)' }}
                onHide={() => setAddVenueOpen(false)}
                modal
                draggable={false}
                footer={
                    <div className="flex justify-end gap-2">
                        <Button label="Cancel" severity="secondary" outlined onClick={() => setAddVenueOpen(false)} />
                        <Button
                            label={saving ? 'Creating…' : 'Create venue'}
                            onClick={handleAddVenue}
                            disabled={
                                saving ||
                                !formData.name ||
                                !formData.slug ||
                                !formData.address_line_1 ||
                                !formData.city ||
                                !formData.country_code
                            }
                            loading={saving}
                        />
                    </div>
                }
            >
                <div className="space-y-4">
                    <div className="flex flex-col gap-1">
                        <label htmlFor="name" style={{ fontSize: 12, fontWeight: 500 }}>Venue name *</label>
                        <InputText
                            id="name"
                            value={formData.name}
                            onChange={(e) => {
                                const name = e.target.value;
                                setFormData({ ...formData, name, slug: formData.slug || generateSlug(name) });
                            }}
                            placeholder="e.g. Casino Windhoek"
                            className="w-full"
                        />
                    </div>
                    <div className="flex flex-col gap-1">
                        <label htmlFor="slug" style={{ fontSize: 12, fontWeight: 500 }}>Slug *</label>
                        <InputText
                            id="slug"
                            value={formData.slug}
                            onChange={(e) => setFormData({ ...formData, slug: e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '') })}
                            placeholder="e.g. casino-windhoek"
                            className="w-full"
                        />
                    </div>
                    <div className="flex flex-col gap-1">
                        <label htmlFor="address" style={{ fontSize: 12, fontWeight: 500 }}>Address *</label>
                        <InputText
                            id="address"
                            value={formData.address_line_1}
                            onChange={(e) => setFormData({ ...formData, address_line_1: e.target.value })}
                            placeholder="Street address"
                            className="w-full"
                        />
                    </div>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div className="flex flex-col gap-1">
                            <label htmlFor="city" style={{ fontSize: 12, fontWeight: 500 }}>City *</label>
                            <InputText
                                id="city"
                                value={formData.city}
                                onChange={(e) => setFormData({ ...formData, city: e.target.value })}
                                placeholder="Windhoek"
                                className="w-full"
                            />
                        </div>
                        <div className="flex flex-col gap-1">
                            <label htmlFor="country" style={{ fontSize: 12, fontWeight: 500 }}>Country *</label>
                            <InputText
                                id="country"
                                value={formData.country_code}
                                onChange={(e) => setFormData({ ...formData, country_code: e.target.value.toUpperCase().slice(0, 2) })}
                                placeholder="NA"
                                maxLength={2}
                                className="w-full"
                            />
                        </div>
                    </div>
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div className="flex flex-col gap-1">
                            <label htmlFor="phone" style={{ fontSize: 12, fontWeight: 500 }}>Phone</label>
                            <InputText
                                id="phone"
                                value={formData.phone}
                                onChange={(e) => setFormData({ ...formData, phone: e.target.value })}
                                placeholder="+264 61 …"
                                className="w-full"
                            />
                        </div>
                        <div className="flex flex-col gap-1">
                            <label htmlFor="email" style={{ fontSize: 12, fontWeight: 500 }}>Email</label>
                            <InputText
                                id="email"
                                type="email"
                                value={formData.email}
                                onChange={(e) => setFormData({ ...formData, email: e.target.value })}
                                placeholder="venue@example.com"
                                className="w-full"
                            />
                        </div>
                    </div>
                </div>
            </Dialog>

            {/* Add tenant admin dialog */}
            <Dialog
                header={`Add tenant admin · ${tenant.name}`}
                visible={addAdminOpen}
                style={{ width: '28rem', maxWidth: 'calc(100vw - 2rem)' }}
                onHide={() => setAddAdminOpen(false)}
                modal
                draggable={false}
                footer={
                    <div className="flex justify-end gap-2">
                        <Button
                            label="Cancel"
                            severity="secondary"
                            outlined
                            onClick={() => setAddAdminOpen(false)}
                        />
                        <Button
                            label={savingAdmin ? 'Adding…' : 'Add admin'}
                            onClick={handleCreateAdmin}
                            disabled={
                                savingAdmin || !adminForm.name.trim() ||
                                !adminForm.email.trim() || adminForm.password.length < 8
                            }
                            loading={savingAdmin}
                        />
                    </div>
                }
            >
                <p style={{ fontSize: 12, color: 'var(--cg-fg-3)', marginBottom: 16 }}>
                    Creates a new user attached to <strong style={{ color: 'var(--cg-fg-2)' }}>{tenant.name}</strong> and grants them the
                    {' '}<code style={{ fontFamily: 'var(--cg-mono)', color: 'var(--cg-brass-hi)' }}>tenant_admin</code> role.
                    They'll be able to sign in at <code style={{ fontFamily: 'var(--cg-mono)' }}>/login</code> immediately.
                </p>
                <div className="space-y-4">
                    <div className="flex flex-col gap-1">
                        <label style={{ fontSize: 12, fontWeight: 500 }}>Name *</label>
                        <InputText
                            value={adminForm.name}
                            onChange={(e) => setAdminForm({ ...adminForm, name: e.target.value })}
                            placeholder="e.g. Jane Doe"
                            className="w-full"
                            autoFocus
                        />
                    </div>
                    <div className="flex flex-col gap-1">
                        <label style={{ fontSize: 12, fontWeight: 500 }}>Email *</label>
                        <InputText
                            type="email"
                            value={adminForm.email}
                            onChange={(e) => setAdminForm({ ...adminForm, email: e.target.value })}
                            placeholder="jane@example.com"
                            className="w-full"
                        />
                    </div>
                    <div className="flex flex-col gap-1">
                        <label style={{ fontSize: 12, fontWeight: 500 }}>Initial password *</label>
                        <InputText
                            type="password"
                            value={adminForm.password}
                            onChange={(e) => setAdminForm({ ...adminForm, password: e.target.value })}
                            placeholder="Min 8 characters"
                            className="w-full"
                        />
                        <small style={{ fontSize: 11, color: 'var(--cg-fg-3)', marginTop: 2 }}>
                            Share this with them out-of-band; they can change it after first login.
                        </small>
                    </div>
                </div>
            </Dialog>

            {/* Manage games dialog */}
            <Dialog
                header="Manage game assignments"
                visible={manageGamesOpen}
                style={{ width: '32rem', maxWidth: 'calc(100vw - 2rem)' }}
                onHide={() => setManageGamesOpen(false)}
                modal
                draggable={false}
                footer={
                    <div className="flex justify-end gap-2">
                        <Button label="Cancel" severity="secondary" outlined onClick={() => setManageGamesOpen(false)} />
                        <Button
                            label={syncing ? 'Saving…' : 'Save assignments'}
                            onClick={handleSyncGames}
                            disabled={syncing}
                            loading={syncing}
                        />
                    </div>
                }
            >
                {dialogLoading ? (
                    <div style={{ textAlign: 'center', padding: 24, color: 'var(--cg-fg-3)' }}>Loading…</div>
                ) : (
                    <div className="space-y-2">
                        <p style={{ fontSize: 12, color: 'var(--cg-fg-3)', marginBottom: 8 }}>
                            Select games to make available for {tenant.name}. Unchecked games will be removed.
                        </p>
                        {[...allGames.assigned, ...allGames.available].map((game) => {
                            const isSelected = selectedGameUuids.includes(game.uuid);
                            return (
                                <Fragment key={game.uuid}>
                                    <label
                                        htmlFor={`game-${game.uuid}`}
                                        className="flex items-start gap-3 p-3 rounded-lg cursor-pointer"
                                        style={{
                                            border: `1px solid ${isSelected ? 'var(--cg-brass)' : 'var(--cg-rule)'}`,
                                            background: isSelected ? 'var(--cg-brass-wash)' : 'transparent',
                                        }}
                                    >
                                        <input
                                            id={`game-${game.uuid}`}
                                            type="checkbox"
                                            checked={isSelected}
                                            onChange={() => {
                                                setSelectedGameUuids((prev) =>
                                                    prev.includes(game.uuid)
                                                        ? prev.filter((u) => u !== game.uuid)
                                                        : [...prev, game.uuid]
                                                );
                                            }}
                                            style={{ marginTop: 4, accentColor: 'var(--cg-brass)' }}
                                        />
                                        <div style={{ flex: 1 }}>
                                            <div style={{ fontSize: 13, fontWeight: 500 }}>{game.name}</div>
                                            <div className="cgo-uid">{game.type} · {game.slug}</div>
                                        </div>
                                    </label>
                                </Fragment>
                            );
                        })}
                        {allGames.assigned.length === 0 && allGames.available.length === 0 && (
                            <p style={{ fontSize: 12, color: 'var(--cg-fg-3)', textAlign: 'center', padding: 16 }}>
                                No games available in the platform.
                            </p>
                        )}
                    </div>
                )}
            </Dialog>
        </UserLayout>
    );
}
