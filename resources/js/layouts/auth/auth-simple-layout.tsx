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

interface AuthLayoutProps {
    name?: string;
    title?: string;
    description?: string;
}

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: PropsWithChildren<AuthLayoutProps>) {
    const { tenant } = usePage<{ tenant: TenantData | null }>().props;

    return (
        <div
            className="flex min-h-svh flex-col items-center justify-center bg-[var(--acu-surface)] p-6 md:p-10"
            style={tenant?.branding?.primary_color ? { '--acu-primary': tenant.branding.primary_color } as CSSProperties : undefined}
        >
            <div className="w-full max-w-md">
                <div className="acu-fieldset border-t-[3px] border-t-[var(--acu-primary)] px-8 py-10">
                    <div className="flex flex-col items-center gap-6">
                        <Link
                            href={home()}
                            className="flex flex-col items-center gap-2 font-medium"
                        >
                            {tenant?.logo_url ? (
                                <img
                                    src={tenant.logo_url}
                                    alt={tenant.name}
                                    className="h-10 w-auto object-contain"
                                />
                            ) : (
                                <div className="flex h-10 w-10 items-center justify-center">
                                    <AppLogoIcon className="size-10 fill-current text-[var(--acu-primary)]" />
                                </div>
                            )}
                            {tenant && (
                                <span className="text-sm font-semibold text-[var(--acu-text)]">
                                    {tenant.name}
                                </span>
                            )}
                            <span className="sr-only">{title}</span>
                        </Link>

                        <div className="space-y-2 text-center">
                            <h1 className="text-xl font-semibold text-[var(--acu-text)]">
                                {title}
                            </h1>
                            <p className="text-sm text-[var(--acu-text-muted)]">
                                {description}
                            </p>
                        </div>
                    </div>

                    <div className="mt-8">
                        {children}
                    </div>

                    {tenant && (
                        <div className="mt-6 text-center text-xs text-[var(--acu-text-light)]">
                            Powered by Chinga Games
                        </div>
                    )}
                </div>
            </div>
        </div>
    );
}
