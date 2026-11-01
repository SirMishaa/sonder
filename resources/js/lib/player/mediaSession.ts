import { watch } from 'vue';
import type { Player } from '@/composables/usePlayer';

/**
 * Mirrors the player into the OS media controls (lock screen, Control
 * Center, AirPods and headset buttons, keyboard media keys) and routes their
 * actions back to the player.
 */

type SessionLike = Pick<
    MediaSession,
    'setActionHandler' | 'setPositionState'
> & {
    metadata: MediaMetadata | null;
    playbackState: MediaSessionPlaybackState;
};

export type MediaSessionDeps = {
    session?: SessionLike | null;
    createMetadata?: (init: MediaMetadataInit) => MediaMetadata;
};

const SEEK_STEP_SECONDS = 10;

function browserSession(): SessionLike | null {
    return typeof navigator !== 'undefined' && 'mediaSession' in navigator
        ? navigator.mediaSession
        : null;
}

/** Binds the player to the media session. Returns an unbind function. */
export function bindMediaSession(
    player: Player,
    deps: MediaSessionDeps = {},
): () => void {
    const session =
        deps.session === undefined ? browserSession() : deps.session;

    if (!session) {
        return () => undefined;
    }

    const createMetadata =
        deps.createMetadata ?? ((init) => new MediaMetadata(init));

    const handlers: Partial<
        Record<MediaSessionAction, MediaSessionActionHandler>
    > = {
        play: () => player.play(),
        pause: () => player.pause(),
        stop: () => player.pause(),
        previoustrack: () => player.previous(),
        nexttrack: () => player.next(),
        seekto: (details) => {
            if (details.seekTime !== undefined) {
                player.seek(details.seekTime);
            }
        },
        seekbackward: (details) =>
            player.seek(
                player.state.elapsed -
                    (details.seekOffset ?? SEEK_STEP_SECONDS),
            ),
        seekforward: (details) =>
            player.seek(
                player.state.elapsed +
                    (details.seekOffset ?? SEEK_STEP_SECONDS),
            ),
    };

    for (const [action, handler] of Object.entries(handlers)) {
        try {
            session.setActionHandler(action as MediaSessionAction, handler);
        } catch {
            // Browsers throw on actions they do not support.
        }
    }

    function updatePosition(): void {
        const duration = player.totalSeconds.value;

        if (!player.current.value || duration <= 0) {
            return;
        }

        try {
            session!.setPositionState({
                duration,
                position: Math.min(duration, Math.max(0, player.state.elapsed)),
                playbackRate: 1,
            });
        } catch {
            // An inconsistent position (mid track change) is simply skipped.
        }
    }

    const stopMetadata = watch(
        () => player.current.value,
        (track) => {
            session.metadata = track
                ? createMetadata({
                      title: track.title,
                      artist: track.artists,
                      album: track.album ?? '',
                      artwork: track.thumbnailUrl
                          ? [{ src: track.thumbnailUrl }]
                          : [],
                  })
                : null;
        },
        { immediate: true },
    );

    const stopPlayback = watch(
        () => [player.state.playing, player.totalSeconds.value] as const,
        ([playing]) => {
            session.playbackState = player.current.value
                ? playing
                    ? 'playing'
                    : 'paused'
                : 'none';
            updatePosition();
        },
        { immediate: true },
    );

    // Elapsed ticks every 250 ms; only a jump (seek, new track) needs to be
    // told, the OS extrapolates the rest from the playback rate.
    const stopSeek = watch(
        () => player.state.elapsed,
        (elapsed, before) => {
            if (Math.abs(elapsed - before) > 2) {
                updatePosition();
            }
        },
    );

    return () => {
        stopMetadata();
        stopPlayback();
        stopSeek();

        for (const action of Object.keys(handlers)) {
            try {
                session.setActionHandler(action as MediaSessionAction, null);
            } catch {
                // Unsupported action, nothing to unbind.
            }
        }
    };
}
