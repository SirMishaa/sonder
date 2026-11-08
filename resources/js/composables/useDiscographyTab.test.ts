import { describe, expect, it } from 'vite-plus/test';
import { nextTick, ref } from 'vue';
import type { DiscographyTab } from '@/composables/useDiscographyTab';
import { useDiscographyTab } from '@/composables/useDiscographyTab';

describe('useDiscographyTab', () => {
    it('falls back to all when the chosen tab is not offered', () => {
        const offered = ref<DiscographyTab[]>(['all', 'albums', 'singles']);
        const tab = useDiscographyTab(
            () => offered.value,
            () => 'UC1',
        );

        tab.value = 'singles';
        expect(tab.value).toBe('singles');

        offered.value = [];
        expect(tab.value).toBe('all');
    });

    it('goes back to all for another artist', async () => {
        const artistId = ref('UC1');
        const tab = useDiscographyTab(
            () => ['all', 'albums', 'singles'],
            () => artistId.value,
        );

        tab.value = 'albums';
        artistId.value = 'UC2';
        await nextTick();

        expect(tab.value).toBe('all');
    });
});
