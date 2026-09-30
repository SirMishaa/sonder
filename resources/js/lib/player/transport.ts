/**
 * What the player store needs from a playback engine. The YouTube IFrame
 * implementation lives in youtubeTransport.ts; tests use FakeTransport.
 */

export type PlaybackState =
    | 'unstarted'
    | 'playing'
    | 'paused'
    | 'buffering'
    | 'ended'
    | 'cued';

export type TransportEvents = {
    ready: [];
    unavailable: [];
    state: [PlaybackState];
    error: [number];
    autoplayBlocked: [];
};

export type LoadOptions = { startAt: number; autoplay: boolean };

type Listener<E extends keyof TransportEvents> = (
    ...args: TransportEvents[E]
) => void;

export interface PlayerTransport {
    load(videoId: string, options: LoadOptions): void;
    play(): void;
    pause(): void;
    seek(seconds: number): void;
    setVolume(volume: number): void;
    setMuted(muted: boolean): void;
    currentTime(): number;
    duration(): number;
    videoId(): string | null;
    on<E extends keyof TransportEvents>(
        event: E,
        listener: Listener<E>,
    ): () => void;
    destroy(): void;
}

export function createEmitter() {
    const listeners = new Map<keyof TransportEvents, Set<Listener<never>>>();

    function on<E extends keyof TransportEvents>(
        event: E,
        listener: Listener<E>,
    ): () => void {
        const set = listeners.get(event) ?? new Set<Listener<never>>();
        set.add(listener as Listener<never>);
        listeners.set(event, set);

        return () => set.delete(listener as Listener<never>);
    }

    function emit<E extends keyof TransportEvents>(
        event: E,
        ...args: TransportEvents[E]
    ): void {
        for (const listener of listeners.get(event) ?? []) {
            (listener as Listener<E>)(...args);
        }
    }

    return { on, emit, clear: () => listeners.clear() };
}
