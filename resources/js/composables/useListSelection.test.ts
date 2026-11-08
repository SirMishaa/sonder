// @vitest-environment happy-dom

import { describe, expect, it } from 'vite-plus/test';
import { nextTick, ref } from 'vue';
import { useListSelection } from '@/composables/useListSelection';

function rows(...ids: string[]): { id: string }[] {
    return ids.map((id) => ({ id }));
}

describe('useListSelection', () => {
    it('keeps the selected item when rows arrive before it', async () => {
        const items = ref(rows('discover', 'library'));
        const reset = ref('disc');
        const { selected, move } = useListSelection(items, reset);

        move(1);
        items.value = rows('top', 'track', 'discover', 'library');
        await nextTick();

        expect(items.value[selected.value].id).toBe('library');
    });

    it('selects the first row of a new query', async () => {
        const items = ref(rows('a', 'b'));
        const reset = ref('a');
        const { selected, move } = useListSelection(items, reset);

        move(1);
        reset.value = 'ab';
        items.value = rows('c', 'd');
        await nextTick();

        expect(selected.value).toBe(0);

        items.value = rows('top', 'c', 'd');
        await nextTick();

        expect(items.value[selected.value].id).toBe('c');
    });
});
