<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import BrowsePlaylistController from '@/actions/App/Http/Controllers/BrowsePlaylistController';
import Artwork from '@/components/music/Artwork.vue';

/** A public playlist as a cover tile: artwork, title, author and size. */
defineProps<{
    playlist: App.Data.CatalogPlaylistData;
}>();
</script>

<template>
    <Link
        :href="BrowsePlaylistController.show(playlist.id)"
        class="tile min-w-0"
    >
        <Artwork
            :src="playlist.thumbnailUrl"
            :alt="playlist.title"
            class="cover aspect-square w-full rounded-md"
        />
        <span class="mt-2.5 block truncate font-semibold">{{
            playlist.title
        }}</span>
        <span class="block truncate text-[13px] text-dim">
            {{ playlist.author }}
            <span v-if="playlist.trackCount" class="text-faint"
                ><template v-if="playlist.author">· </template
                >{{
                    $tChoice(':count track|:count tracks', playlist.trackCount)
                }}</span
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
