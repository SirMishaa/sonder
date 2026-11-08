<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import AlbumController from '@/actions/App/Http/Controllers/AlbumController';
import Artwork from '@/components/music/Artwork.vue';

/**
 * An album as a cover tile: artwork, title, then "type · year" with the
 * track count dimmed. `trackCount` undefined means still loading.
 */
type Props = {
    album: App.Data.CatalogAlbumData;
    trackCount?: number | null;
};

defineProps<Props>();
</script>

<template>
    <Link :href="AlbumController.show(album.id)" class="group tile min-w-0">
        <Artwork
            :src="album.thumbnailUrl"
            :alt="album.title"
            class="cover aspect-square w-full rounded-md"
        />
        <span class="mt-2.5 block truncate font-semibold">{{
            album.title
        }}</span>
        <span class="flex items-center gap-1 truncate text-[13px] text-dim">
            <span class="truncate">{{
                [album.type, album.year].filter(Boolean).join(' · ')
            }}</span>
            <span v-if="trackCount === undefined" class="skeleton h-2.5 w-12" />
            <span v-else-if="trackCount" class="shrink-0 text-faint"
                >·
                {{ $tChoice(':count track|:count tracks', trackCount) }}</span
            >
        </span>
    </Link>
</template>

<style scoped>
.cover {
    box-shadow:
        0 18px 30px -20px oklch(0 0 0 / 0.8),
        inset 0 0 0 1px oklch(1 0 0 / 0.06);
    transition: box-shadow 240ms var(--ease-out-quint);
}

.tile:hover .cover,
.tile:focus-visible .cover {
    box-shadow:
        0 0 0 1px oklch(0.8 0.15 68 / 0.35),
        0 20px 34px -20px oklch(0 0 0 / 0.9);
}

.tile:focus-visible {
    outline-offset: 4px;
    border-radius: 6px;
}
</style>
