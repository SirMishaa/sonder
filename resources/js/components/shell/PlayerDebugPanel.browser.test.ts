import { expect, it } from 'vite-plus/test';
import { page } from 'vite-plus/test/browser';
import { render } from 'vitest-browser-vue';
import PlayerDebugPanel from '@/components/shell/PlayerDebugPanel.vue';
import { createPlayer, playerKey } from '@/composables/usePlayer';
import { FakeTransport } from '@/lib/player/fakeTransport';

async function mountPanel() {
    const player = createPlayer({
        sender: { send: async () => true, sendOnUnload: () => undefined },
        storage: null,
    });
    const transport = new FakeTransport();
    player.attachTransport(transport);
    transport.becomeReady();
    player.playTracks(
        [
            {
                videoId: 'abc',
                title: 'Survival',
                artists: 'Muse',
                album: null,
                duration: '3:00',
                durationSeconds: 180,
                thumbnailUrl: null,
                isExplicit: false,
                isAvailable: true,
                genres: [],
            },
            {
                videoId: 'def',
                title: 'Psycho',
                artists: 'Muse',
                album: null,
                duration: '5:17',
                durationSeconds: 317,
                thumbnailUrl: null,
                isExplicit: false,
                isAvailable: true,
                genres: [],
            },
        ],
        0,
        { playlistId: 'PL1', title: 'Mix' },
    );

    await render(PlayerDebugPanel, {
        global: {
            provide: { [playerKey]: player },
            mocks: { $t: (key: string) => key },
        },
    });

    return { player, transport };
}

it('shows the player state and the expected video', async () => {
    await mountPanel();

    await expect
        .element(page.getByText('playing', { exact: true }))
        .toBeVisible();
    await expect
        .element(page.getByText('abc', { exact: true }).first())
        .toBeVisible();
});

it('flags a likely ad when another video is playing', async () => {
    const { transport } = await mountPanel();

    transport.currentVideo = 'ad-video';
    transport.emitState('playing');

    await expect.element(page.getByText('Likely ad')).toBeVisible();
});

it('lists player errors', async () => {
    const { transport } = await mountPanel();

    transport.fail(150);

    await expect
        .element(page.getByText(/YouTube error 150 on abc/))
        .toBeVisible();
});
