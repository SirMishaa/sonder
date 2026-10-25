import { describe, expect, it, vi } from 'vitest';
import { listenForToken } from './handoff';

describe('listenForToken', () => {
    it('stores a token posted by the page and acknowledges it', async () => {
        const store = vi.fn(async () => undefined);
        const acknowledged = new Promise<unknown>((resolve) =>
            window.addEventListener('message', (event) => {
                if (
                    (event.data as { type?: string })?.type ===
                    'sonder:extension-connected'
                ) {
                    resolve(event.data);
                }
            }),
        );
        listenForToken(window, store);

        window.postMessage(
            { type: 'sonder:extension-token', token: '1|secret' },
            window.location.origin,
        );

        await acknowledged;
        expect(store).toHaveBeenCalledWith('1|secret');
    });

    it('ignores messages that do not carry a token', async () => {
        const store = vi.fn(async () => undefined);
        listenForToken(window, store);

        window.postMessage({ type: 'sonder:extension-token', token: 42 }, '*');
        window.postMessage('hello', '*');
        await new Promise((resolve) => setTimeout(resolve, 20));

        expect(store).not.toHaveBeenCalled();
    });
});
