// resources/js/lib/csrf.ts
//
// The CSRF header for fetch() calls. Laravel checks X-CSRF-TOKEN FIRST and
// only falls back to X-XSRF-TOKEN when it is absent, so sending a stale
// meta-tag token beside a fresh cookie still fails ("CSRF token mismatch").
// The meta tag is written once per full page load; Inertia navigation never
// refreshes it, so after logging out and back in, or once the session has
// rotated, it is stale until a reload. The XSRF-TOKEN cookie is reissued
// with every response, so it is always current: send it alone, and fall
// back to the meta tag only when there is no cookie.

function xsrfCookie(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

export function csrfHeader(): Record<string, string> {
    const cookie = xsrfCookie();
    if (cookie) return { 'X-XSRF-TOKEN': cookie };
    return {
        'X-CSRF-TOKEN':
            document
                .querySelector('meta[name="csrf-token"]')
                ?.getAttribute('content') || '',
    };
}
