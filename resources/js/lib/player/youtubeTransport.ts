import { createEmitter } from '@/lib/player/transport';
import type { PlaybackState, PlayerTransport } from '@/lib/player/transport';

type YouTubePlayer = {
    loadVideoById(options: { videoId: string; startSeconds?: number }): void;
    cueVideoById(options: { videoId: string; startSeconds?: number }): void;
    playVideo(): void;
    pauseVideo(): void;
    seekTo(seconds: number, allowSeekAhead: boolean): void;
    setVolume(volume: number): void;
    mute(): void;
    unMute(): void;
    getCurrentTime(): number;
    getDuration(): number;
    getVideoData(): { video_id?: string };
    destroy(): void;
};

type YouTubePlayerOptions = {
    host: string;
    width: number;
    height: number;
    playerVars: Record<string, string | number>;
    events: {
        onReady: () => void;
        onStateChange: (event: { data: number }) => void;
        onError: (event: { data: number }) => void;
        onAutoplayBlocked: () => void;
    };
};

type YouTubeNamespace = {
    Player: new (
        element: HTMLElement,
        options: YouTubePlayerOptions,
    ) => YouTubePlayer;
};

declare global {
    interface Window {
        YT?: YouTubeNamespace;
        onYouTubeIframeAPIReady?: () => void;
    }
}

const API_URL = 'https://www.youtube.com/iframe_api';
const LOAD_TIMEOUT_MS = 15_000;
const STATES: Record<number, PlaybackState> = {
    [-1]: 'unstarted',
    0: 'ended',
    1: 'playing',
    2: 'paused',
    3: 'buffering',
    5: 'cued',
};

let apiPromise: Promise<YouTubeNamespace> | null = null;

function loadIframeApi(): Promise<YouTubeNamespace> {
    if (window.YT?.Player) {
        return Promise.resolve(window.YT);
    }

    apiPromise ??= new Promise<YouTubeNamespace>((resolve, reject) => {
        const timeout = window.setTimeout(
            () => reject(new Error('The YouTube IFrame API timed out')),
            LOAD_TIMEOUT_MS,
        );
        const previous = window.onYouTubeIframeAPIReady;

        window.onYouTubeIframeAPIReady = () => {
            previous?.();
            window.clearTimeout(timeout);

            if (window.YT?.Player) {
                resolve(window.YT);
            } else {
                reject(
                    new Error(
                        'The YouTube IFrame API loaded without YT.Player',
                    ),
                );
            }
        };

        const script = document.createElement('script');
        script.src = API_URL;
        script.async = true;
        script.addEventListener('error', () => {
            window.clearTimeout(timeout);
            reject(new Error('The YouTube IFrame API failed to load'));
        });
        document.head.append(script);
    }).catch((error: unknown) => {
        apiPromise = null;

        throw error;
    });

    return apiPromise;
}

/**
 * PlayerTransport over the YouTube IFrame Player API. youtube.com (never
 * youtube-nocookie.com) so the user's YouTube Premium session applies.
 * Commands before the player is ready are ignored; the store waits for
 * "ready" and cues the current track itself.
 */
export function createYouTubeTransport(element: HTMLElement): PlayerTransport {
    const emitter = createEmitter();
    let player: YouTubePlayer | null = null;
    let destroyed = false;

    loadIframeApi().then(
        (YT) => {
            if (destroyed) {
                return;
            }

            const instance = new YT.Player(element, {
                host: 'https://www.youtube.com',
                width: 1,
                height: 1,
                playerVars: {
                    controls: 0,
                    disablekb: 1,
                    playsinline: 1,
                    rel: 0,
                    iv_load_policy: 3,
                    origin: window.location.origin,
                },
                events: {
                    onReady: () => {
                        player = instance;
                        emitter.emit('ready');
                    },
                    onStateChange: ({ data }) => {
                        const state = STATES[data];

                        if (state) {
                            emitter.emit('state', state);
                        }
                    },
                    onError: ({ data }) => emitter.emit('error', data),
                    onAutoplayBlocked: () => emitter.emit('autoplayBlocked'),
                },
            });
        },
        () => {
            if (!destroyed) {
                emitter.emit('unavailable');
            }
        },
    );

    return {
        load(videoId, { startAt, autoplay }) {
            if (autoplay) {
                player?.loadVideoById({ videoId, startSeconds: startAt });
            } else {
                player?.cueVideoById({ videoId, startSeconds: startAt });
            }
        },
        play: () => player?.playVideo(),
        pause: () => player?.pauseVideo(),
        seek: (seconds) => player?.seekTo(seconds, true),
        setVolume: (volume) => player?.setVolume(volume),
        setMuted: (muted) => (muted ? player?.mute() : player?.unMute()),
        currentTime: () => player?.getCurrentTime() ?? 0,
        duration: () => player?.getDuration() ?? 0,
        videoId: () => player?.getVideoData().video_id ?? null,
        on: emitter.on,
        destroy() {
            destroyed = true;
            player?.destroy();
            player = null;
            emitter.clear();
        },
    };
}
