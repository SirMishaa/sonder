import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import { createPlayer } from '@/composables/usePlayer';
import { FakeTransport } from '@/lib/player/fakeTransport';
import type { ListenPayload } from '@/lib/player/listenTracker';

const SOURCE = { playlistId: 'PL1', title: 'Road trip' };

function track(
    videoId: string | null,
    overrides: Partial<App.Data.TrackData> = {},
): App.Data.TrackData {
    return {
        videoId,
        title: `Title ${videoId}`,
        artists: 'Artist',
        album: null,
        duration: '3:00',
        durationSeconds: 180,
        thumbnailUrl: null,
        isExplicit: false,
        isAvailable: true,
        ...overrides,
    };
}

function memoryStorage() {
    const data = new Map<string, string>();

    return {
        getItem: (key: string) => data.get(key) ?? null,
        setItem: (key: string, value: string) => {
            data.set(key, value);
        },
    };
}

function setup(
    options: {
        storage?: ReturnType<typeof memoryStorage>;
        ready?: boolean;
    } = {},
) {
    const sent: ListenPayload[] = [];
    const unloaded: ListenPayload[] = [];
    const notices: string[] = [];
    const player = createPlayer({
        sender: {
            send: async (payload) => {
                sent.push(payload);

                return true;
            },
            sendOnUnload: (payload) => {
                unloaded.push(payload);
            },
        },
        storage: options.storage ?? memoryStorage(),
        now: () => Date.now(),
        notify: (message) => notices.push(message),
    });
    const transport = new FakeTransport();
    player.attachTransport(transport);

    if (options.ready ?? true) {
        transport.becomeReady();
    }

    return { player, transport, sent, unloaded, notices };
}

/** Plays `seconds` of media with the wall clock moving in step. */
function listen(transport: FakeTransport, seconds: number): void {
    for (let step = 0; step < seconds * 4; step++) {
        transport.position += 0.25;
        vi.advanceTimersByTime(250);
    }
}

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-10-01T10:00:00Z'));
});

afterEach(() => {
    vi.useRealTimers();
});

