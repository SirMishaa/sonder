import '../../../css/app.css';
import { expect, it } from 'vite-plus/test';
import { page } from 'vite-plus/test/browser';
import { render } from 'vitest-browser-vue';
import PlayerBar from '@/components/shell/PlayerBar.vue';
import { createPlayer, playerKey } from '@/composables/usePlayer';
import { FakeTransport } from '@/lib/player/fakeTransport';

async function mountBar() {
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
                videoId: 'a',
                title: 'Survival',
                artists: 'Muse',
                album: null,
                duration: '3:00',
                durationSeconds: 180,
                thumbnailUrl: null,
                isExplicit: false,
                isAvailable: true,
                genres: ['alternative rock', 'rock'],
            },
        ],
        0,
        { playlistId: 'PL1', title: 'Mix' },
    );

    await render(PlayerBar, {
        global: {
            provide: { [playerKey]: player },
            mocks: { $t: (key: string) => key },
        },
    });

    return { player, transport };
}

it('pauses and resumes through the transport', async () => {
    const { transport } = await mountBar();

    await page.getByRole('button', { name: 'Pause' }).click();
    expect(transport.calls).toContain('pause');
    await expect
        .element(page.getByRole('button', { name: 'Play' }))
        .toBeVisible();

    await page.getByRole('button', { name: 'Play' }).click();
    expect(transport.calls).toContain('play');
});

it('seeks where the bar is clicked', async () => {
    const { transport } = await mountBar();
    const bar = page.getByTestId('seek-bar');
    const width = bar.element().getBoundingClientRect().width;

    await bar.click({ position: { x: width / 2, y: 6 } });

    expect(transport.position).toBeGreaterThan(80);
    expect(transport.position).toBeLessThan(100);
});

it('sets the volume along a curve that favours quiet levels', async () => {
    const { transport } = await mountBar();

    await page.getByRole('slider', { name: 'Volume' }).fill('50');

    expect(transport.volume).toBe(25);
});

it('invites to install the extension for volumes under 1 %', async () => {
    await mountBar();

    await expect
        .element(
            page.getByRole('link', {
                name: 'Install the Sonder extension to go below 1 %',
            }),
        )
        .toBeVisible();
});

it('shows when the extension makes the volume precise', async () => {
    const { transport } = await mountBar();

    transport.announceFineVolume(true);

    await expect
        .element(
            page.getByRole('img', {
                name: 'Precise volume, through the Sonder extension',
            }),
        )
        .toBeVisible();
});

it('no longer presents itself as a preview', async () => {
    await mountBar();

    await expect
        .element(page.getByText('Preview player'))
        .not.toBeInTheDocument();
});

it('disables the controls when the player is unavailable', async () => {
    const { player } = await mountBar();

    player.state.unavailable = true;

    await expect
        .element(page.getByRole('button', { name: 'Next  N' }))
        .toBeDisabled();
});

it('shows the main genre of the playing track', async () => {
    await mountBar();

    await expect.element(page.getByText('alternative rock')).toBeVisible();
    await expect
        .element(page.getByText('rock', { exact: true }))
        .not.toBeInTheDocument();
});

it('keeps the thumb where it is dragged, step after step', async () => {
    const { transport } = await mountBar();
    const slider = page
        .getByRole('slider', { name: 'Volume' })
        .element() as HTMLInputElement;
    const seen: string[] = [];

    for (let position = 40; position <= 70; position += 0.5) {
        slider.value = String(position);
        slider.dispatchEvent(new Event('input', { bubbles: true }));
        await new Promise((resolve) => requestAnimationFrame(resolve));
        seen.push(`${position}→${slider.value}`);
    }

    expect(
        seen.filter((entry) => {
            const [asked, shown] = entry.split('→').map(Number);

            return asked !== shown;
        }),
    ).toEqual([]);
    expect(transport.volume).toBeCloseTo(49);
});
