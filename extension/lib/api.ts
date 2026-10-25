import type { TokenStore } from './token';
import { newTraceparent } from './trace';

/** The extension holds no valid token: the owner must connect it again. */
export class SignedOut extends Error {
    constructor() {
        super('signed out of Sonder');
    }
}

export type LookupBody = { videoIds: string[]; listId: string | null };

export type LookupResult = {
    videos: Record<string, App.Data.VideoKnowledgeData>;
    list: { sentBefore: boolean; lastSentAt: string | null } | null;
};

export type Api = {
    command(
        body: App.Data.PlayerCommandData,
    ): Promise<{ id: string; traceparent: string }>;
    lookup(body: LookupBody): Promise<LookupResult>;
};

/**
 * Calls Sonder's extension endpoints with the stored token. A 401 means the
 * token was revoked: it is dropped and `SignedOut` is thrown.
 */
export function createApi(deps: {
    baseUrl: string;
    token: TokenStore;
    fetch?: typeof fetch;
    traceparent?: () => string;
}): Api {
    const send = deps.fetch ?? ((...args) => fetch(...args));
    const trace = deps.traceparent ?? (() => newTraceparent());

    async function post<T>(
        path: string,
        body: unknown,
        traceparent: string,
    ): Promise<T> {
        const token = await deps.token.get();

        if (token === null) {
            throw new SignedOut();
        }

        const response = await send(`${deps.baseUrl}/api/extension/${path}`, {
            method: 'POST',
            body: JSON.stringify(body),
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                Authorization: `Bearer ${token}`,
                traceparent,
            },
        });

        if (response.status === 401) {
            await deps.token.clear();

            throw new SignedOut();
        }

        if (!response.ok) {
            throw new Error(`Sonder answered ${response.status}`);
        }

        return (await response.json()) as T;
    }

    return {
        async command(body) {
            const traceparent = trace();
            const { id } = await post<{ id: string }>(
                'commands',
                body,
                traceparent,
            );

            return { id, traceparent };
        },
        lookup: (body) => post<LookupResult>('lookup', body, trace()),
    };
}
