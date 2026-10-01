import '../../../css/app.css';
import { expect, it } from 'vite-plus/test';
import { page } from 'vite-plus/test/browser';
import { render } from 'vitest-browser-vue';
import QueuePanel from '@/components/shell/QueuePanel.vue';
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
    };
}

async function mountQueue() {
    const player = createPlayer({
        sender: { send: async () => true, sendOnUnload: () => undefined },
        storage: null,
    });
    const transport = new FakeTransport();
    player.attachTransport(transport);
    transport.becomeReady();
    player.playTracks([track('a', 'Survival'), track('b', 'Hysteria')], 0, {
        playlistId: 'PL1',
        title: 'Mix',
    });
    player.toggleQueue();

    await render(QueuePanel, {
        global: {
            provide: { [playerKey]: player },
            mocks: {
                $t: (key: string, params?: Record<string, string>) =>
                    key.replace(':title', params?.title ?? ''),
            },
        },
    });

    return { player, transport };
}

it('pauses and resumes from the now playing card', async () => {
    const { transport } = await mountQueue();

    await page.getByRole('button', { name: 'Pause Survival' }).click();
    expect(transport.calls).toContain('pause');

    await page.getByRole('button', { name: 'Play Survival' }).click();
    expect(transport.calls).toContain('play');
});

it('shows that an upcoming track can be played', async () => {
    await mountQueue();

    const row = page.getByRole('button', { name: /Hysteria/ });

    await expect.element(row).toHaveStyle({ cursor: 'pointer' });
});
