import '../../../css/app.css';
import { expect, it } from 'vite-plus/test';
import { page } from 'vite-plus/test/browser';
import { render } from 'vitest-browser-vue';
import TrackRow from '@/components/music/TrackRow.vue';

function track(
    overrides: Partial<App.Data.CatalogTrackData> = {},
): App.Data.CatalogTrackData {
    return {
        videoId: 'a',
        title: 'Survival',
        artists: 'Muse',
        album: 'The 2nd Law',
        duration: '4:00',
        durationSeconds: 240,
        thumbnailUrl: null,
        isExplicit: false,
        isAvailable: true,
        genres: ['rock', 'alternative', 'prog'],
        artistId: 'UC1',
        albumId: 'MPREb_1',
        year: 2012,
        plays: 1200,
        inLibrary: true,
        ...overrides,
    };
}

function mount(
    props: Record<string, unknown>,
    attrs: Record<string, unknown> = {},
) {
    return render(TrackRow, {
        props: {
            track: track(),
            position: 1,
            current: false,
            playing: false,
            ...props,
            ...attrs,
        },
        global: {
            mocks: {
                $t: (key: string, params?: Record<string, string>) =>
                    key.replace(':title', params?.title ?? ''),
            },
        },
    });
}

it('renders genre tags and the library mark', async () => {
    await mount({});

    await expect.element(page.getByText('rock')).toBeVisible();
    await expect.element(page.getByText('alternative')).toBeVisible();
    expect(page.getByText('prog').elements()).toHaveLength(0);
    await expect
        .element(page.getByLabelText('In your library'))
        .toBeInTheDocument();
    await expect
        .element(page.getByRole('link', { name: 'Muse' }))
        .toHaveAttribute('href', expect.stringContaining('UC1'));
});

it('hides the library mark for tracks outside the library', async () => {
    await mount({ track: track({ inLibrary: false }) });

    expect(page.getByLabelText('In your library').elements()).toHaveLength(0);
});

it('hides the cover and album when asked', async () => {
    const { container } = await mount({
        showArtwork: false,
        showAlbum: false,
    });

    expect(container.querySelector('[data-track-artwork]')).toBeNull();
    expect(page.getByText('The 2nd Law').elements()).toHaveLength(0);
});

it('emits play from the play button without tapping', async () => {
    const taps: MouseEvent[] = [];
    let plays = 0;
    await mount(
        {},
        {
            onTap: (event: MouseEvent) => taps.push(event),
            onPlay: () => plays++,
        },
    );

    await page.getByRole('button', { name: 'Play Survival' }).click();

    expect(plays).toBe(1);
    expect(taps).toHaveLength(0);
});
