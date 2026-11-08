// @vitest-environment happy-dom

import { beforeEach, describe, expect, it } from 'vite-plus/test';
import { nextTick } from 'vue';
import { useCatalogSort } from '@/composables/useCatalogSort';

type Item = { title: string; year: number | null };

const yearOf = (item: Item) => item.year;
const titleOf = (item: Item) => item.title;

function titles(items: Item[]): string[] {
    return items.map(titleOf);
}

describe('useCatalogSort', () => {
    beforeEach(() => {
        localStorage.clear();
    });

    it('sorts newest first by default', () => {
        const { sort, apply } = useCatalogSort('songs');

        expect(sort.value).toBe('recent');
        expect(
            titles(
                apply(
                    [
                        { title: 'a', year: 2001 },
                        { title: 'b', year: 2020 },
                        { title: 'c', year: 2010 },
                    ],
                    yearOf,
                    titleOf,
                ),
            ),
        ).toEqual(['b', 'c', 'a']);
    });

    it('keeps provider order for equal years', () => {
        const { apply } = useCatalogSort('songs');

        expect(
            titles(
                apply(
                    [
                        { title: 'x', year: 2020 },
                        { title: 'y', year: 2020 },
                        { title: 'z', year: 2020 },
                    ],
                    yearOf,
                    titleOf,
                ),
            ),
        ).toEqual(['x', 'y', 'z']);
    });

    it('sorts oldest first and unknown years last in both directions', () => {
        const { sort, apply } = useCatalogSort('songs');
        const items = [
            { title: 'none', year: null },
            { title: 'new', year: 2020 },
            { title: 'old', year: 1990 },
        ];

        expect(titles(apply(items, yearOf, titleOf))).toEqual([
            'new',
            'old',
            'none',
        ]);

        sort.value = 'oldest';

        expect(titles(apply(items, yearOf, titleOf))).toEqual([
            'old',
            'new',
            'none',
        ]);
    });

    it('sorts A to Z with French collation', () => {
        const { sort, apply } = useCatalogSort('songs');
        sort.value = 'az';

        expect(
            titles(
                apply(
                    ['Zeit', 'Ängste', 'Adieu'].map((title) => ({
                        title,
                        year: null,
                    })),
                    yearOf,
                    titleOf,
                ),
            ),
        ).toEqual(['Adieu', 'Ängste', 'Zeit']);
    });

    it('popular keeps the given order', () => {
        const { sort, apply } = useCatalogSort('songs');
        sort.value = 'popular';

        expect(
            titles(
                apply(
                    [
                        { title: 'b', year: 2001 },
                        { title: 'a', year: 2020 },
                    ],
                    yearOf,
                    titleOf,
                ),
            ),
        ).toEqual(['b', 'a']);
    });

    it('persists the choice per storage key', async () => {
        useCatalogSort('albums').sort.value = 'az';
        await nextTick();

        expect(useCatalogSort('albums').sort.value).toBe('az');
        expect(useCatalogSort('songs').sort.value).toBe('recent');
    });

    it('falls back to newest for an unknown stored value', () => {
        localStorage.setItem('sonder.sort.songs', 'loudest');

        expect(useCatalogSort('songs').sort.value).toBe('recent');
    });
});
