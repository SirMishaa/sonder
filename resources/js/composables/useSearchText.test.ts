// @vitest-environment happy-dom

import { describe, expect, it } from 'vite-plus/test';
import { nextTick, ref } from 'vue';
import { useSearchText } from '@/composables/useSearchText';

describe('useSearchText', () => {
    it('keeps newer typing when an earlier search of its own lands', async () => {
        const query = ref('r');
        const { text, requested } = useSearchText(() => query.value);

        text.value = 'ra';
        requested('ra');
        text.value = 'ram';
        query.value = 'ra';
        await nextTick();

        expect(text.value).toBe('ram');
    });

    it('follows a search it did not request', async () => {
        const query = ref('ram');
        const { text, requested } = useSearchText(() => query.value);

        requested('ram');
        query.value = 'mutter';
        await nextTick();

        expect(text.value).toBe('mutter');
    });

    it('squishes the typed text', () => {
        const { text, current } = useSearchText(() => '');

        text.value = '  du   hast ';

        expect(current()).toBe('du hast');
    });
});
