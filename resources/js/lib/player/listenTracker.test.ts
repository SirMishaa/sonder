import { describe, expect, it } from 'vite-plus/test';
import {
    finishListen,
    markSeek,
    recordProgress,
    startListen,
} from '@/lib/player/listenTracker';

const T0 = Date.parse('2026-10-01T10:00:00Z');

function aListen() {
    return startListen(
        {
            videoId: 'abc',
            title: 'Survival',
            artists: 'Muse',
            playlistId: 'PL1',
            origin: 'playlist',
        },
        T0,
        () => 'listen-1',
    );
}

/** Plays `seconds` of media in 250ms steps, media and wall clock in sync. */
function play(listen: ReturnType<typeof aListen>, from: number, seconds: number, at: number): number {
    let position = from;
    let now = at;

    for (let step = 0; step < seconds * 4; step++) {
        position += 0.25;
        now += 250;
        recordProgress(listen, position, true, now);
    }

    return now;
}

describe('listenTracker', () => {
    it('counts media time played while playing', () => {
        const listen = aListen();
        recordProgress(listen, 0, true, T0);

        const now = play(listen, 0, 10, T0);

        expect(finishListen(listen, 'skipped', now, 200).listened_seconds).toBe(10);
    });

    it('adds nothing while paused', () => {
        const listen = aListen();
        recordProgress(listen, 0, true, T0);
        let now = play(listen, 0, 5, T0);

        recordProgress(listen, 5, false, now + 30_000);
        now = play(listen, 5, 5, now + 30_000);

        expect(finishListen(listen, 'ended', now, 200).listened_seconds).toBe(10);
    });

    it('does not count a seek as listening', () => {
        const listen = aListen();
        recordProgress(listen, 0, true, T0);
        let now = play(listen, 0, 5, T0);

        markSeek(listen, 120, now);
        now = play(listen, 120, 5, now);

        const payload = finishListen(listen, 'skipped', now, 200);
        expect(payload.listened_seconds).toBe(10);
        expect(payload.position_seconds).toBe(125);
    });

    it('does not count a forward jump the wall clock cannot explain', () => {
        const listen = aListen();
        recordProgress(listen, 0, true, T0);

        recordProgress(listen, 90, true, T0 + 250);

        expect(finishListen(listen, 'skipped', T0 + 250, 200).listened_seconds).toBe(0);
    });

    it('counts a long gap when the wall clock moved as much (throttled background tab)', () => {
        const listen = aListen();
        recordProgress(listen, 0, true, T0);

        recordProgress(listen, 60, true, T0 + 60_000);

        expect(finishListen(listen, 'ended', T0 + 60_000, 200).listened_seconds).toBe(60);
    });

    it('builds the payload the API expects', () => {
        const listen = aListen();
        recordProgress(listen, 0, true, T0);
        const now = play(listen, 0, 3, T0);

        expect(finishListen(listen, 'picked', now, 257.6)).toEqual({
            id: 'listen-1',
            youtube_video_id: 'abc',
            title: 'Survival',
            artists: 'Muse',
            youtube_playlist_id: 'PL1',
            origin: 'playlist',
            end_reason: 'picked',
            started_at: '2026-10-01T10:00:00.000Z',
            ended_at: '2026-10-01T10:00:03.000Z',
            position_seconds: 3,
            listened_seconds: 3,
            duration_seconds: 258,
        });
    });

    it('never reports more listening than the wall clock allows', () => {
        const listen = aListen();
        recordProgress(listen, 0, true, T0);
        recordProgress(listen, 2.5, true, T0 + 1250);

        expect(finishListen(listen, 'skipped', T0 + 1250, null).listened_seconds).toBe(1);
    });

    it('reports an unknown duration as null', () => {
        expect(finishListen(aListen(), 'error', T0, 0).duration_seconds).toBeNull();
    });
});
