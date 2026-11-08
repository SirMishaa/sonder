import { ref, watch } from 'vue';
import type { Ref } from 'vue';

function squish(value: string): string {
    return value.replace(/\s+/g, ' ').trim();
}

/**
 * The text of a search field bound to the page's `query` prop. A query this
 * field requested itself never overwrites the text, which may have moved on
 * while the response was in flight; any other query (history, palette) does.
 * The request is forgotten on the next query change, so going back to it
 * later through history updates the text again.
 */
export function useSearchText(query: () => string): {
    text: Ref<string>;
    current: () => string;
    requested: (query: string) => void;
} {
    const text = ref(query());
    let lastRequested: string | null = null;

    watch(query, (incoming) => {
        const isOwnResponse = incoming === lastRequested;
        lastRequested = null;

        if (!isOwnResponse && squish(text.value) !== incoming) {
            text.value = incoming;
        }
    });

    return {
        text,
        current: () => squish(text.value),
        requested: (requestedQuery) => {
            lastRequested = requestedQuery;
        },
    };
}
