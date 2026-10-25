import { describe, expect, it, vi } from 'vite-plus/test';
import { createFineVolumeLink, splitVolume } from '@/lib/player/fineVolume';

describe('splitVolume', () => {
    it('asks YouTube for the nearest whole percent above and makes up the rest', () => {
        expect(splitVolume(0.25)).toEqual({ playerVolume: 1, gain: 0.25 });
        expect(splitVolume(12.5)).toEqual({
            playerVolume: 13,
            gain: 12.5 / 13,
        });
        expect(splitVolume(40)).toEqual({ playerVolume: 40, gain: 1 });
    });

    it('mutes at zero without touching the gain', () => {
        expect(splitVolume(0)).toEqual({ playerVolume: 0, gain: 1 });
    });
});

function setup() {
    const posted: unknown[] = [];
    let handler:
        | ((event: { source: unknown; origin: string; data: unknown }) => void)
        | null = null;
    const frame = {
        postMessage: (message: unknown, origin: string) =>
            posted.push({ message, origin }),
    };
    const link = createFineVolumeLink({
        frame: () => frame,
        listen: (listener) => {
            handler = listener;

            return () => (handler = null);
        },
    });
    const reply = (
        data: unknown,
        origin = 'https://www.youtube.com',
        source: unknown = frame,
    ) => handler?.({ source, origin, data });

    return { link, posted, reply };
}

describe('createFineVolumeLink', () => {
    it('asks the embedded player whether the extension is there', () => {
        const { link, posted } = setup();

        link.hello();

        expect(posted).toEqual([
            {
                message: { type: 'sonder:fine-volume-hello' },
                origin: 'https://www.youtube.com',
            },
        ]);
    });

    it('becomes available when the extension answers from the player', () => {
        const { link, reply } = setup();
        const changed = vi.fn();
        link.onChange(changed);

        reply({ type: 'sonder:fine-volume', available: true });

        expect(link.available()).toBe(true);
        expect(changed).toHaveBeenCalledWith(true);
    });

    it('ignores answers from anywhere else', () => {
        const { link, reply } = setup();

        reply(
            { type: 'sonder:fine-volume', available: true },
            'https://evil.test',
        );
        reply(
            { type: 'sonder:fine-volume', available: true },
            'https://www.youtube.com',
            {},
        );

        expect(link.available()).toBe(false);
    });

    it('sends the gain once available, and the last one asked before', () => {
        const { link, posted, reply } = setup();

        link.setGain(0.25);
        expect(posted).toEqual([]);

        reply({ type: 'sonder:fine-volume', available: true });

        expect(posted).toEqual([
            {
                message: { type: 'sonder:fine-volume-gain', gain: 0.25 },
                origin: 'https://www.youtube.com',
            },
        ]);
    });
});
