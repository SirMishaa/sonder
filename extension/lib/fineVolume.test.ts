import { describe, expect, it, vi } from 'vitest';
import { serveFineVolume } from './fineVolume';

const SONDER = 'https://sonder.test';

function setup(graph: { setGain: (gain: number) => void } | null) {
    const replies: unknown[] = [];
    const attach = vi.fn(async () => graph);
    const stop = serveFineVolume({
        win: window,
        parentOrigin: SONDER,
        attach,
        reply: (message) => replies.push(message),
    });
    const send = (data: unknown, origin = SONDER) =>
        window.dispatchEvent(new MessageEvent('message', { data, origin }));

    return { replies, attach, send, stop };
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('serveFineVolume', () => {
    it('tells Sonder the precise volume is on once audio runs through it', async () => {
        const { replies, send, stop } = setup({ setGain: vi.fn() });

        send({ type: 'sonder:fine-volume-hello' });
        await flush();

        expect(replies).toEqual([
            { type: 'sonder:fine-volume', available: true },
        ]);
        stop();
    });

    it('says it is off when the audio could not start, and tries again next time', async () => {
        const { replies, attach, send, stop } = setup(null);

        send({ type: 'sonder:fine-volume-hello' });
        await flush();
        send({ type: 'sonder:fine-volume-hello' });
        await flush();

        expect(replies).toEqual([
            { type: 'sonder:fine-volume', available: false },
            { type: 'sonder:fine-volume', available: false },
        ]);
        expect(attach).toHaveBeenCalledTimes(2);
        stop();
    });

    it('applies the gain Sonder asks for, within 0 and 1', async () => {
        const setGain = vi.fn();
        const { send, stop } = setup({ setGain });

        send({ type: 'sonder:fine-volume-gain', gain: 0.25 });
        send({ type: 'sonder:fine-volume-gain', gain: 4 });
        await flush();

        expect(setGain.mock.calls).toEqual([[0.25], [1]]);
        stop();
    });

    it('ignores pages other than Sonder', async () => {
        const { replies, attach, send, stop } = setup({ setGain: vi.fn() });

        send({ type: 'sonder:fine-volume-hello' }, 'https://evil.test');
        await flush();

        expect(replies).toEqual([]);
        expect(attach).not.toHaveBeenCalled();
        stop();
    });
});
