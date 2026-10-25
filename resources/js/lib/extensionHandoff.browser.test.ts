import { afterEach, describe, expect, it, vi } from 'vite-plus/test';
import { handOffToken } from '@/lib/extensionHandoff';

describe('handOffToken', () => {
    afterEach(() => {
        vi.useRealTimers();
    });

    it('resolves once the extension acknowledges the token', async () => {
        window.addEventListener(
            'message',
            (event: MessageEvent<{ type?: string }>) => {
                if (event.data?.type === 'sonder:extension-token') {
                    window.postMessage(
                        { type: 'sonder:extension-connected' },
                        window.location.origin,
                    );
                }
            },
            { once: true },
        );

        await expect(handOffToken('1|secret', 1000)).resolves.toBe(true);
    });

    it('gives up when no extension answers', async () => {
        vi.useFakeTimers();
        const handoff = handOffToken('1|secret', 1000);

        await vi.advanceTimersByTimeAsync(1000);

        await expect(handoff).resolves.toBe(false);
    });
});
