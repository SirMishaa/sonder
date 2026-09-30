import { trans } from 'laravel-vue-i18n';
import { computed, getCurrentInstance, inject, reactive, watch } from 'vue';
import type { InjectionKey } from 'vue';
import { useToast } from '@/composables/useToast';
import { createListenSender } from '@/lib/player/listenSender';
import type { ListenSender } from '@/lib/player/listenSender';
import {
    finishListen,
    markSeek,
    recordProgress,
    startListen,
} from '@/lib/player/listenTracker';
import type { Listen } from '@/lib/player/listenTracker';
import type { PlaybackState, PlayerTransport } from '@/lib/player/transport';

/**
 * The player store: queue, current track and listening history, driving a
 * PlayerTransport (the hidden YouTube iframe in the app, a fake in tests).
 * Components reach it through usePlayer(); tests build one with createPlayer().
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
    playlistId: string | null;
};

export type QueueSource = {
    playlistId: string | null;
    title: string;
};

export type DebugEntry = {
    at: number;
    kind: 'load' | 'state' | 'error' | 'send' | 'info';
    message: string;
};

export type PlayerDeps = {
    sender: ListenSender;
    storage?: Pick<Storage, 'getItem' | 'setItem'> | null;
    now?: () => number;
    notify?: (message: string) => void;
};

type PlayerState = {
    queue: QueueTrack[];
    index: number;
    playing: boolean;
    elapsed: number;
    duration: number;
    volume: number;
    muted: boolean;
    source: QueueSource | null;
    origin: App.Enums.ListenOrigin;
    queueOpen: boolean;
    ready: boolean;
    unavailable: boolean;
    actualVideoId: string | null;
};

type PersistedState = Pick<
    PlayerState,
    | 'queue'
    | 'index'
    | 'elapsed'
    | 'volume'
    | 'muted'
    | 'source'
    | 'origin'
    | 'queueOpen'
>;

const STORAGE_KEY = 'sonder.player.v1';
const POLL_MS = 250;
const RESTART_THRESHOLD_SECONDS = 3;
const FALLBACK_SECONDS = 210;
const MAX_STORED_TRACKS = 300;
const MAX_DEBUG_ENTRIES = 200;
const AD_DURATION_TOLERANCE_SECONDS = 5;

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
    playlistId: string | null = null,
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
        playlistId,
    };
}

export function createPlayer(deps: PlayerDeps) {
    const now = deps.now ?? (() => Date.now());
    const notify = deps.notify ?? (() => undefined);

    const state = reactive<PlayerState>({
        queue: [],
        index: -1,
        playing: false,
        elapsed: 0,
        duration: 0,
        volume: 70,
        muted: false,
        source: null,
        origin: 'playlist',
        queueOpen: false,
        ready: false,
        unavailable: false,
        actualVideoId: null,
    });
    const debug = reactive<{ entries: DebugEntry[] }>({ entries: [] });

    let transport: PlayerTransport | null = null;
    let listen: Listen | null = null;
    let poll: ReturnType<typeof setInterval> | null = null;
    // The player's position means something only once the current track has
    // played: before that (loading, blocked, merely cued) it can read 0 and
    // would overwrite the restored position.
    let positionTrusted = false;

    const current = computed<QueueTrack | null>(
        () => state.queue[state.index] ?? null,
    );
    const totalSeconds = computed(() =>
        state.duration > 0
            ? state.duration
            : (current.value?.durationSeconds ?? 0),
    );
    const progress = computed(() =>
        totalSeconds.value > 0
            ? Math.min(1, state.elapsed / totalSeconds.value)
            : 0,
    );
    const upNext = computed(() => state.queue.slice(state.index + 1));
    const diagnostics = computed(() => {
        const expectedVideoId = current.value?.videoId ?? null;
        const storedDuration = current.value?.duration
            ? current.value.durationSeconds
            : null;
        const isOtherVideo =
            state.actualVideoId !== null &&
            expectedVideoId !== null &&
            state.actualVideoId !== expectedVideoId;
        const isDurationOff =
            state.duration > 0 &&
            storedDuration !== null &&
            Math.abs(state.duration - storedDuration) >
                AD_DURATION_TOLERANCE_SECONDS;

        return {
            expectedVideoId,
            actualVideoId: state.actualVideoId,
            playerDuration: state.duration,
            storedDuration,
            isLikelyAd: state.playing && (isOtherVideo || isDurationOff),
        };
    });

    function log(kind: DebugEntry['kind'], message: string): void {
        debug.entries.push({ at: now(), kind, message });

        if (debug.entries.length > MAX_DEBUG_ENTRIES) {
            debug.entries.splice(0, debug.entries.length - MAX_DEBUG_ENTRIES);
        }
    }

    function persist(): void {
        if (!deps.storage) {
            return;
        }

        const saved: PersistedState = {
            queue: state.queue.slice(0, MAX_STORED_TRACKS),
            index: state.index,
            elapsed: Math.floor(state.elapsed),
            volume: state.volume,
            muted: state.muted,
            source: state.source,
            origin: state.origin,
            queueOpen: state.queueOpen,
        };

        try {
            deps.storage.setItem(STORAGE_KEY, JSON.stringify(saved));
        } catch {
            // Storage can be unavailable (private mode, quota); playback still works.
        }
    }

    function restore(): void {
        if (!deps.storage) {
            return;
        }

        try {
            const saved = JSON.parse(
                deps.storage.getItem(STORAGE_KEY) ?? 'null',
            ) as Partial<PersistedState> | null;

            if (!saved || !Array.isArray(saved.queue)) {
                return;
            }

            state.queue = saved.queue;
            state.index = saved.index ?? -1;
            state.elapsed = saved.elapsed ?? 0;
            state.volume = saved.volume ?? state.volume;
            state.muted = saved.muted ?? state.muted;
            state.source = saved.source ?? null;
            state.origin = saved.origin ?? state.origin;
            state.queueOpen = saved.queueOpen ?? false;
        } catch {
            // A corrupt entry simply starts an empty player.
        }
    }

    function durationOrNull(): number | null {
        return state.duration > 0 ? state.duration : null;
    }

    function beginListen(origin: App.Enums.ListenOrigin): void {
        const track = current.value;

        if (!track?.videoId) {
            return;
        }

        listen = startListen(
            {
                videoId: track.videoId,
                title: track.title,
                artists: track.artists,
                // Tracks restored from an older stored queue have no playlistId.
                playlistId: track.playlistId ?? null,
                origin,
            },
            now(),
        );
    }

    function endListen(reason: App.Enums.ListenEndReason): void {
        if (!listen) {
            return;
        }

        const payload = finishListen(listen, reason, now(), durationOrNull());
        listen = null;
        log(
            'send',
            `${payload.end_reason} ${payload.youtube_video_id} after ${payload.listened_seconds}s`,
        );

        void deps.sender.send(payload).then((recorded) => {
            if (!recorded) {
                log('error', `listen ${payload.id} was not recorded`);
            }
        });
    }

    function sample(): void {
        if (!transport || !state.ready) {
            return;
        }

        state.actualVideoId = transport.videoId();

        if (
            !positionTrusted ||
            state.actualVideoId !== current.value?.videoId
        ) {
            return;
        }

        const position = transport.currentTime();
        const duration = transport.duration();

        state.elapsed = position;

        if (duration > 0) {
            state.duration = duration;
        }

        if (listen) {
            recordProgress(listen, position, state.playing, now());
        }
    }

    function startPolling(): void {
        if (poll === null) {
            poll = setInterval(sample, POLL_MS);
        }
    }

    function stopPolling(): void {
        if (poll !== null) {
            clearInterval(poll);
            poll = null;
        }
    }

    function stopAtEnd(): void {
        stopPolling();
        positionTrusted = false;
        // Pause first: the "paused" event samples the position, which must
        // not overwrite the reset below.
        transport?.pause();
        state.playing = false;
        state.elapsed = 0;
    }

    function loadIndex(index: number, origin: App.Enums.ListenOrigin): void {
        state.index = index;
        state.elapsed = 0;
        state.duration = 0;
        state.origin = origin;
        positionTrusted = false;

        const track = current.value;

        if (!track) {
            return;
        }

        if (!track.videoId) {
            log('error', `${track.title} has no video id, skipping it`);
            advance();

            return;
        }

        log('load', `${track.videoId} ${track.title} (${origin})`);

        // Only a track actually handed to a ready player becomes a listen;
        // otherwise (still loading, blocked script) it is cued once ready.
        if (transport && state.ready) {
            beginListen(origin);
            transport.load(track.videoId, { startAt: 0, autoplay: true });
        }
    }

    function advance(): void {
        if (state.index + 1 < state.queue.length) {
            loadIndex(state.index + 1, 'autoplay');
        } else {
            stopAtEnd();
        }
    }

    function onState(playback: PlaybackState): void {
        log('state', playback);

        if (playback === 'playing') {
            state.playing = true;
            positionTrusted = true;

            if (!listen) {
                beginListen(state.origin);
            }

            sample();
            startPolling();

            return;
        }

        if (playback === 'buffering') {
            return;
        }

        sample();
        stopPolling();
        state.playing = false;

        if (playback === 'ended') {
            endListen('ended');
            advance();
        }
    }

    function onError(code: number): void {
        log(
            'error',
            `YouTube error ${code} on ${current.value?.videoId ?? 'nothing'}`,
        );

        if (!listen) {
            beginListen(state.origin);
        }

        stopPolling();
        state.playing = false;
        endListen('error');
        notify("This track can't be played here, skipping it.");
        advance();
    }

    function attachTransport(next: PlayerTransport): void {
        if (transport) {
            // A new host (remounted layout) replaces the old player: close
            // what was playing there and wait for the new one to be ready.
            sample();
            stopPolling();
            endListen('abandoned');
        }

        transport = next;
        state.ready = false;
        state.playing = false;
        positionTrusted = false;

        next.on('ready', () => {
            state.ready = true;
            log('info', 'player ready');
            next.setVolume(state.volume);
            next.setMuted(state.muted);

            const track = current.value;

            if (track?.videoId) {
                next.load(track.videoId, {
                    startAt: Math.floor(state.elapsed),
                    autoplay: false,
                });
            }
        });
        next.on('unavailable', () => {
            state.unavailable = true;
            log('error', 'the YouTube player could not load');
            notify('Player unavailable');
        });
        next.on('state', onState);
        next.on('error', onError);
        next.on('autoplayBlocked', () => {
            stopPolling();
            state.playing = false;
            log('info', 'autoplay blocked by the browser');
        });
    }

    function playTracks(
        tracks: App.Data.TrackData[],
        startAt: number,
        source: QueueSource,
        origin: App.Enums.ListenOrigin = 'playlist',
    ): void {
        const playable = tracks
            .map((track, position) => ({ track, position }))
            .filter(({ track }) => track.isAvailable);

        if (playable.length === 0) {
            return;
        }

        const start = Math.max(
            0,
            playable.findIndex(({ position }) => position === startAt),
        );

        endListen('replaced');
        state.queue = playable.map(({ track, position }) =>
            toQueueTrack(track, position, source.playlistId),
        );
        state.source = source;
        loadIndex(start, origin);
    }

    function playNext(
        track: App.Data.TrackData,
        source: QueueSource,
        origin: App.Enums.ListenOrigin,
    ): void {
        const item = toQueueTrack(track, Date.now(), source.playlistId);

        if (state.index < 0) {
            state.queue = [item];
            state.source = source;
            loadIndex(0, origin);

            return;
        }

        endListen('picked');
        state.queue.splice(state.index + 1, 0, item);
        loadIndex(state.index + 1, origin);
    }

    function jumpTo(index: number): void {
        if (index < 0 || index >= state.queue.length) {
            return;
        }

        endListen('jumped');
        loadIndex(index, 'queue');
    }

    function next(): void {
        if (!current.value) {
            return;
        }

        endListen('skipped');

        if (state.index + 1 < state.queue.length) {
            loadIndex(state.index + 1, 'queue');
        } else {
            stopAtEnd();
        }
    }

    function seek(seconds: number): void {
        if (!transport || !state.ready) {
            return;
        }

        const target = Math.max(0, seconds);
        transport.seek(target);
        state.elapsed = target;

        if (listen) {
            markSeek(listen, target, now());
        }
    }

    function previous(): void {
        if (!current.value) {
            return;
        }

        if (state.elapsed > RESTART_THRESHOLD_SECONDS || state.index <= 0) {
            seek(0);

            return;
        }

        endListen('previous');
        loadIndex(state.index - 1, 'queue');
    }

    function toggle(): void {
        if (!current.value || !transport || !state.ready) {
            return;
        }

        if (state.playing) {
            transport.pause();
        } else {
            transport.play();
        }
    }

    function setVolume(volume: number): void {
        state.volume = Math.max(0, Math.min(100, volume));
        state.muted = state.volume === 0;
        transport?.setVolume(state.volume);
        transport?.setMuted(state.muted);
    }

    function toggleMute(): void {
        state.muted = !state.muted;
        transport?.setMuted(state.muted);
    }

    function handlePageHide(): void {
        sample();

        if (listen) {
            const payload = finishListen(
                listen,
                'abandoned',
                now(),
                durationOrNull(),
            );
            listen = null;
            deps.sender.sendOnUnload(payload);
        }

        persist();
    }

    restore();
    watch(
        () => [
            state.queue,
            state.index,
            state.volume,
            state.muted,
            state.source,
            state.origin,
            state.queueOpen,
        ],
        persist,
        { deep: true },
    );

    return {
        state,
        debug,
        current,
        progress,
        totalSeconds,
        upNext,
        diagnostics,
        attachTransport,
        playTracks,
        playNext,
        jumpTo,
        next,
        previous,
        toggle,
        seek,
        setVolume,
        toggleMute,
        toggleQueue: () => {
            state.queueOpen = !state.queueOpen;
        },
        handlePageHide,
        isPlayingFrom: (playlistId: string) =>
            state.source?.playlistId === playlistId && current.value !== null,
    };
}

export type Player = ReturnType<typeof createPlayer>;

export const playerKey: InjectionKey<Player> = Symbol('player');

let defaultPlayer: Player | null = null;

function browserStorage(): Storage | null {
    if (typeof window === 'undefined') {
        return null;
    }

    try {
        return window.localStorage;
    } catch {
        return null;
    }
}

function getDefaultPlayer(): Player {
    if (defaultPlayer === null) {
        const { toast } = useToast();

        defaultPlayer = createPlayer({
            sender: createListenSender(),
            storage: browserStorage(),
            notify: (message) => toast(trans(message)),
        });
    }

    return defaultPlayer;
}

/**
 * The app-wide player. A component tree can provide its own under
 * `playerKey` (component tests do).
 */
export function usePlayer(): Player {
    const provided = getCurrentInstance() ? inject(playerKey, null) : null;

    return provided ?? getDefaultPlayer();
}

export function formatSeconds(seconds: number): string {
    const whole = Math.max(0, Math.floor(seconds));

    return `${Math.floor(whole / 60)}:${String(whole % 60).padStart(2, '0')}`;
}
