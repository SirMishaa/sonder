import '../../../css/app.css';
import { afterEach, expect, it } from 'vite-plus/test';
import { page } from 'vite-plus/test/browser';
import { render } from 'vitest-browser-vue';
import { defineComponent, h } from 'vue';
import MiniPlayer from '@/components/shell/MiniPlayer.vue';
import NowPlayingSheet from '@/components/shell/NowPlayingSheet.vue';
import QueueSheet from '@/components/shell/QueueSheet.vue';
import { useMobileSheets } from '@/composables/useMobileSheets';
import { createPlayer, playerKey } from '@/composables/usePlayer';
import { FakeTransport } from '@/lib/player/fakeTransport';

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
        genres: [],
    };
}

async function mountMobilePlayer() {
    const player = createPlayer({
        sender: { send: async () => true, sendOnUnload: () => undefined },
        storage: null,
    });
    const transport = new FakeTransport();
    player.attachTransport(transport);
    transport.becomeReady();
    player.playTracks(
        [
            track('a', 'Survival'),
            track('b', 'Hysteria'),
            track('c', 'Uprising'),
        ],
        0,
        { playlistId: 'PL1', title: 'Mix' },
    );

    const Shell = defineComponent(
        () => () =>
            h('div', [h(MiniPlayer), h(NowPlayingSheet), h(QueueSheet)]),
    );

    await render(Shell, {
        global: {
            provide: { [playerKey]: player },
            mocks: { $t: (key: string) => key },
        },
    });

    return { player, transport };
}

afterEach(() => useMobileSheets().closeAll());

it('opens now playing from the mini player and skips from there', async () => {
    const { transport } = await mountMobilePlayer();

    await page.getByRole('button', { name: 'Open now playing' }).click();
    const sheet = page.getByRole('dialog', { name: 'Now playing' });
    await expect.element(sheet).toBeVisible();

    await sheet.getByRole('button', { name: 'Next' }).click();

    expect(transport.videoId()).toBe('b');
});

it('seeks with the thumb-sized seek bar', async () => {
    const { transport } = await mountMobilePlayer();

    await page.getByRole('button', { name: 'Open now playing' }).click();
    await page.getByRole('slider', { name: 'Playback progress' }).fill('90');

    expect(transport.position).toBe(90);
});

it('shows up next in its own sheet and clears it', async () => {
    const { player } = await mountMobilePlayer();

    await page.getByRole('button', { name: 'Open now playing' }).click();
    await page.getByRole('button', { name: /Up next/ }).click();
    const queue = page.getByRole('dialog', { name: 'Queue' });
    await expect.element(queue.getByText('Hysteria')).toBeVisible();

    await queue.getByRole('button', { name: 'Clear' }).click();

    expect(player.upNext.value).toEqual([]);
});
