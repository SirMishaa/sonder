import { describe, expect, it } from 'vite-plus/test';
import { nextTick } from 'vue';
import { createPlayer } from '@/composables/usePlayer';
import { FakeTransport } from '@/lib/player/fakeTransport';
import { bindMediaSession } from '@/lib/player/mediaSession';

function track(videoId: string): App.Data.TrackData {
    return {
        videoId,
        title: `Title ${videoId}`,
        artists: 'Artist',
        album: 'Album',
        duration: '3:00',
        durationSeconds: 180,
        thumbnailUrl: `https://img/${videoId}.jpg`,
        isExplicit: false,
        isAvailable: true,
        genres: [],
    };
}

function setup() {
    const player = createPlayer({
        sender: { send: async () => true, sendOnUnload: () => undefined },
        storage: null,
    });
    const transport = new FakeTransport();
    player.attachTransport(transport);
    transport.becomeReady();

    const handlers = new Map<MediaSessionAction, MediaSessionActionHandler>();
    const session = {
        metadata: null as MediaMetadata | null,
        playbackState: 'none' as MediaSessionPlaybackState,
        setActionHandler: (
            action: MediaSessionAction,
            handler: MediaSessionActionHandler | null,
        ) => {
            if (handler) {
                handlers.set(action, handler);
            } else {
                handlers.delete(action);
            }
        },
        setPositionState: () => undefined,
    };
    const unbind = bindMediaSession(player, {
        session,
        createMetadata: (init) => init as MediaMetadata,
    });

    return { player, transport, session, handlers, unbind };
}

describe('mediaSession', () => {
    it('shows the current track in the OS media controls', async () => {
        const { player, session } = setup();

        player.playTracks([track('a'), track('b')], 0, {
            playlistId: 'PL1',
            title: 'Mix',
        });
        await nextTick();

        expect(session.metadata).toMatchObject({
            title: 'Title a',
            artist: 'Artist',
            artwork: [{ src: 'https://img/a.jpg' }],
        });
        expect(session.playbackState).toBe('playing');
    });

    it('skips, goes back and pauses from headset buttons', async () => {
        const { player, transport, handlers } = setup();
        player.playTracks([track('a'), track('b')], 0, {
            playlistId: 'PL1',
            title: 'Mix',
        });
        const details = {} as MediaSessionActionDetails;

        handlers.get('nexttrack')?.(details);
        expect(transport.videoId()).toBe('b');

        handlers.get('previoustrack')?.(details);
        expect(transport.videoId()).toBe('a');

        handlers.get('pause')?.(details);
        expect(player.state.playing).toBe(false);

        handlers.get('play')?.(details);
        expect(player.state.playing).toBe(true);
    });

    it('releases the handlers when unbound', () => {
        const { handlers, unbind } = setup();

        unbind();

        expect(handlers.size).toBe(0);
    });
});
