import { describe, expect, it, vi } from 'vite-plus/test';
import { createListenSender, readXsrfToken } from '@/lib/player/listenSender';
import type { ListenPayload } from '@/lib/player/listenTracker';

const payload: ListenPayload = {
    id: 'listen-1',
    youtube_video_id: 'abc',
    title: 'Survival',
    artists: 'Muse',
    youtube_playlist_id: 'PL1',
    origin: 'playlist',
    end_reason: 'skipped',
    started_at: '2026-10-01T10:00:00.000Z',
    ended_at: '2026-10-01T10:00:30.000Z',
    position_seconds: 30,
    listened_seconds: 30,
    duration_seconds: 258,
};

describe('listenSender', () => {
    it('posts the listen and reports success on 204', async () => {
        const request = vi.fn(async () => ({ status: 204 }));
        const sender = createListenSender({ url: () => '/listens', request });

        await expect(sender.send(payload)).resolves.toBe(true);
        expect(request).toHaveBeenCalledWith('/listens', payload);
    });

    it('reports failure instead of throwing when the request fails', async () => {
        const sender = createListenSender({
            url: () => '/listens',
            request: async () => {
                throw new Error('offline');
            },
        });

        await expect(sender.send(payload)).resolves.toBe(false);
    });

    it('reports failure on a validation error', async () => {
        const sender = createListenSender({
            url: () => '/listens',
            request: async () => ({ status: 422 }),
        });

        await expect(sender.send(payload)).resolves.toBe(false);
    });

    it('sends on unload with keepalive and the XSRF token from the cookie', () => {
        const fetcher = vi.fn(async () => new Response(null, { status: 204 }));
        const sender = createListenSender({
            url: () => '/listens',
            fetcher,
            cookie: () => 'other=1; XSRF-TOKEN=abc%3D%3D; session=x',
        });

        sender.sendOnUnload(payload);

        expect(fetcher).toHaveBeenCalledWith('/listens', {
            method: 'POST',
            keepalive: true,
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-XSRF-TOKEN': 'abc==',
            },
            body: JSON.stringify(payload),
        });
    });

    it('reads no token when the cookie is missing', () => {
        expect(readXsrfToken('session=x')).toBeNull();
    });
});
