import { useHttp } from '@inertiajs/vue3';
import { useTimeoutFn } from '@vueuse/core';
import { ref, watch } from 'vue';
import type { Ref } from 'vue';
import SearchSuggestionController from '@/actions/App/Http/Controllers/SearchSuggestionController';

/** Fetches the catalog suggestions for a query; the seam tests replace. */
export type SuggestionFetcher = {
    /** Rejects on a throttled (429), failed (503) or lost request. */
    fetch: (query: string) => Promise<App.Data.SearchResultsData | undefined>;
    cancel: () => void;
};

const DEBOUNCE_MS = 200;
const MIN_LENGTH = 2;

/** Suggestions through Inertia's `useHttp`, which aborts on `cancel()`. */
export function httpSuggestionFetcher(): SuggestionFetcher {
    const http = useHttp<{ q: string }, App.Data.SearchResultsData>({ q: '' });

    return {
        fetch: (query) => {
            http.q = query;

            return http.get(SearchSuggestionController.index.url());
        },
        cancel: () => http.cancel(),
    };
}

/**
 * Catalog suggestions for the palette's text, debounced, from two characters
 * on. Each new query cancels the request in flight, and a response for an
 * older query is dropped. A failed request clears the results and sets
 * `failed`, so the palette keeps its library results and says the catalog is
 * unavailable.
 */
export function useCatalogSuggestions(
    query: Ref<string>,
    fetcher: SuggestionFetcher = httpSuggestionFetcher(),
): {
    results: Ref<App.Data.SearchResultsData | null>;
    loading: Ref<boolean>;
    failed: Ref<boolean>;
} {
    const results = ref<App.Data.SearchResultsData | null>(null);
    const loading = ref(false);
    const failed = ref(false);
    let latest = 0;
    let pending = '';

    const debounce = useTimeoutFn(
        () => {
            void request(pending);
        },
        DEBOUNCE_MS,
        { immediate: false },
    );

    async function request(text: string): Promise<void> {
        const ticket = ++latest;
        fetcher.cancel();

        try {
            const response = await fetcher.fetch(text);

            if (ticket !== latest) {
                return;
            }

            if (response === undefined) {
                throw new Error('Invalid suggestions request');
            }

            results.value = response;
            failed.value = false;
        } catch {
            if (ticket !== latest) {
                return;
            }

            results.value = null;
            failed.value = true;
        }

        loading.value = false;
    }

    watch(query, (value) => {
        const text = value.replace(/\s+/g, ' ').trim();

        if (text.length < MIN_LENGTH) {
            debounce.stop();
            latest++;
            fetcher.cancel();
            results.value = null;
            loading.value = false;
            failed.value = false;

            return;
        }

        pending = text;
        loading.value = true;
        debounce.start();
    });

    return { results, loading, failed };
}
