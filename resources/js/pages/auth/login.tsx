// resources/js/pages/auth/login.tsx
//
// Sign-in form, brass-on-ink. Uses the auth-premium-layout shell
// (which carries the tenant logo + dot-pattern background) and
// renders the form with native inputs styled in brass tokens. The
// shadcn UI primitives are deliberately not used here so the form
// blends with the operator console aesthetic.

import AuthLayout from '@/layouts/auth-layout';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import { Form, Head, Link } from '@inertiajs/react';

interface LoginProps {
    status?: string;
    canResetPassword: boolean;
}

export default function Login({ status, canResetPassword }: LoginProps) {
    return (
        <AuthLayout title="Sign in" description="Enter your credentials to continue">
            <Head title="Log in" />

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                style={{ display: 'flex', flexDirection: 'column', gap: 18 }}
            >
                {({ processing, errors }) => (
                    <>
                        {/* Email */}
                        <div>
                            <label
                                htmlFor="email"
                                style={{
                                    display: 'block',
                                    fontSize: 10,
                                    textTransform: 'uppercase',
                                    letterSpacing: '0.18em',
                                    color: 'var(--cg-fg-3)',
                                    fontWeight: 600,
                                    marginBottom: 6,
                                }}
                            >
                                Email
                            </label>
                            <input
                                id="email"
                                type="email"
                                name="email"
                                required
                                autoFocus
                                tabIndex={1}
                                autoComplete="email"
                                placeholder="you@example.com"
                                className="cgo-auth-field"
                            />
                            {errors.email && (
                                <div className="cgo-auth-error">{errors.email}</div>
                            )}
                        </div>

                        {/* Password */}
                        <div>
                            <label
                                htmlFor="password"
                                style={{
                                    display: 'block',
                                    fontSize: 10,
                                    textTransform: 'uppercase',
                                    letterSpacing: '0.18em',
                                    color: 'var(--cg-fg-3)',
                                    fontWeight: 600,
                                    marginBottom: 6,
                                }}
                            >
                                Password
                            </label>
                            <input
                                id="password"
                                type="password"
                                name="password"
                                required
                                tabIndex={2}
                                autoComplete="current-password"
                                placeholder="••••••••"
                                className="cgo-auth-field"
                            />
                            {errors.password && (
                                <div className="cgo-auth-error">{errors.password}</div>
                            )}
                        </div>

                        {/* Remember + forgot */}
                        <div
                            style={{
                                display: 'flex',
                                alignItems: 'center',
                                justifyContent: 'space-between',
                                gap: 12,
                            }}
                        >
                            <label
                                style={{
                                    display: 'inline-flex',
                                    alignItems: 'center',
                                    gap: 8,
                                    fontSize: 13,
                                    color: 'var(--cg-fg-2)',
                                    cursor: 'pointer',
                                    userSelect: 'none',
                                }}
                            >
                                <input
                                    type="checkbox"
                                    name="remember"
                                    tabIndex={3}
                                    style={{
                                        width: 14,
                                        height: 14,
                                        accentColor: 'var(--cg-brass)',
                                        cursor: 'pointer',
                                    }}
                                />
                                Remember me
                            </label>
                            {canResetPassword && (
                                <Link
                                    href={request()}
                                    style={{
                                        fontSize: 13,
                                        color: 'var(--cg-fg-3)',
                                        textDecoration: 'none',
                                    }}
                                    tabIndex={5}
                                    onMouseOver={(e) => { e.currentTarget.style.color = 'var(--cg-brass-hi)'; }}
                                    onMouseOut={(e) => { e.currentTarget.style.color = 'var(--cg-fg-3)'; }}
                                >
                                    Forgot password?
                                </Link>
                            )}
                        </div>

                        {/* Submit */}
                        <button
                            type="submit"
                            tabIndex={4}
                            disabled={processing}
                            data-test="login-button"
                            className="cg-btn cg-btn--primary"
                            style={{
                                width: '100%',
                                justifyContent: 'center',
                                marginTop: 4,
                                opacity: processing ? 0.7 : 1,
                                cursor: processing ? 'wait' : 'pointer',
                            }}
                        >
                            {processing ? 'Signing in…' : 'Sign in'}
                        </button>

                        {/* Status / register link */}
                        {status && (
                            <div
                                style={{
                                    fontSize: 12,
                                    color: 'var(--cg-pos)',
                                    textAlign: 'center',
                                }}
                            >
                                {status}
                            </div>
                        )}

                    </>
                )}
            </Form>

            {/* Local field styling — scoped via the .cgo-auth-field class
                so it doesn't bleed into other forms. */}
            <style>{`
                .cgo-auth-field {
                    width: 100%;
                    height: 40px;
                    padding: 0 12px;
                    background: var(--cg-ink-elevated);
                    border: 1px solid var(--cg-rule-strong);
                    border-radius: 6px;
                    color: var(--cg-fg-1);
                    font-family: var(--cg-body);
                    font-size: 14px;
                    transition: border-color 120ms ease, box-shadow 120ms ease;
                    outline: none;
                }
                .cgo-auth-field::placeholder { color: var(--cg-fg-4); }
                .cgo-auth-field:focus {
                    border-color: var(--cg-brass);
                    box-shadow: 0 0 0 3px var(--cg-brass-wash);
                }
                .cgo-auth-field:autofill,
                .cgo-auth-field:-webkit-autofill {
                    -webkit-text-fill-color: var(--cg-fg-1);
                    -webkit-box-shadow: 0 0 0 1000px var(--cg-ink-elevated) inset;
                    box-shadow: 0 0 0 1000px var(--cg-ink-elevated) inset;
                    caret-color: var(--cg-fg-1);
                }
                .cgo-auth-error {
                    margin-top: 6px;
                    font-size: 12px;
                    color: var(--cg-neg);
                }
            `}</style>
        </AuthLayout>
    );
}
