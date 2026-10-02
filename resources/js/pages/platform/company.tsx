// resources/js/pages/platform/company.tsx
//
// The platform's own company details: legal identity, address, contact,
// bank account and payment terms. One row; everything here is printed on
// the tenant invoices (From block, Pay to block, terms line).

import UserLayout from '@/layouts/user-layout';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Toast } from 'primereact/toast';
import { type FormEvent, useEffect, useRef } from 'react';

interface Profile {
    legal_name: string | null;
    trading_name: string | null;
    registration_number: string | null;
    vat_number: string | null;
    address_line1: string | null;
    address_line2: string | null;
    city: string | null;
    country: string | null;
    email: string | null;
    phone: string | null;
    bank_name: string | null;
    bank_account_name: string | null;
    bank_account_number: string | null;
    bank_branch_code: string | null;
    payment_terms_days: number;
    updated_at: string | null;
}

interface Props {
    profile: Profile;
}

type FieldKey = Exclude<keyof Profile, 'updated_at' | 'payment_terms_days'>;

const SECTIONS: { title: string; hint: string; fields: { key: FieldKey; label: string; hint?: string; wide?: boolean }[] }[] = [
    {
        title: 'Identity',
        hint: 'Printed in the From block of every invoice.',
        fields: [
            { key: 'legal_name', label: 'Legal name', hint: 'As registered' },
            { key: 'trading_name', label: 'Trading name', hint: 'Shown as the invoice brand if different' },
            { key: 'registration_number', label: 'Registration number' },
            { key: 'vat_number', label: 'VAT number' },
        ],
    },
    {
        title: 'Address and contact',
        hint: 'Address lines print under the name; the email is the queries address in the terms.',
        fields: [
            { key: 'address_line1', label: 'Address line 1', wide: true },
            { key: 'address_line2', label: 'Address line 2', wide: true },
            { key: 'city', label: 'City' },
            { key: 'country', label: 'Country' },
            { key: 'email', label: 'Email' },
            { key: 'phone', label: 'Phone' },
        ],
    },
    {
        title: 'Bank account',
        hint: 'Printed as the Pay to block above the terms. Leave empty to print no bank details.',
        fields: [
            { key: 'bank_name', label: 'Bank' },
            { key: 'bank_account_name', label: 'Account name' },
            { key: 'bank_account_number', label: 'Account number' },
            { key: 'bank_branch_code', label: 'Branch code' },
        ],
    },
];

