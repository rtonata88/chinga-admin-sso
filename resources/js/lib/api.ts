/**
 * Get CSRF token from the page meta tag
 */
function getCsrfToken(): string {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta?.getAttribute('content') || '';
}

/**
 * API fetch wrapper that includes CSRF token and proper headers
 */
export async function api<T = unknown>(
    url: string,
    options: RequestInit = {},
): Promise<{ success: boolean; data?: T; message?: string; meta?: unknown }> {
    const headers: HeadersInit = {
        Accept: 'application/json',
        'X-CSRF-TOKEN': getCsrfToken(),
        ...(options.headers || {}),
    };

    // Add Content-Type for requests with body
    if (options.body && typeof options.body === 'string') {
        headers['Content-Type'] = 'application/json';
    }

    const response = await fetch(url, {
        ...options,
        headers,
        credentials: 'same-origin',
    });

    const data = await response.json();

    if (!response.ok) {
        throw new Error(data.message || `HTTP error ${response.status}`);
    }

    return data;
}

/**
 * GET request
 */
export function apiGet<T = unknown>(url: string) {
    return api<T>(url, { method: 'GET' });
}

/**
 * POST request
 */
export function apiPost<T = unknown>(url: string, body?: unknown) {
    return api<T>(url, {
        method: 'POST',
        body: body ? JSON.stringify(body) : undefined,
    });
}

/**
 * PUT request
 */
export function apiPut<T = unknown>(url: string, body?: unknown) {
    return api<T>(url, {
        method: 'PUT',
        body: body ? JSON.stringify(body) : undefined,
    });
}

/**
 * DELETE request
 */
export function apiDelete<T = unknown>(url: string) {
    return api<T>(url, { method: 'DELETE' });
}
