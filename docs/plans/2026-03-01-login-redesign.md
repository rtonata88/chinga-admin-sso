# Login Page Premium Redesign - Implementation Plan

> **For Claude:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Redesign the login page from a plain centered fieldset to a premium, immersive gaming login with a dark gradient background and glass-effect centered card.

**Architecture:** Create a new `AuthPremiumLayout` component that wraps auth pages with a dark gradient background + radial glow + centered glass card. Update the `AuthLayout` bridge to use it. Adjust the login form for premium styling (gradient button, inline remember/forgot row). Keep all existing form logic and tenant branding support.

**Tech Stack:** React, Tailwind CSS v4, Inertia.js, existing shadcn/ui components (Button, Input, Checkbox, Label)

---

### Task 1: Add premium auth CSS custom properties

**Files:**
- Modify: `resources/css/app.css`

**Step 1: Add premium auth variables to the Acumatica section**

Add these CSS custom properties after the existing `--acu-sidebar-collapsed` line (line 176) but before the closing `}` of the `:root` block:

```css
/* ================================================================ */
/* Premium Auth Layout                                                */
/* ================================================================ */

:root {
    --auth-bg-from: #0a0e27;
    --auth-bg-to: #131a3d;
    --auth-glow-color: rgba(37, 99, 235, 0.15);
    --auth-glow-color-strong: rgba(37, 99, 235, 0.25);
    --auth-accent: #2563EB;
    --auth-accent-hover: #3b82f6;
}
```

**Step 2: Verify the dev server compiles without errors**

Run: `cd /Users/richard/Projects/chinga-games-sso && npx vite build 2>&1 | tail -5`
Expected: Build completes without CSS errors

**Step 3: Commit**

```bash
git add resources/css/app.css
git commit -m "style: add CSS custom properties for premium auth layout"
```

---

### Task 2: Create the AuthPremiumLayout component

**Files:**
- Create: `resources/js/layouts/auth/auth-premium-layout.tsx`

**Step 1: Create the premium layout file**

```tsx
import AppLogoIcon from '@/components/app-logo-icon';
import { home } from '@/routes';
import { Link, usePage } from '@inertiajs/react';
import { type CSSProperties, type PropsWithChildren } from 'react';

interface TenantBranding {
    primary_color?: string;
    secondary_color?: string;
}

interface TenantData {
    uuid: string;
    name: string;
    slug: string;
    logo_url: string | null;
    branding: TenantBranding | null;
}

interface AuthPremiumLayoutProps {
    title?: string;
    description?: string;
}

export default function AuthPremiumLayout({
    children,
    title,
    description,
}: PropsWithChildren<AuthPremiumLayoutProps>) {
    const { tenant } = usePage<{ tenant: TenantData | null }>().props;

    const tenantColor = tenant?.branding?.primary_color;

    const bgStyle: CSSProperties = {
        ...(tenantColor
            ? {
                  '--auth-glow-color': hexToRgba(tenantColor, 0.15),
                  '--auth-glow-color-strong': hexToRgba(tenantColor, 0.25),
                  '--auth-accent': tenantColor,
              } as CSSProperties
            : {}),
    };

    return (
        <div
            className="relative flex min-h-svh flex-col items-center justify-center overflow-hidden p-4 sm:p-6 md:p-10"
            style={bgStyle}
        >
            {/* Dark gradient background */}
            <div className="fixed inset-0 bg-gradient-to-br from-[var(--auth-bg-from)] to-[var(--auth-bg-to)]" />

            {/* Subtle dot pattern overlay */}
            <div
                className="fixed inset-0 opacity-[0.03]"
                style={{
                    backgroundImage:
                        'radial-gradient(circle, rgba(255,255,255,0.8) 1px, transparent 1px)',
                    backgroundSize: '24px 24px',
                }}
            />

            {/* Radial glow behind card */}
            <div className="fixed left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 h-[600px] w-[600px] rounded-full bg-[var(--auth-glow-color)] blur-[120px]" />

            {/* Card */}
            <div className="relative z-10 w-full max-w-md">
                <div className="rounded-2xl border border-white/10 bg-white/95 px-8 py-10 shadow-2xl backdrop-blur-xl dark:bg-slate-900/90">
                    {/* Logo + branding */}
                    <div className="flex flex-col items-center gap-4">
                        <Link
                            href={home()}
                            className="flex flex-col items-center gap-2 font-medium"
                        >
                            {tenant?.logo_url ? (
                                <img
                                    src={tenant.logo_url}
                                    alt={tenant.name}
                                    className="h-12 w-auto object-contain"
                                />
                            ) : (
                                <div className="flex h-12 w-12 items-center justify-center">
                                    <AppLogoIcon className="size-12 fill-[var(--auth-accent)]" />
                                </div>
                            )}
                            {tenant && (
                                <span className="text-sm font-semibold text-slate-800 dark:text-slate-200">
                                    {tenant.name}
                                </span>
                            )}
                            <span className="sr-only">{title}</span>
                        </Link>

                        <div className="space-y-1.5 text-center">
                            <h1 className="text-xl font-semibold text-slate-900 dark:text-white">
                                {title}
                            </h1>
                            <p className="text-sm text-slate-500 dark:text-slate-400">
                                {description}
                            </p>
                        </div>
                    </div>

                    {/* Form content */}
                    <div className="mt-8">{children}</div>
                </div>

                {/* Powered by footer (tenant context only) */}
                {tenant && (
                    <div className="mt-4 text-center text-xs text-slate-400/60">
                        Powered by Chinga Games
                    </div>
                )}
            </div>
        </div>
    );
}

/**
 * Convert a hex color to rgba string.
 */
function hexToRgba(hex: string, alpha: number): string {
    const cleanHex = hex.replace('#', '');
    const r = parseInt(cleanHex.substring(0, 2), 16);
    const g = parseInt(cleanHex.substring(2, 4), 16);
    const b = parseInt(cleanHex.substring(4, 6), 16);
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}
```

