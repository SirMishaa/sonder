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

    it('follows history back to the query it last requested', async () => {
        const query = ref('');
        const { text, requested } = useSearchText(() => query.value);

        text.value = 'ram';
        requested('ram');
        query.value = 'ram';
        await nextTick();
        query.value = 'x';
        await nextTick();
        query.value = 'ram';
        await nextTick();

        expect(text.value).toBe('ram');
    });

    it('stays in sync through back and forward', async () => {
        const query = ref('');
        const { text, requested } = useSearchText(() => query.value);

        text.value = 'a';
        requested('a');
        query.value = 'a';
        await nextTick();
        text.value = 'b';
        requested('b');
        query.value = 'b';
        await nextTick();

        for (const step of ['a', 'b', 'a', 'b']) {
            query.value = step;
            await nextTick();

            expect(text.value).toBe(step);
        }
    });

    it('squishes the typed text', () => {
        const { text, current } = useSearchText(() => '');

        text.value = '  du   hast ';

        expect(current()).toBe('du hast');
    });
});
