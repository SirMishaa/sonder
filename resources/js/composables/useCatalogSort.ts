import { useLocalStorage } from '@vueuse/core';
import type { Ref } from 'vue';

export type CatalogSort = 'recent' | 'oldest' | 'popular' | 'az';

export const CATALOG_SORTS: readonly CatalogSort[] = [
    'recent',
    'oldest',
    'popular',
    'az',
];

const collator = new Intl.Collator('fr', { sensitivity: 'base' });

/**
 * Sort choice for one catalog section, persisted per storage key.
 * `popular` keeps the provider order; ties and unknown years stay stable.
 */
export function useCatalogSort(storageKey: string): {
    sort: Ref<CatalogSort>;
    apply: <T>(
        items: T[],
        yearOf: (item: T) => number | null,
        titleOf: (item: T) => string,
    ) => T[];
} {
    const sort = useLocalStorage<CatalogSort>(
        `sonder.sort.${storageKey}`,
        'recent',
    );

    function apply<T>(
        items: T[],
        yearOf: (item: T) => number | null,
        titleOf: (item: T) => string,
    ): T[] {
        if (sort.value === 'popular') {
            return [...items];
        }

        const indexed = items.map((item, index) => ({ item, index }));

        if (sort.value === 'az') {
            indexed.sort(
                (a, b) =>
                    collator.compare(titleOf(a.item), titleOf(b.item)) ||
                    a.index - b.index,
            );
        } else {
            const direction = sort.value === 'oldest' ? 1 : -1;

            indexed.sort((a, b) => {
                const yearA = yearOf(a.item);
                const yearB = yearOf(b.item);

                if ((yearA == null) !== (yearB == null)) {
                    return yearA == null ? 1 : -1;
                }

                return (
                    direction * ((yearA ?? 0) - (yearB ?? 0)) ||
                    a.index - b.index
                );
            });
        }

        return indexed.map(({ item }) => item);
    }

    return { sort, apply };
}