**Step 2: Verify TypeScript compiles**

Run: `cd /Users/richard/Projects/chinga-games-sso && npx tsc --noEmit 2>&1 | head -20`
Expected: No errors related to `auth-premium-layout.tsx`

**Step 3: Commit**

```bash
git add resources/js/layouts/auth/auth-premium-layout.tsx
git commit -m "feat: create AuthPremiumLayout component with dark gradient and glass card"
```

---

### Task 3: Switch AuthLayout to use premium layout

**Files:**
- Modify: `resources/js/layouts/auth-layout.tsx`

**Step 1: Update the import and component**

Replace the entire contents of `auth-layout.tsx`:

```tsx
import AuthLayoutTemplate from '@/layouts/auth/auth-premium-layout';

export default function AuthLayout({
    children,
    title,
    description,
    ...props
}: {
    children: React.ReactNode;
    title: string;
    description: string;
}) {
    return (
        <AuthLayoutTemplate title={title} description={description} {...props}>
            {children}
        </AuthLayoutTemplate>
    );
}
```

**Step 2: Verify TypeScript compiles**

Run: `cd /Users/richard/Projects/chinga-games-sso && npx tsc --noEmit 2>&1 | head -20`
Expected: No errors

**Step 3: Commit**

```bash
git add resources/js/layouts/auth-layout.tsx
git commit -m "feat: switch auth layout bridge to premium layout"
```

---

### Task 4: Update login form with premium styling

**Files:**
- Modify: `resources/js/pages/auth/login.tsx`

**Step 1: Update the login page component**

Replace the entire contents of `login.tsx`:

