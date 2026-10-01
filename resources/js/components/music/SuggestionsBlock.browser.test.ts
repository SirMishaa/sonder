import '../../../css/app.css';
import { expect, it } from 'vite-plus/test';
import { page } from 'vite-plus/test/browser';
import { render } from 'vitest-browser-vue';
import SuggestionsBlock from '@/components/music/SuggestionsBlock.vue';
import { createPlayer, playerKey } from '@/composables/usePlayer';
import { FakeTransport } from '@/lib/player/fakeTransport';
import type { ListenPayload } from '@/lib/player/listenTracker';

function track(videoId: string, title: string): App.Data.TrackData {
    return {
        videoId,
        title,
        artists: 'Muse',
        album: null,
        duration: '3:00',
        durationSeconds: 180,
        thumbnailUrl: null,
        isExplicit: false,
        isAvailable: true,
    };
}

it('plays a suggestion right away, as a suggestion', async () => {
    const unloaded: ListenPayload[] = [];
    const player = createPlayer({
        sender: {
            send: async () => true,
            sendOnUnload: (payload) => unloaded.push(payload),
        },
        storage: null,
    });
    const transport = new FakeTransport();
    player.attachTransport(transport);
    transport.becomeReady();

    await render(SuggestionsBlock, {
        props: {
            pool: [
                {
                    track: track('b', 'Hysteria'),
                    playlistId: 'PL9',
                    playlistTitle: 'Elsewhere',
                },
            ],
            playlistTracks: [track('a', 'Survival')],
            playlistTitle: 'Late focus',
        },
        global: {
            provide: { [playerKey]: player },
            mocks: {
                $t: (key: string, params?: Record<string, string>) =>
                    key.replace(':title', params?.title ?? ''),
                $tChoice: (key: string) => key,
            },
        },
    });

    await page.getByRole('button', { name: 'Play Hysteria' }).click();

    expect(player.current.value?.videoId).toBe('b');
    expect(transport.videoId()).toBe('b');

    player.handlePageHide();
    expect(unloaded[0]).toMatchObject({
        youtube_video_id: 'b',
        origin: 'suggestion',
    });
});
