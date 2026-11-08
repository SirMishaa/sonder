import type { WritableComputedRef } from 'vue';
import { computed, ref, watch } from 'vue';

export type DiscographyTab = 'all' | 'albums' | 'singles';

/**
 * The artist page's discography tab. Reads as "all" whenever the chosen tab
 * is not offered (no singles, say), and goes back to "all" for another artist.
 */
export function useDiscographyTab(
    offered: () => DiscographyTab[],
    artistId: () => string | null,
): WritableComputedRef<DiscographyTab> {
    const chosen = ref<DiscographyTab>('all');

    watch(artistId, () => {
        chosen.value = 'all';
    });

    return computed({
        get: () => (offered().includes(chosen.value) ? chosen.value : 'all'),
        set: (tab) => {
            chosen.value = tab;
        },
    });
}
