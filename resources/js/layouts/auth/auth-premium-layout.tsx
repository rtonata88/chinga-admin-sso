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
            <div className="absolute inset-0 bg-gradient-to-br from-[var(--auth-bg-from)] to-[var(--auth-bg-to)]" />

            {/* Subtle dot pattern overlay */}
            <div
                className="absolute inset-0 opacity-[0.03]"
                style={{
                    backgroundImage:
                        'radial-gradient(circle, rgba(255,255,255,0.8) 1px, transparent 1px)',
                    backgroundSize: '24px 24px',
                }}
            />

            {/* Radial glow behind card */}
            <div className="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 h-[600px] w-[600px] rounded-full bg-[var(--auth-glow-color)] blur-[120px]" />

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
 * Handles both 3-char (#abc) and 6-char (#aabbcc) hex values.
 * Falls back to default blue accent for invalid input.
 */
function hexToRgba(hex: string, alpha: number): string {
    const cleanHex = hex.replace('#', '');

    const fullHex =
        cleanHex.length === 3
            ? cleanHex
                  .split('')
                  .map((c) => c + c)
                  .join('')
            : cleanHex;

    if (!/^[0-9a-fA-F]{6}$/.test(fullHex)) {
        return `rgba(37, 99, 235, ${alpha})`;
    }

    const r = parseInt(fullHex.substring(0, 2), 16);
    const g = parseInt(fullHex.substring(2, 4), 16);
    const b = parseInt(fullHex.substring(4, 6), 16);
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}