export default function Company({ profile }: Props) {
    const toast = useRef<Toast>(null);
    const { flash } = usePage<{ flash: { success?: string; error?: string } }>().props;
    useEffect(() => {
        if (flash?.success) toast.current?.show({ severity: 'success', summary: 'Saved', detail: flash.success });
        if (flash?.error) toast.current?.show({ severity: 'error', summary: 'Error', detail: flash.error });
    }, [flash]);

    const form = useForm({
        legal_name: profile.legal_name ?? '',
        trading_name: profile.trading_name ?? '',
        registration_number: profile.registration_number ?? '',
        vat_number: profile.vat_number ?? '',
        address_line1: profile.address_line1 ?? '',
        address_line2: profile.address_line2 ?? '',
        city: profile.city ?? '',
        country: profile.country ?? '',
        email: profile.email ?? '',
        phone: profile.phone ?? '',
        bank_name: profile.bank_name ?? '',
        bank_account_name: profile.bank_account_name ?? '',
        bank_account_number: profile.bank_account_number ?? '',
        bank_branch_code: profile.bank_branch_code ?? '',
        payment_terms_days: String(profile.payment_terms_days ?? 14),
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put('/platform/company', { preserveScroll: true });
    };

    return (
        <UserLayout title="Company">
            <Head title="Company · Admin" />
            <Toast ref={toast} />
            <div className="cgo-page">
                <div className="cgo-page-head">
                    <div>
                        <div className="cgo-eyebrow">Platform</div>
                        <h1 className="cgo-title">Company</h1>
                        <div className="cgo-subtitle">
                            Your company details as printed on tenant invoices.
                            {profile.updated_at ? ` Last saved ${new Date(profile.updated_at).toLocaleString()}.` : ' Not filled in yet: invoices print the platform defaults.'}
                        </div>
                    </div>
                    <div style={{ display: 'flex', gap: 8 }}>
                        <a href="/invoices" className="cg-btn cg-btn--ghost cg-btn--sm">Invoices</a>
                        <button type="submit" form="company-form" className="cg-btn cg-btn--primary cg-btn--sm" disabled={form.processing}>
                            {form.processing ? 'Saving…' : 'Save'}
                        </button>
                    </div>
                </div>

                <form id="company-form" onSubmit={submit} style={{ display: 'grid', gap: 16 }}>
                    {SECTIONS.map((section) => (
                        <div key={section.title} style={{ border: '1px solid var(--cg-rule)', borderRadius: 8, background: 'var(--cg-ink-card)' }}>
                            <div className="cgo-table-bar">
                                <div className="cgo-table-bar-title">{section.title}</div>
                            </div>
                            <div style={{ padding: 20 }}>
                                <div style={{ fontSize: 12, color: 'var(--cg-fg-3)', marginBottom: 16 }}>{section.hint}</div>
                                <div className="cgo-field-grid">
                                    {section.fields.map((f) => (
                                        <div key={f.key} className="cgo-field" style={f.wide ? { gridColumn: '1 / -1' } : undefined}>
                                            <div className="cgo-field-label" style={{ marginBottom: 6 }}>
                                                <label htmlFor={`company-${f.key}`} style={{ fontSize: 10, textTransform: 'uppercase', letterSpacing: '0.16em', color: 'var(--cg-fg-3)', fontWeight: 600 }}>
                                                    {f.label}
                                                </label>
                                                {f.hint && <div style={{ fontSize: 11, color: 'var(--cg-fg-3)', marginTop: 2 }}>{f.hint}</div>}
                                            </div>
                                            <span className="cgo-input">
                                                <input
                                                    id={`company-${f.key}`}
                                                    type={f.key === 'email' ? 'email' : 'text'}
                                                    value={form.data[f.key]}
                                                    onChange={(e) => form.setData(f.key, e.target.value)}
                                                />
                                            </span>
                                            {form.errors[f.key] && <div style={{ fontSize: 11, color: 'var(--cg-neg)', marginTop: 4 }}>{form.errors[f.key]}</div>}
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>
                    ))}

                    <div style={{ border: '1px solid var(--cg-rule)', borderRadius: 8, background: 'var(--cg-ink-card)' }}>
                        <div className="cgo-table-bar">
                            <div className="cgo-table-bar-title">Payment terms</div>
                        </div>
                        <div style={{ padding: 20 }}>
                            <div className="cgo-field-grid">
                                <div className="cgo-field">
                                    <div className="cgo-field-label" style={{ marginBottom: 6 }}>
                                        <label htmlFor="company-terms" style={{ fontSize: 10, textTransform: 'uppercase', letterSpacing: '0.16em', color: 'var(--cg-fg-3)', fontWeight: 600 }}>
                                            Days to pay
                                        </label>
                                        <div style={{ fontSize: 11, color: 'var(--cg-fg-3)', marginTop: 2 }}>Sets the due date of every invoice issued from now on.</div>
                                    </div>
                                    <span className="cgo-input">
                                        <input
                                            id="company-terms"
                                            type="number"
                                            min={0}
                                            max={365}
                                            value={form.data.payment_terms_days}
                                            onChange={(e) => form.setData('payment_terms_days', e.target.value)}
                                        />
                                    </span>
                                    {form.errors.payment_terms_days && <div style={{ fontSize: 11, color: 'var(--cg-neg)', marginTop: 4 }}>{form.errors.payment_terms_days}</div>}
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </UserLayout>
    );
}
