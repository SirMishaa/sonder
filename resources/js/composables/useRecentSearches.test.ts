// @vitest-environment happy-dom

import { beforeEach, describe, expect, it } from 'vite-plus/test';
import { useRecentSearches } from '@/composables/useRecentSearches';
import type { RecentItem } from '@/composables/useRecentSearches';

function query(label: string): RecentItem {
    return { id: label, kind: 'query', label };
}

describe('useRecentSearches', () => {
    beforeEach(() => {
        localStorage.clear();
    });

    it('keeps eight recent items newest first', () => {
        const { items, add } = useRecentSearches();

        for (let index = 1; index <= 10; index++) {
            add(query(`query ${index}`));
        }

        expect(items.value.map((item) => item.label)).toEqual([
            'query 10',
            'query 9',
            'query 8',
            'query 7',
            'query 6',
            'query 5',
            'query 4',
            'query 3',
        ]);
        expect(
            useRecentSearches().items.value.map((item) => item.label),
        ).toEqual(items.value.map((item) => item.label));
    });

    it('moves a repeated item to the top', () => {
        const { items, add, remove } = useRecentSearches();

        add({ id: 'UC1', kind: 'artist', label: 'Rammstein' });
        add(query('mutter'));
        add({ id: 'UC1', kind: 'artist', label: 'Rammstein', sub: 'Artist' });

        expect(items.value).toEqual([
            { id: 'UC1', kind: 'artist', label: 'Rammstein', sub: 'Artist' },
            query('mutter'),
        ]);

        remove('mutter');

        expect(items.value.map((item) => item.id)).toEqual(['UC1']);
    });
});
