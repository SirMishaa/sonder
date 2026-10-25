import { describe, expect, it, vi } from 'vitest';
import { createMessageListener } from './background';

function deps() {
    return {
        token: {
            get: vi.fn(async () => '1|secret'),
            set: vi.fn(async () => undefined),
            clear: vi.fn(async () => undefined),
        },
        api: {
            command: vi.fn(async () => ({ id: 'cmd', traceparent: 't' })),
            lookup: vi.fn(async () => ({ videos: {}, list: null })),
        },
        sonderIsOpen: vi.fn(async () => true),
        openSonder: vi.fn(async () => undefined),
    };
}

describe('background message listener', () => {
    it('answers through sendResponse and keeps the channel open', async () => {
        const listener = createMessageListener(deps());
        const sendResponse = vi.fn();

        const keepOpen = listener({ type: 'status' }, {}, sendResponse);

        expect(keepOpen).toBe(true);
        await vi.waitFor(() =>
            expect(sendResponse).toHaveBeenCalledWith({ signedIn: true }),
        );
    });

    it('opens Sonder when playing now with no Sonder tab', async () => {
        const d = deps();
        d.sonderIsOpen.mockResolvedValue(false);
        const sendResponse = vi.fn();

        createMessageListener(d)(
            {
                type: 'command',
                body: {
                    action: 'play_now',
                    kind: 'video',
                    listId: null,
                    title: 'x',
                    items: [],
                },
            },
            {},
            sendResponse,
        );

        await vi.waitFor(() =>
            expect(sendResponse).toHaveBeenCalledWith({
                ok: true,
                value: { id: 'cmd', traceparent: 't', sonderOpen: false },
            }),
        );
        expect(d.openSonder).toHaveBeenCalledOnce();
    });

    it('says when Sonder rejected what was sent', async () => {
        const d = deps();
        d.api.command.mockRejectedValue(new Error('Sonder answered 422'));
        const sendResponse = vi.fn();

        createMessageListener(d)(
            {
                type: 'command',
                body: {
                    action: 'enqueue',
                    kind: 'video',
                    listId: null,
                    title: 'x',
                    items: [],
                },
            },
            {},
            sendResponse,
        );

        await vi.waitFor(() =>
            expect(sendResponse).toHaveBeenCalledWith({
                ok: false,
                error: 'failed',
            }),
        );
    });
});
