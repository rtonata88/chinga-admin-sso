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

/**
 * Auth shell — brass-on-ink to match the operator console. Tenant
 * branding (logo, name, primary_color) still customises the page;
 * a tenant `primary_color` overrides the brass accent so resellers
 * can keep their visual identity on the login flow.
 */
export default function AuthPremiumLayout({
    children,
    title,
    description,
}: PropsWithChildren<AuthPremiumLayoutProps>) {
    const { tenant } = usePage<{ tenant: TenantData | null }>().props;

    const tenantColor = tenant?.branding?.primary_color;

    const accentStyle: CSSProperties = tenantColor
        ? ({
              // Tenant-supplied accent overrides the brass scale on the
              // login page only — operator console keeps brass.
              '--cg-brass': tenantColor,
              '--cg-brass-hi': tenantColor,
              '--cg-brass-wash': hexToRgba(tenantColor, 0.12),
              '--auth-accent': tenantColor,
              '--auth-glow': hexToRgba(tenantColor, 0.18),
          } as CSSProperties)
        : ({} as CSSProperties);

    return (
        <div
            className="cgo-auth-shell"
            style={{
                position: 'relative',
                minHeight: '100svh',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                padding: '32px 16px',
                background: 'var(--cg-ink)',
                color: 'var(--cg-fg-1)',
                overflow: 'hidden',
                fontFamily: 'var(--cg-body)',
                ...accentStyle,
            }}
        >
            {/* Subtle dot pattern */}
            <div
                aria-hidden
                style={{
                    position: 'absolute',
                    inset: 0,
                    opacity: 0.045,
                    backgroundImage:
                        'radial-gradient(circle, rgba(255,255,255,0.8) 1px, transparent 1px)',
                    backgroundSize: '22px 22px',
                    pointerEvents: 'none',
                }}
            />

            {/* Brass radial glow behind the card */}
            <div
                aria-hidden
                style={{
                    position: 'absolute',
                    left: '50%',
                    top: '50%',
                    transform: 'translate(-50%, -50%)',
                    width: 560,
                    height: 560,
                    borderRadius: '50%',
                    background: 'var(--auth-glow, rgba(201, 168, 76, 0.12))',
                    filter: 'blur(120px)',
                    pointerEvents: 'none',
                }}
            />

            <div style={{ position: 'relative', zIndex: 1, width: '100%', maxWidth: 420 }}>
                <div
                    style={{
                        background: 'var(--cg-ink-card)',
                        border: '1px solid var(--cg-rule)',
                        borderRadius: 12,
                        padding: '36px 32px 32px',
                        boxShadow:
                            '0 1px 0 rgba(255,255,255,0.02) inset, 0 12px 40px rgba(0,0,0,0.4)',
                    }}
                >
                    {/* Wordmark + title */}
                    <div
                        style={{
                            display: 'flex',
                            flexDirection: 'column',
                            alignItems: 'center',
                            gap: 18,
                            marginBottom: 24,
                        }}
                    >
                        <Link
                            href={home()}
                            style={{
                                fontFamily: 'var(--cg-condensed)',
                                fontSize: 22,
                                fontWeight: 700,
                                letterSpacing: '0.06em',
                                textTransform: 'uppercase',
                                color: 'var(--cg-brass-hi)',
                                textDecoration: 'none',
                            }}
                        >
                            {tenant?.name ?? 'Chinga Games'}
                        </Link>

                        <div style={{ textAlign: 'center' }}>
                            {title && (
                                <h1
                                    style={{
                                        margin: 0,
                                        fontFamily: 'var(--cg-condensed)',
                                        fontSize: 20,
                                        fontWeight: 600,
                                        letterSpacing: '-0.005em',
                                        color: 'var(--cg-fg-1)',
                                    }}
                                >
                                    {title}
                                </h1>
                            )}
                            {description && (
                                <p
                                    style={{
                                        margin: '6px 0 0',
                                        fontSize: 13,
                                        color: 'var(--cg-fg-3)',
                                    }}
                                >
                                    {description}
                                </p>
                            )}
                        </div>
                    </div>

                    {/* Form content */}
                    <div>{children}</div>
                </div>

                {/* Footer */}
                <div
                    style={{
                        marginTop: 16,
                        textAlign: 'center',
                        fontSize: 11,
                        letterSpacing: '0.18em',
                        textTransform: 'uppercase',
                        color: 'var(--cg-fg-4)',
                    }}
                >
                    {tenant ? 'Powered by Chinga Games' : 'Chinga Games'}
                </div>
            </div>
        </div>
    );
}

function hexToRgba(hex: string, alpha: number): string {
    const cleanHex = hex.replace('#', '');
    const fullHex =
        cleanHex.length === 3
            ? cleanHex.split('').map((c) => c + c).join('')
            : cleanHex;
    if (!/^[0-9a-fA-F]{6}$/.test(fullHex)) {
        return `rgba(201, 168, 76, ${alpha})`;
    }
    const r = parseInt(fullHex.substring(0, 2), 16);
    const g = parseInt(fullHex.substring(2, 4), 16);
    const b = parseInt(fullHex.substring(4, 6), 16);
    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}
