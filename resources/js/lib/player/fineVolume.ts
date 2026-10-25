const PLAYER_ORIGIN = 'https://www.youtube.com';

type Frame = { postMessage(message: unknown, targetOrigin: string): void };

type Incoming = { source: unknown; origin: string; data: unknown };

/**
 * YouTube's player takes whole percents only. The wanted volume is asked of
 * YouTube rounded up, and the Sonder extension, when present inside the
 * player, makes up the rest with a gain below 1.
 */
export function splitVolume(volume: number): {
    playerVolume: number;
    gain: number;
} {
    if (volume <= 0) {
        return { playerVolume: 0, gain: 1 };
    }

    const playerVolume = Math.min(100, Math.max(1, Math.ceil(volume)));

    return { playerVolume, gain: Math.min(volume, 100) / playerVolume };
}

export type FineVolumeLink = {
    /** Asks the player whether the extension is listening inside it. */
    hello(): void;
    setGain(gain: number): void;
    available(): boolean;
    onChange(listener: (available: boolean) => void): () => void;
    destroy(): void;
};

/**
 * Talks to the Sonder extension's script inside the YouTube player iframe,
 * which applies a Web Audio gain to the video there. Only answers from that
 * iframe, on YouTube's origin, count.
 */
export function createFineVolumeLink(deps: {
    frame: () => Frame | null;
    listen: (listener: (event: Incoming) => void) => () => void;
}): FineVolumeLink {
    let available = false;
    let gain = 1;
    const listeners = new Set<(available: boolean) => void>();

    function post(message: unknown): void {
        deps.frame()?.postMessage(message, PLAYER_ORIGIN);
    }

    const stop = deps.listen((event) => {
        const data = event.data as {
            type?: unknown;
            available?: unknown;
        } | null;

        if (
            event.origin !== PLAYER_ORIGIN ||
            event.source !== deps.frame() ||
            data?.type !== 'sonder:fine-volume'
        ) {
            return;
        }

        available = data.available === true;
        listeners.forEach((listener) => listener(available));

        if (available) {
            post({ type: 'sonder:fine-volume-gain', gain });
        }
    });

    return {
        hello: () => post({ type: 'sonder:fine-volume-hello' }),
        setGain(next) {
            gain = next;

            if (available) {
                post({ type: 'sonder:fine-volume-gain', gain });
            }
        },
        available: () => available,
        onChange(listener) {
            listeners.add(listener);

            return () => listeners.delete(listener);
        },
        destroy() {
            stop();
            listeners.clear();
        },
    };
}
