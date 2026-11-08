<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import ArtistController from '@/actions/App/Http/Controllers/ArtistController';
import Artwork from '@/components/music/Artwork.vue';

/** An artist as a round portrait with the name and subscriber count. */
defineProps<{
    artist: App.Data.CatalogArtistData;
}>();
</script>

<template>
    <Link
        :href="ArtistController.show(artist.id)"
        class="tile min-w-0 text-center"
    >
        <Artwork
            :src="artist.thumbnailUrl"
            :alt="artist.name"
            class="portrait aspect-square w-full rounded-full"
        />
        <span class="mt-2.5 block truncate font-semibold">{{
            artist.name
        }}</span>
        <span class="block truncate text-[12.5px] font-medium text-faint">{{
            artist.subscribers
                ? $t(':count subscribers', { count: artist.subscribers })
                : $t('Artist')
        }}</span>
    </Link>
</template>

<style scoped>
.portrait {
    box-shadow: 0 18px 30px -20px oklch(0 0 0 / 0.8);
    transition: box-shadow 240ms var(--ease-out-quint);
}

.tile:hover .portrait,
.tile:focus-visible .portrait {
    box-shadow:
        0 0 0 1px oklch(0.8 0.15 68 / 0.35),
        0 20px 34px -20px oklch(0 0 0 / 0.9);
}

.tile:focus-visible {
    outline-offset: 4px;
    border-radius: 8px;
}
</style>
