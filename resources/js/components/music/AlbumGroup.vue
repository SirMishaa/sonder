<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AlbumController from '@/actions/App/Http/Controllers/AlbumController';
import Artwork from '@/components/music/Artwork.vue';
import TrackRow from '@/components/music/TrackRow.vue';
import type { AlbumGroupData } from '@/composables/useAlbumGrouping';

/**
 * Tracks sharing an album: cover, title, "type · year · track count" and the
 * result count, then the rows without artwork or album column.
 */
type Props = {
    group: AlbumGroupData;
    /** Album details looked up by `group.albumId`. */
    albums?: App.Data.CatalogAlbumData[];
    /** Full track count per album id, when known. */
    trackCounts?: Record<string, number>;
    currentVideoId?: string | null;
    playing?: boolean;
    /** Position of the group's first row. */
    startPosition?: number;
};

const props = withDefaults(defineProps<Props>(), {
    albums: () => [],
    trackCounts: () => ({}),
    currentVideoId: null,
    playing: false,
    startPosition: 1,
});

defineEmits<{
    tap: [track: App.Data.CatalogTrackData, event: MouseEvent];
    play: [track: App.Data.CatalogTrackData];
}>();

const album = computed(() =>
    props.group.albumId
        ? props.albums.find(({ id }) => id === props.group.albumId)
        : undefined,
);
const artwork = computed(
    () =>
        album.value?.thumbnailUrl ??
        props.group.tracks[0]?.thumbnailUrl ??
        null,
);
const trackCount = computed(() =>
    props.group.albumId
        ? (props.trackCounts[props.group.albumId] ?? album.value?.trackCount)
        : undefined,
);
const year = computed(
    () => album.value?.year ?? props.group.tracks[0]?.year ?? null,
);
</script>

<template>
    <section class="mb-3.5">
        <header
            class="grid grid-cols-[44px_minmax(0,1fr)_auto] items-center gap-3 px-2 pt-2 pb-1.5"
        >
            <Artwork :src="artwork" class="size-11 rounded-[5px]" />
            <span class="min-w-0">
                <Link
                    v-if="group.albumId"
                    :href="AlbumController.show(group.albumId)"
                    class="block truncate font-bold hover:underline"
                    >{{ group.title }}</Link
                >
                <span v-else class="block truncate font-bold">{{
                    group.title
                }}</span>
                <span class="block text-[12.5px] font-medium text-dim">
                    <template v-if="album">{{ album.type }}</template>
                    <template v-if="album && year"> · </template>
                    <template v-if="year">{{ year }}</template>
                    <template v-if="trackCount">
                        <template v-if="album || year"> · </template>
                        <span class="text-faint">{{
                            $tChoice(':count track|:count tracks', trackCount)
                        }}</span>
                    </template>
                </span>
            </span>
            <span class="text-[12.5px] font-medium text-faint">{{
                $tChoice(':count result|:count results', group.tracks.length)
            }}</span>
        </header>
        <div class="album-rows pl-2" role="table">
            <TrackRow
                v-for="(track, index) in group.tracks"
                :key="track.videoId ?? index"
                :track="track"
                :position="startPosition + index"
                :current="
                    currentVideoId !== null && track.videoId === currentVideoId
                "
                :playing="playing"
                :show-artwork="false"
                :show-album="false"
                @tap="$emit('tap', track, $event)"
                @play="$emit('play', track)"
            />
        </div>
    </section>
</template>

<style scoped>
.album-rows {
    display: grid;
    grid-template-columns: 36px minmax(0, 1fr) 64px;
}
</style>