describe('usePlayer', () => {
    it('loads the chosen track and plays it', () => {
        const { player, transport } = setup();

        player.playTracks([track('a'), track('b'), track('c')], 1, SOURCE);

        expect(transport.loads.at(-1)).toEqual({
            videoId: 'b',
            startAt: 0,
            autoplay: true,
        });
        expect(player.current.value?.videoId).toBe('b');
        expect(player.state.playing).toBe(true);
    });

    it('moves on at the end of a track and records it as ended', () => {
        const { player, transport, sent } = setup();
        player.playTracks([track('a'), track('b')], 0, SOURCE);

        listen(transport, 10);
        transport.finish();

        expect(sent[0]).toMatchObject({
            youtube_video_id: 'a',
            youtube_playlist_id: 'PL1',
            origin: 'playlist',
            end_reason: 'ended',
            listened_seconds: 10,
            position_seconds: 180,
        });
        expect(transport.loads.at(-1)?.videoId).toBe('b');

        transport.finish();
        expect(sent[1]).toMatchObject({
            youtube_video_id: 'b',
            origin: 'autoplay',
            end_reason: 'ended',
        });
        expect(player.state.playing).toBe(false);
    });

    it('records a skip and starts the next listen from the queue', () => {
        const { player, transport, sent } = setup();
        player.playTracks([track('a'), track('b')], 0, SOURCE);

        listen(transport, 5);
        player.next();
        player.next();

        expect(sent[0]).toMatchObject({
            youtube_video_id: 'a',
            end_reason: 'skipped',
            listened_seconds: 5,
            position_seconds: 5,
        });
        expect(sent[1]).toMatchObject({
            youtube_video_id: 'b',
            end_reason: 'skipped',
            origin: 'queue',
        });
        expect(player.state.playing).toBe(false);
    });

    it('goes back a track within the first three seconds', () => {
        const { player, transport, sent } = setup();
        player.playTracks([track('a'), track('b')], 1, SOURCE);

        listen(transport, 2);
        player.previous();

        expect(sent[0]).toMatchObject({
            youtube_video_id: 'b',
            end_reason: 'previous',
        });
        expect(transport.loads.at(-1)?.videoId).toBe('a');
    });

    it('restarts the same listen after three seconds', () => {
        const { player, transport, sent } = setup();
        player.playTracks([track('a'), track('b')], 0, SOURCE);

        listen(transport, 5);
        player.previous();

        expect(sent).toHaveLength(0);
        expect(transport.position).toBe(0);

        listen(transport, 2);
        player.next();
        expect(sent[0]).toMatchObject({
            youtube_video_id: 'a',
            end_reason: 'skipped',
            listened_seconds: 7,
        });
    });

    it('records a jump when a queued track is picked', () => {
        const { player, sent } = setup();
        player.playTracks([track('a'), track('b'), track('c')], 0, SOURCE);

        player.jumpTo(2);
        player.next();

        expect(sent[0]).toMatchObject({
            youtube_video_id: 'a',
            end_reason: 'jumped',
        });
        expect(sent[1]).toMatchObject({
            youtube_video_id: 'c',
            origin: 'queue',
        });
    });

    it('records a pick when a suggestion interrupts the queue', () => {
        const { player, transport, sent } = setup();
        player.playTracks([track('a'), track('b')], 0, SOURCE);

        listen(transport, 3);
        player.playNext(
            track('z'),
            { playlistId: null, title: 'Fresh finds' },
            'suggestion',
        );
        player.next();

        expect(sent[0]).toMatchObject({
            youtube_video_id: 'a',
            end_reason: 'picked',
        });
        expect(sent[1]).toMatchObject({
            youtube_video_id: 'z',
            origin: 'suggestion',
            youtube_playlist_id: null,
        });
    });

    it('records a replacement when another playlist starts', () => {
        const { player, transport, sent } = setup();
        player.playTracks([track('a'), track('b')], 0, SOURCE);

        listen(transport, 1);
        player.playTracks([track('c')], 0, {
            playlistId: 'PL2',
            title: 'Focus',
        });

        expect(sent[0]).toMatchObject({
            youtube_video_id: 'a',
            end_reason: 'replaced',
        });
        expect(player.current.value?.videoId).toBe('c');
    });

    it('skips a track YouTube refuses to embed', () => {
        const { player, transport, sent, notices } = setup();
        player.playTracks([track('a'), track('b')], 0, SOURCE);

        transport.fail(150);

        expect(sent[0]).toMatchObject({
            youtube_video_id: 'a',
            end_reason: 'error',
            listened_seconds: 0,
        });
        expect(transport.loads.at(-1)?.videoId).toBe('b');
        expect(notices).toEqual([
            "This track can't be played here, skipping it.",
        ]);
    });

    it('stops when the last track fails', () => {
        const { player, transport, sent } = setup();
        player.playTracks([track('a')], 0, SOURCE);

        transport.fail(101);

        expect(sent[0]).toMatchObject({ end_reason: 'error' });
        expect(transport.loads).toHaveLength(1);
        expect(player.state.playing).toBe(false);
    });

    it('does not count paused time', () => {
        const { player, transport, sent } = setup();
        player.playTracks([track('a'), track('b')], 0, SOURCE);

        listen(transport, 5);
        player.toggle();
        vi.advanceTimersByTime(10_000);
        player.toggle();
        listen(transport, 5);
        player.next();

        expect(sent[0]).toMatchObject({ listened_seconds: 10 });
    });

    it('sends the listen in progress when the page is closed', () => {
        const { player, transport, unloaded } = setup();
        player.playTracks([track('a')], 0, SOURCE);

        listen(transport, 42);
        player.handlePageHide();

        expect(unloaded[0]).toMatchObject({
            youtube_video_id: 'a',
            end_reason: 'abandoned',
            listened_seconds: 42,
        });
    });

    it('restores the track paused where it was, keeping its origin', () => {
        const storage = memoryStorage();
        const first = setup({ storage });
        first.player.playTracks([track('a'), track('b')], 1, SOURCE);
        listen(first.transport, 42);
        first.player.handlePageHide();

        const second = setup({ storage });

        expect(second.transport.loads.at(-1)).toEqual({
            videoId: 'b',
            startAt: 42,
            autoplay: false,
        });
        expect(second.player.state.playing).toBe(false);

        second.player.toggle();
        second.player.next();
        expect(second.sent[0]).toMatchObject({
            youtube_video_id: 'b',
            origin: 'playlist',
        });
    });

    it('ignores play until the player is ready, then cues the current track', () => {
        const { player, transport } = setup({ ready: false });
        player.playTracks([track('a')], 0, SOURCE);
        transport.calls.length = 0;

        player.toggle();
        expect(transport.calls).toEqual([]);

        transport.becomeReady();
        expect(transport.loads.at(-1)).toEqual({
            videoId: 'a',
            startAt: 0,
            autoplay: false,
        });
    });

    it('skips tracks without a video id', () => {
        const { player, transport, sent } = setup();

        player.playTracks([track(null), track('b')], 0, SOURCE);

        expect(transport.loads.map((load) => load.videoId)).toEqual(['b']);
        expect(sent).toHaveLength(0);
    });

    it('stops cleanly when no track has a video id', () => {
        const { player, transport } = setup();

        player.playTracks([track(null), track(null)], 0, SOURCE);

        expect(transport.loads).toHaveLength(0);
        expect(player.state.playing).toBe(false);
    });

    it('records each track of a rapid double skip', () => {
        const { player, sent } = setup();
        player.playTracks([track('a'), track('b'), track('c')], 0, SOURCE);

        player.next();
        player.next();

        expect(
            sent.map((listen) => [
                listen.youtube_video_id,
                listen.end_reason,
                listen.listened_seconds,
            ]),
        ).toEqual([
            ['a', 'skipped', 0],
            ['b', 'skipped', 0],
        ]);
    });

    it('flags the player as unavailable when YouTube cannot load', () => {
        const { player, transport, notices } = setup({ ready: false });

        transport.becomeUnavailable();

        expect(player.state.unavailable).toBe(true);
        expect(notices).toEqual(['Player unavailable']);
    });

    it('treats a blocked autoplay as paused', () => {
        const { player, transport } = setup();
        player.playTracks([track('a')], 0, SOURCE);

        transport.blockAutoplay();

        expect(player.state.playing).toBe(false);
    });

    it('drives volume, mute and seek through the transport', () => {
        const { player, transport } = setup();
        player.playTracks([track('a')], 0, SOURCE);

        player.setVolume(30);
        player.toggleMute();
        player.seek(90);

        expect(transport.volume).toBe(30);
        expect(transport.muted).toBe(true);
        expect(transport.position).toBe(90);
        expect(player.state.elapsed).toBe(90);
    });

    it('flags a likely ad when another video plays', () => {
        const { player, transport } = setup();
        player.playTracks([track('a')], 0, SOURCE);

        transport.currentVideo = 'ad-video';
        vi.advanceTimersByTime(250);

        expect(player.diagnostics.value).toMatchObject({
            expectedVideoId: 'a',
            actualVideoId: 'ad-video',
            isLikelyAd: true,
        });
    });
});
