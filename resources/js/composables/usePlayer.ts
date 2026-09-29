import { computed, reactive, watch } from 'vue';

/**
 * Front-end playback fixture.
 *
 * Sonder cannot control YouTube Music playback yet. Until it can, this store
 * plays the real tracks the pages already loaded (queue, next / previous,
 * progress, volume) so the interface can be built and felt for real. Nothing
 * here talks to YouTube Music; swapping it for a real transport should only
 * mean replacing this module.
 */

export type QueueTrack = {
    key: string;
    videoId: string | null;
    title: string;
    artists: string;
    album: string | null;
    duration: string | null;
    durationSeconds: number;
    thumbnailUrl: string | null;
};

export type QueueSource = {
    playlistId: string | null;
    title: string;
};

type PlayerState = {
    queue: QueueTrack[];
    index: number;
    playing: boolean;
    elapsed: number;
    volume: number;
    muted: boolean;
    source: QueueSource | null;
    queueOpen: boolean;
};

const STORAGE_KEY = 'sonder.player.v1';
const TICK_MS = 250;
const FALLBACK_SECONDS = 210;
const MAX_STORED_TRACKS = 300;

const state = reactive<PlayerState>({
    queue: [],
    index: -1,
    playing: false,
    elapsed: 0,
    volume: 70,
    muted: false,
    source: null,
    queueOpen: false,
});

let booted = false;

function secondsOf(track: App.Data.TrackData): number {
    if (track.durationSeconds) {
        return track.durationSeconds;
    }

    const parts = (track.duration ?? '').split(':').map(Number);

    if (parts.length < 2 || parts.some(Number.isNaN)) {
        return FALLBACK_SECONDS;
    }

    return parts.reduce((total, part) => total * 60 + part, 0);
}

export function toQueueTrack(
    track: App.Data.TrackData,
    position: number,
): QueueTrack {
    return {
        key: `${track.videoId ?? track.title}#${position}`,
        videoId: track.videoId,
        title: track.title,
        artists: track.artists,
        album: track.album,
        duration: track.duration,
        durationSeconds: secondsOf(track),
        thumbnailUrl: track.thumbnailUrl,
    };
}

function persist(): void {
    try {
        localStorage.setItem(
            STORAGE_KEY,
            JSON.stringify({
                queue: state.queue.slice(0, MAX_STORED_TRACKS),
                index: state.index,
                elapsed: Math.floor(state.elapsed),
                volume: state.volume,
                muted: state.muted,
                source: state.source,
                queueOpen: state.queueOpen,
            }),
        );
    } catch {
        // Storage can be unavailable (private mode, quota); playback still works.
    }
}

function restore(): void {
    try {
        const saved = JSON.parse(
            localStorage.getItem(STORAGE_KEY) ?? 'null',
        ) as Partial<PlayerState> | null;

        if (saved && Array.isArray(saved.queue)) {
            Object.assign(state, saved, { playing: false });
        }
    } catch {
        // A corrupt entry simply starts an empty player.
    }
}

function boot(): void {
    if (booted || typeof window === 'undefined') {
        return;
    }

    booted = true;
    restore();

    window.setInterval(() => {
        const track = state.queue[state.index];

        if (!state.playing || !track) {
            return;
        }

        state.elapsed += TICK_MS / 1000;

        if (state.elapsed >= track.durationSeconds) {
            next();
        }
    }, TICK_MS);

    watch(
        () => [
            state.queue,
            state.index,
            state.volume,
            state.muted,
            state.source,
            state.queueOpen,
        ],
        persist,
        { deep: true },
    );

    window.addEventListener('pagehide', persist);
}

function jumpTo(index: number): void {
    if (index < 0 || index >= state.queue.length) {
        return;
    }

    state.index = index;
    state.elapsed = 0;
    state.playing = true;
}

function next(): void {
    if (state.index + 1 < state.queue.length) {
        jumpTo(state.index + 1);
    } else {
        state.playing = false;
        state.elapsed = 0;
    }
}

function previous(): void {
    if (state.elapsed > 3 || state.index <= 0) {
        state.elapsed = 0;

        return;
    }

    jumpTo(state.index - 1);
}

function playTracks(
    tracks: App.Data.TrackData[],
    startAt: number,
    source: QueueSource,
): void {
    const playable = tracks
        .map((track, position) => ({ track, position }))
        .filter(({ track }) => track.isAvailable);

    const start = Math.max(
        0,
        playable.findIndex(({ position }) => position === startAt),
    );

    state.queue = playable.map(({ track, position }) =>
        toQueueTrack(track, position),
    );
    state.source = source;
    jumpTo(start);
}

function playNext(track: App.Data.TrackData, source: QueueSource): void {
    const item = toQueueTrack(track, Date.now());

    if (state.index < 0) {
        state.queue = [item];
        state.source = source;
        jumpTo(0);

        return;
    }

    state.queue.splice(state.index + 1, 0, item);
    jumpTo(state.index + 1);
}

export function usePlayer() {
    boot();

    const current = computed<QueueTrack | null>(
        () => state.queue[state.index] ?? null,
    );
    const progress = computed(() =>
        current.value
            ? Math.min(1, state.elapsed / current.value.durationSeconds)
            : 0,
    );
    const upNext = computed(() => state.queue.slice(state.index + 1));

    return {
        state,
        current,
        progress,
        upNext,
        playTracks,
        playNext,
        jumpTo,
        next,
        previous,
        toggle: () => {
            if (current.value) {
                state.playing = !state.playing;
            }
        },
        setVolume: (volume: number) => {
            state.volume = Math.max(0, Math.min(100, volume));
            state.muted = state.volume === 0;
        },
        toggleMute: () => {
            state.muted = !state.muted;
        },
        toggleQueue: () => {
            state.queueOpen = !state.queueOpen;
        },
        isPlayingFrom: (playlistId: string) =>
            state.source?.playlistId === playlistId && current.value !== null,
    };
}

export function formatSeconds(seconds: number): string {
    const whole = Math.max(0, Math.floor(seconds));

    return `${Math.floor(whole / 60)}:${String(whole % 60).padStart(2, '0')}`;
}
