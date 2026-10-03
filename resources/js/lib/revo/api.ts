import { embedded } from '@salla.sa/embedded-sdk';

export class ApiError extends Error {
    constructor(
        message: string,
        public status: number,
        public body: Record<string, unknown> = {},
    ) {
        super(message);
    }
}

export type SessionState = 'ready' | 'awaiting_authorization' | 'not_installed' | 'failed';

let sessionToken: string | null = null;

/**
 * Exchange the short-lived Salla token for Revo's own session (FR-PLT-003).
 */
export async function startSession(): Promise<SessionState> {
    const token = embedded.auth.getToken();

    if (!token) {
        return 'failed';
    }

    const response = await fetch('/api/app/session', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify({ token }),
    });

    if (response.status === 404) {
        return 'not_installed';
    }

    if (!response.ok) {
        return 'failed';
    }

    const body = await response.json();
    sessionToken = body.token;

    return body.state as SessionState;
}

export async function api<T = unknown>(method: string, path: string, body?: unknown, retried = false): Promise<T> {
    const isForm = body instanceof FormData;
    const response = await fetch(`/api/app${path}`, {
        method,
        headers: {
            Accept: 'application/json',
            Authorization: `Bearer ${sessionToken ?? ''}`,
            ...(body !== undefined && !isForm ? { 'Content-Type': 'application/json' } : {}),
        },
        body: body === undefined ? undefined : isForm ? body : JSON.stringify(body),
    });

    if (response.status === 401 && !retried && (await startSession()) === 'ready') {
        return api<T>(method, path, body, true);
    }

    if (response.status === 204) {
        return undefined as T;
    }

    const json = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new ApiError(json.message ?? 'Request failed', response.status, json);
    }

    return json as T;
}

export const get = <T>(path: string) => api<T>('GET', path);
export const post = <T>(path: string, body?: unknown) => api<T>('POST', path, body ?? {});
export const put = <T>(path: string, body?: unknown) => api<T>('PUT', path, body ?? {});
export const del = <T>(path: string) => api<T>('DELETE', path);

export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
}

export interface Overview {
    status: string;
    sync: { pages_done: number; total_pages: number | null; finished: boolean };
    credits: { balance: number; reserved: number; available: number; low: boolean; threshold: number; spent_this_month: number };
    products: { total: number; missing_seo: number; stale_context: number };
    content: { drafts_waiting: number; approved_this_month: number };
    images: { edits_this_month: number; drafts_waiting: number; library: number; expiring_soon: number };
    plan: { code: string | null; status: string | null; features: Record<string, boolean> };
}

export interface ProductSummary {
    id: number;
    sku: string | null;
    name: string | null;
    price: string | null;
    currency: string | null;
    image: string | null;
    has_seo: boolean;
}

export interface Generation {
    id: number;
    product_id: number;
    lang: string;
    status: string;
    fields: string[];
    output: Record<string, string> | null;
    manual_fields: string[] | null;
    pending_fields: string[];
    outdated: boolean;
    credits: number;
    error: string | null;
    limits: Record<string, number>;
}

export interface MediaItem {
    id: number;
    name: string | null;
    status: string;
    source: string;
    url: string | null;
    tags: string[];
    attached_product_id: number | null;
    expires_at: string | null;
}