```tsx
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import AuthLayout from '@/layouts/auth-layout';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import { Form, Head } from '@inertiajs/react';

interface LoginProps {
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
}

export default function Login({
    status,
    canResetPassword,
    canRegister,
}: LoginProps) {
    return (
        <AuthLayout
            title="Log in to your account"
            description="Enter your credentials below"
        >
            <Head title="Log in" />

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label htmlFor="email">Email address</Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="email"
                                    placeholder="email@example.com"
                                    className="h-10"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password">Password</Label>
                                <Input
                                    id="password"
                                    type="password"
                                    name="password"
                                    required
                                    tabIndex={2}
                                    autoComplete="current-password"
                                    placeholder="Password"
                                    className="h-10"
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="flex items-center justify-between">
                                <div className="flex items-center space-x-3">
                                    <Checkbox
                                        id="remember"
                                        name="remember"
                                        tabIndex={3}
                                    />
                                    <Label htmlFor="remember">Remember me</Label>
                                </div>
                                {canResetPassword && (
                                    <TextLink
                                        href={request()}
                                        className="text-sm text-slate-500 hover:text-slate-700 dark:text-slate-400 dark:hover:text-slate-200"
                                        tabIndex={5}
                                    >
                                        Forgot password?
                                    </TextLink>
                                )}
                            </div>

                            <Button
                                type="submit"
                                className="mt-2 h-10 w-full bg-[var(--auth-accent)] text-white shadow-lg shadow-[var(--auth-glow-color-strong)] hover:bg-[var(--auth-accent-hover)] hover:shadow-xl hover:shadow-[var(--auth-glow-color-strong)]"
                                tabIndex={4}
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                Log in
                            </Button>
                        </div>

                        {canRegister && (
                            <div className="text-center text-sm text-slate-500 dark:text-slate-400">
                                Don't have an account?{' '}
                                <TextLink
                                    href={register()}
                                    className="font-medium text-[var(--auth-accent)] decoration-[var(--auth-accent)]/30 hover:decoration-[var(--auth-accent)]"
                                    tabIndex={5}
                                >
                                    Sign up
                                </TextLink>
                            </div>
                        )}
                    </>
                )}
            </Form>

            {status && (
                <div className="mt-4 text-center text-sm font-medium text-emerald-600">
                    {status}
                </div>
            )}
        </AuthLayout>
    );
}
```

Key changes from original:
- Description text shortened to "Enter your credentials below"
- Inputs use `h-10` for slightly taller touch targets
- "Forgot password?" link moved to same row as "Remember me" (flexbox justify-between)
- Password label stands alone (no more inline forgot link)
- Button uses gradient accent color with glow shadow
- Sign-up link uses accent color
- Status message uses emerald-600 instead of green-600
- All `tabIndex`, `data-test`, `resetOnSuccess` preserved

**Step 2: Verify TypeScript compiles**

Run: `cd /Users/richard/Projects/chinga-games-sso && npx tsc --noEmit 2>&1 | head -20`
Expected: No errors

**Step 3: Commit**

```bash
git add resources/js/pages/auth/login.tsx
git commit -m "feat: restyle login form with premium button, inline remember/forgot row"
```

---

### Task 5: Visual verification and polish

**Files:**
- Possibly modify: `resources/js/layouts/auth/auth-premium-layout.tsx`
- Possibly modify: `resources/js/pages/auth/login.tsx`

**Step 1: Start dev server and verify in browser**

Run: `cd /Users/richard/Projects/chinga-games-sso && npm run dev`

Open: `http://chinga-games-sso.test/login`

**Verify these items:**
- [ ] Dark gradient background covers full viewport
- [ ] Subtle dot pattern visible on close inspection
- [ ] Blue radial glow visible behind card
- [ ] Card has glass-like effect with slight transparency
- [ ] Card border is subtle white/10
- [ ] Logo renders at correct size (h-12)
- [ ] Title and subtitle text centered and legible
- [ ] Input fields are h-10, properly styled with focus rings
- [ ] Remember me checkbox and Forgot password link are on same row
- [ ] Login button is blue with glow shadow, darker on hover
- [ ] Sign up link uses accent blue color
- [ ] Mobile responsive: card fills width on small screens
- [ ] No horizontal scrollbar at any viewport width
- [ ] Form submits correctly (test with valid credentials)
- [ ] Form validation errors display correctly (submit empty form)
- [ ] Loading spinner appears on submit

**Step 2: Fix any visual issues found**

Apply fixes directly to the relevant files.

**Step 3: Final commit if any polish was needed**

```bash
git add -A
git commit -m "fix: polish premium login layout after visual review"
```

---

### Task 6: Verify other auth pages still work

**Step 1: Check each auth page in browser**

Navigate to each and confirm they render correctly with the new premium layout:
- `http://chinga-games-sso.test/register`
- `http://chinga-games-sso.test/forgot-password`

These pages also use `AuthLayout`, so they'll automatically get the premium layout.

**Step 2: Fix any issues with other auth pages**

If any page looks broken, fix it. The form content should work as-is since we only changed the wrapper layout.

**Step 3: Commit if changes were needed**

```bash
git add -A
git commit -m "fix: ensure all auth pages work with premium layout"
```
