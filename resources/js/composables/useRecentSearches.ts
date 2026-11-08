import { ref } from 'vue';
import type { Ref } from 'vue';

/** A query searched, or an artist or album opened, from the palette. */
export type RecentItem = {
    /** The catalog id of an artist or album, the text of a query. */
    id: string;
    kind: 'query' | 'artist' | 'album';
    label: string;
    sub?: string;
    artwork?: string | null;
};

const STORAGE_KEY = 'sonder.search.recent';
const MAX_ITEMS = 8;

function read(): RecentItem[] {
    try {
        const stored: unknown = JSON.parse(
            localStorage.getItem(STORAGE_KEY) ?? '[]',
        );

        return Array.isArray(stored)
            ? stored
                  .filter(
                      (item): item is RecentItem =>
                          typeof item?.id === 'string' &&
                          typeof item?.label === 'string' &&
                          ['query', 'artist', 'album'].includes(item?.kind),
                  )
                  .slice(0, MAX_ITEMS)
            : [];
    } catch {
        return [];
    }
}

function write(items: RecentItem[]): void {
    try {
        localStorage.setItem(STORAGE_KEY, JSON.stringify(items));
    } catch {
        // Storage may be full or blocked; recents are a convenience.
    }
}

/**
 * The palette's recent searches: eight at most, newest first, one entry per
 * id. Stored in the browser and read again on each use.
 */
export function useRecentSearches(): {
    items: Ref<RecentItem[]>;
    add: (item: RecentItem) => void;
    remove: (id: string) => void;
} {
    const items = ref<RecentItem[]>(read());

    function save(next: RecentItem[]): void {
        items.value = next;
        write(next);
    }

    return {
        items,
        add: (item) =>
            save(
                [item, ...items.value.filter(({ id }) => id !== item.id)].slice(
                    0,
                    MAX_ITEMS,
                ),
            ),
        remove: (id) => save(items.value.filter((item) => item.id !== id)),
    };
}
