import { describe, expect, it, vi } from 'vitest';
import { createApi, Rejected, SignedOut } from './api';
import type { TokenStore } from './token';

const TRACE = '00-0af7651916cd43dd8448eb211c80319c-b7ad6b7169203331-01';

function memoryToken(
    initial: string | null,
): TokenStore & { value: string | null } {
    const store = {
        value: initial,
        get: async () => store.value,
        set: async (token: string) => {
            store.value = token;
        },
        clear: async () => {
            store.value = null;
        },
    };

    return store;
}

const body: App.Data.PlayerCommandData = {
    action: 'play_now',
    kind: 'video',
    listId: null,
    title: 'Muse - Sunburn',
    items: [
        {
            videoId: 'dQw4w9WgXcQ',
            title: 'Sunburn',
            artists: 'Muse',
            durationSeconds: 238,
            thumbnailUrl: null,
        },
    ],
};

describe('api', () => {
    it('sends the bearer token and traceparent', async () => {
        const fetch = vi.fn(
            async () => new Response('{"id":"cmd-1"}', { status: 202 }),
        );
        const api = createApi({
            baseUrl: 'https://sonder.test',
            token: memoryToken('1|secret'),
            fetch,
            traceparent: () => TRACE,
        });

        await expect(api.command(body)).resolves.toEqual({
            id: 'cmd-1',
            traceparent: TRACE,
        });
        expect(fetch).toHaveBeenCalledWith(
            'https://sonder.test/api/extension/commands',
            expect.objectContaining({
                method: 'POST',
                body: JSON.stringify(body),
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    Authorization: 'Bearer 1|secret',
                    traceparent: TRACE,
                },
            }),
        );
    });

    it('clears the token on 401', async () => {
        const token = memoryToken('1|revoked');
        const api = createApi({
            baseUrl: 'https://sonder.test',
            token,
            fetch: async () => new Response('{}', { status: 401 }),
        });

        await expect(
            api.lookup({ videoIds: ['dQw4w9WgXcQ'], listId: null }),
        ).rejects.toBeInstanceOf(SignedOut);
        expect(token.value).toBeNull();
    });

    it('throws SignedOut without a token', async () => {
        const fetch = vi.fn();
        const api = createApi({
            baseUrl: 'https://sonder.test',
            token: memoryToken(null),
            fetch,
        });

        await expect(api.command(body)).rejects.toBeInstanceOf(SignedOut);
        expect(fetch).not.toHaveBeenCalled();
    });

    it('tells a rejected request from a failure', async () => {
        const rejecting = createApi({
            baseUrl: 'https://sonder.test',
            token: memoryToken('1|secret'),
            fetch: async () => new Response('{}', { status: 422 }),
        });
        const failing = createApi({
            baseUrl: 'https://sonder.test',
            token: memoryToken('1|secret'),
            fetch: async () => new Response('{}', { status: 500 }),
        });

        await expect(rejecting.command(body)).rejects.toBeInstanceOf(Rejected);
        await expect(failing.command(body)).rejects.toThrow('500');
    });
});
