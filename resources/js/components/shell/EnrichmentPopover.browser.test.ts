import '../../../css/app.css';
import { expect, it } from 'vite-plus/test';
import { page } from 'vite-plus/test/browser';
import { render } from 'vitest-browser-vue';
import EnrichmentPopover from '@/components/shell/EnrichmentPopover.vue';

function enriched(
    videoId: string,
    title: string,
): App.Data.RecentEnrichmentData {
    return {
        videoId,
        title,
        artists: 'Muse',
        thumbnailUrl: null,
        genres: ['alternative rock'],
        tags: [],
        listeners: null,
        creditCount: 12,
        year: 2012,
        enrichedAt: new Date().toISOString(),
    };
}

function summary(
    overrides: Partial<App.Data.LibraryEnrichmentData> = {},
): App.Data.LibraryEnrichmentData {
    return {
        state: 'running',
        total: 1251,
        resolved: 1000,
        notFound: 186,
        failed: 0,
        pending: 65,
        recordings: 950,
        described: 900,
        withGenre: 321,
        withTags: 654,
        updatedAt: new Date().toISOString(),
        recent: [],
        ...overrides,
    };
}

async function mount(initial: App.Data.LibraryEnrichmentData) {
    return render(EnrichmentPopover, {
        props: { summary: initial },
        global: {
            mocks: {
                $t: (key: string, params?: Record<string, string>) =>
                    key
                        .replace(':ago', params?.ago ?? '')
                        .replace(':count', params?.count ?? ''),
                $tChoice: (key: string, count: number): string =>
                    key
                        .split('|')
                        [count === 1 ? 0 : 1].replace(':count', String(count)),
            },
        },
    });
}

it('opens on where the library enrichment stands', async () => {
    await mount(summary());

    await page.getByRole('button', { name: 'Metadata enrichment' }).click();

    await expect.element(page.getByText('Live')).toBeVisible();
    await expect
        .element(page.getByRole('progressbar', { name: 'Tracks looked up' }))
        .toHaveAttribute('aria-valuenow', '1186');
    await expect.element(page.getByText('321')).toBeVisible();
});

it('follows live updates', async () => {
    const screen = await mount(summary());
    await page.getByRole('button', { name: 'Metadata enrichment' }).click();

    await screen.rerender({
        summary: summary({
            state: 'attention',
            resolved: 1060,
            pending: 0,
            failed: 5,
            withGenre: 340,
        }),
    });

    await expect.element(page.getByText('Needs a retry')).toBeVisible();
    await expect
        .element(
            page.getByText(
                '5 tracks could not be looked up and will be retried tomorrow.',
            ),
        )
        .toBeVisible();
    await expect.element(page.getByText('340')).toBeVisible();
});

it('slides each newly enriched track in at the top', async () => {
    const screen = await mount(
        summary({ recent: [enriched('a', 'Survival')] }),
    );
    await page.getByRole('button', { name: 'Metadata enrichment' }).click();
    await expect.element(page.getByText(/12 credits/)).toBeVisible();

    await screen.rerender({
        summary: summary({
            recent: [enriched('b', 'Madness'), enriched('a', 'Survival')],
        }),
    });

    await expect
        .poll(() =>
            Array.from(
                document.querySelectorAll('.feed-item p:first-child'),
                (title) => title.textContent?.trim(),
            ),
        )
        .toEqual(['Madness', 'Survival']);
});

it('keeps the panel nearly opaque over artwork', async () => {
    await mount(summary());
    await page.getByRole('button', { name: 'Metadata enrichment' }).click();

    const panel = document.querySelector<HTMLElement>('.enrichment-panel');

    expect(panel).not.toBeNull();
    expect(getComputedStyle(panel!).backgroundImage).toContain('0.94');
});

it('shows tags beside genres and how many people listen', async () => {
    await mount(
        summary({
            recent: [
                {
                    ...enriched('a', 'Loreley'),
                    genres: ['gothic rock'],
                    tags: ['german', 'dark'],
                    listeners: 171227,
                },
            ],
        }),
    );
    await page.getByRole('button', { name: 'Metadata enrichment' }).click();

    await expect.element(page.getByText('654')).toBeVisible();
    await expect.element(page.getByText('gothic rock')).toBeVisible();
    await expect.element(page.getByText('german')).toBeVisible();
    await expect.element(page.getByText('dark')).toBeVisible();
    await expect.element(page.getByText(/171K listeners/)).toBeVisible();
});
