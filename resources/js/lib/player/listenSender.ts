import { http } from '@inertiajs/vue3';
import ListenController from '@/actions/App/Http/Controllers/ListenController';
import type { ListenPayload } from '@/lib/player/listenTracker';

export type ListenSender = {
    send(payload: ListenPayload): Promise<boolean>;
    sendOnUnload(payload: ListenPayload): void;
};

type SenderDeps = {
    url?: () => string;
    request?: (url: string, payload: ListenPayload) => Promise<{ status: number }>;
    fetcher?: typeof fetch;
    cookie?: () => string;
};

export function readXsrfToken(cookie: string): string | null {
    const prefix = 'XSRF-TOKEN=';
    const part = cookie
        .split(';')
        .map((piece) => piece.trim())
        .find((piece) => piece.startsWith(prefix));

    return part ? decodeURIComponent(part.slice(prefix.length)) : null;
}

/**
 * Sends finished listens. `send` goes through Inertia's HTTP client, which
 * adds the X-XSRF-TOKEN header itself. `sendOnUnload` uses fetch with
 * keepalive because an XHR does not survive `pagehide`.
 */
export function createListenSender(deps: SenderDeps = {}): ListenSender {
    const url = deps.url ?? (() => ListenController.store().url);
    const request =
        deps.request ??
        ((target: string, payload: ListenPayload) =>
            http.getClient().request({
                method: 'post',
                url: target,
                data: payload,
                headers: { Accept: 'application/json' },
            }));
    const fetcher = deps.fetcher ?? ((...args: Parameters<typeof fetch>) => fetch(...args));
    const cookie = deps.cookie ?? (() => document.cookie);

    return {
        async send(payload) {
            try {
                const response = await request(url(), payload);

                return response.status >= 200 && response.status < 300;
            } catch {
                return false;
            }
        },
        sendOnUnload(payload) {
            const token = readXsrfToken(cookie());

            void fetcher(url(), {
                method: 'POST',
                keepalive: true,
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    ...(token ? { 'X-XSRF-TOKEN': token } : {}),
                },
                body: JSON.stringify(payload),
            }).catch(() => undefined);
        },
    };
}
