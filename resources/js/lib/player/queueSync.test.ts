import { describe, expect, it, vi } from 'vite-plus/test';
import { createQueueSync } from '@/lib/player/queueSync';
import type { QueueSnapshot } from '@/lib/player/queueSync';

const snapshot: QueueSnapshot = {
    tracks: [],
    index: -1,
    source: null,
    origin: 'playlist',
};

describe('queueSync', () => {
    it('puts the snapshot and returns the new version', async () => {
        const request = vi.fn(async () => ({
            status: 200,
            data: '{"version":6}',
        }));
        const sync = createQueueSync({ url: () => '/player/queue', request });

        await expect(sync.save(snapshot)).resolves.toBe(6);
        expect(request).toHaveBeenCalledWith('/player/queue', snapshot);
    });

    it('returns null on a validation error', async () => {
        const sync = createQueueSync({
            url: () => '/player/queue',
            request: async () => ({ status: 422, data: '{}' }),
        });

        await expect(sync.save(snapshot)).resolves.toBeNull();
    });

    it('returns null instead of throwing when the request fails', async () => {
        const sync = createQueueSync({
            url: () => '/player/queue',
            request: async () => {
                throw new Error('offline');
            },
        });

        await expect(sync.save(snapshot)).resolves.toBeNull();
    });
});
