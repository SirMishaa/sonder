/**
 * Pure accounting for one listen: how long the track was actually heard,
 * where it was left, and the payload the listens endpoint expects.
 */

/** Media may run this much ahead of the wall clock before it counts as a seek. */
const SEEK_TOLERANCE_SECONDS = 1.5;

export type ListenContext = {
    videoId: string;
    title: string;
    artists: string;
    playlistId: string | null;
    origin: App.Enums.ListenOrigin;
};

export type Listen = ListenContext & {
    id: string;
    startedAt: number;
    listenedSeconds: number;
    position: number;
    lastSample: { position: number; at: number } | null;
};

export type ListenPayload = {
    id: string;
    youtube_video_id: string;
    title: string;
    artists: string;
    youtube_playlist_id: string | null;
    origin: App.Enums.ListenOrigin;
    end_reason: App.Enums.ListenEndReason;
    started_at: string;
    ended_at: string;
    position_seconds: number;
    listened_seconds: number;
    duration_seconds: number | null;
};

export function startListen(
    context: ListenContext,
    nowMs: number,
    createId: () => string = () => crypto.randomUUID(),
): Listen {
    return {
        ...context,
        id: createId(),
        startedAt: nowMs,
        listenedSeconds: 0,
        position: 0,
        lastSample: null,
    };
}

/**
 * Feed a position sample. Media progress counts as listening only while
 * playing and only as far as the wall clock explains it, so a seek never
 * counts while a throttled background timer (one tick a minute) still does.
 */
export function recordProgress(
    listen: Listen,
    position: number,
    isPlaying: boolean,
    nowMs: number,
): void {
    if (isPlaying && listen.lastSample !== null) {
        const mediaDelta = position - listen.lastSample.position;
        const wallDelta = (nowMs - listen.lastSample.at) / 1000;

        if (
            mediaDelta > 0 &&
            mediaDelta <= wallDelta + SEEK_TOLERANCE_SECONDS
        ) {
            listen.listenedSeconds += mediaDelta;
        }
    }

    listen.lastSample = { position, at: nowMs };
    listen.position = position;
}

export function markSeek(
    listen: Listen,
    position: number,
    nowMs: number,
): void {
    listen.lastSample = { position, at: nowMs };
    listen.position = position;
}

export function finishListen(
    listen: Listen,
    reason: App.Enums.ListenEndReason,
    nowMs: number,
    durationSeconds: number | null,
): ListenPayload {
    const endedAt = Math.max(nowMs, listen.startedAt);
    const lastedSeconds = Math.floor((endedAt - listen.startedAt) / 1000);

    return {
        id: listen.id,
        youtube_video_id: listen.videoId,
        title: listen.title,
        artists: listen.artists,
        youtube_playlist_id: listen.playlistId,
        origin: listen.origin,
        end_reason: reason,
        started_at: new Date(listen.startedAt).toISOString(),
        ended_at: new Date(endedAt).toISOString(),
        position_seconds: Math.max(0, Math.floor(listen.position)),
        listened_seconds: Math.min(
            Math.floor(listen.listenedSeconds),
            lastedSeconds,
        ),
        duration_seconds:
            durationSeconds !== null && durationSeconds > 0
                ? Math.round(durationSeconds)
                : null,
    };
}
