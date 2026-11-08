// @vitest-environment happy-dom

import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import { nextTick, ref } from 'vue';
import { useCatalogSuggestions } from '@/composables/useCatalogSuggestions';
import type { SuggestionFetcher } from '@/composables/useCatalogSuggestions';

function resultsFor(title: string): App.Data.SearchResultsData {
    return {
        topArtist: null,
        topArtistInLibrary: null,
        topArtistLibraryTracks: [],
        tracks: [
            {
                videoId: title,
                title,
                artists: 'Rammstein',
                album: null,
                duration: null,
                durationSeconds: null,
                thumbnailUrl: null,
                isExplicit: false,
                isAvailable: true,
                genres: [],
                artistId: null,
                albumId: null,
                year: null,
                plays: null,
                inLibrary: false,
            },
        ],
        albums: [],
        artists: [],
        playlists: [],
    };
}

type Pending = {
    query: string;
    resolve: (value: App.Data.SearchResultsData) => void;
    reject: (error: Error) => void;
};

function fakeFetcher(): SuggestionFetcher & { requests: Pending[] } {
    const requests: Pending[] = [];

    return {
        requests,
        fetch: (query) =>
            new Promise((resolve, reject) => {
                requests.push({ query, resolve, reject });
            }),
        cancel: vi.fn(),
    };
}

async function type(query: { value: string }, value: string): Promise<void> {
    query.value = value;
    await nextTick();
    vi.advanceTimersByTime(200);
}

describe('useCatalogSuggestions', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('waits for two characters', async () => {
        const query = ref('');
        const fetcher = fakeFetcher();
        const { loading } = useCatalogSuggestions(query, fetcher);

        await type(query, 'r');

        expect(fetcher.requests).toHaveLength(0);
        expect(loading.value).toBe(false);

        await type(query, 'ra');

        expect(fetcher.requests.map((request) => request.query)).toEqual([
            'ra',
        ]);
    });

    it('ignores a response for an older query', async () => {
        const query = ref('');
        const fetcher = fakeFetcher();
        const { results, loading } = useCatalogSuggestions(query, fetcher);

        await type(query, 'ra');
        await type(query, 'ram');
        const [older, newer] = fetcher.requests;
        newer.resolve(resultsFor('ram'));
        await vi.runAllTimersAsync();
        older.resolve(resultsFor('ra'));
        await vi.runAllTimersAsync();

        expect(results.value?.tracks[0].title).toBe('ram');
        expect(loading.value).toBe(false);
        expect(fetcher.cancel).toHaveBeenCalled();
    });

    it('keeps library results when suggestions fail', async () => {
        const query = ref('');
        const fetcher = fakeFetcher();
        const { results, loading, failed } = useCatalogSuggestions(
            query,
            fetcher,
        );

        await type(query, 'ra');
        fetcher.requests[0].resolve(resultsFor('ra'));
        await vi.runAllTimersAsync();
        await type(query, 'ram');
        fetcher.requests[1].reject(new Error('Request failed with status 503'));
        await vi.runAllTimersAsync();

        expect(failed.value).toBe(true);
        expect(results.value).toBeNull();
        expect(loading.value).toBe(false);
    });
});
