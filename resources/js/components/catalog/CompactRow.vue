<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import AlbumController from '@/actions/App/Http/Controllers/AlbumController';
import ArtistController from '@/actions/App/Http/Controllers/ArtistController';
import BrowsePlaylistController from '@/actions/App/Http/Controllers/BrowsePlaylistController';
import Artwork from '@/components/music/Artwork.vue';

/**
 * One album, artist or playlist as a dense row linking to its page:
 * 44px artwork, title and a muted subtitle. For an album, `trackCount`
 * undefined means the count is still loading.
 */
type Props = {
    kind: 'album' | 'artist' | 'playlist';
    item:
        | App.Data.CatalogAlbumData
        | App.Data.CatalogArtistData
        | App.Data.CatalogPlaylistData;
    trackCount?: number | null;
};

const props = defineProps<Props>();

const album = computed(() =>
    props.kind === 'album' ? (props.item as App.Data.CatalogAlbumData) : null,
);
const artist = computed(() =>
    props.kind === 'artist' ? (props.item as App.Data.CatalogArtistData) : null,
);
const playlist = computed(() =>
    props.kind === 'playlist'
        ? (props.item as App.Data.CatalogPlaylistData)
        : null,
);

const href = computed(() => {
    if (artist.value) {
        return ArtistController.show(artist.value.id);
    }

    if (playlist.value) {
        return BrowsePlaylistController.show(playlist.value.id);
    }

    return AlbumController.show(props.item.id);
});

const title = computed(
    () =>
        artist.value?.name ?? playlist.value?.title ?? album.value?.title ?? '',
);
const count = computed(() =>
    album.value ? props.trackCount : (playlist.value?.trackCount ?? null),
);
</script>

<template>
    <Link
        :href="href"
        class="grid grid-cols-[44px_minmax(0,1fr)] items-center gap-3 rounded-lg px-2 py-1.5 transition-colors duration-150 hover:bg-hover focus-visible:bg-hover"
    >
        <Artwork
            :src="item.thumbnailUrl"
            :alt="title"
            class="size-11"
            :class="artist ? 'rounded-full' : 'rounded-[5px]'"
        />
        <span class="min-w-0">
            <span class="block truncate font-semibold">{{ title }}</span>
            <span
                class="flex items-center gap-1 overflow-hidden text-[13px] text-dim"
            >
                <span v-if="album" class="truncate">{{
                    [album.type, album.year].filter(Boolean).join(' · ')
                }}</span>
                <span v-else-if="artist" class="truncate">{{
                    artist.subscribers
                        ? $t(':count subscribers', {
                              count: artist.subscribers,
                          })
                        : $t('Artist')
                }}</span>
                <span v-else-if="playlist?.author" class="truncate">{{
                    playlist.author
                }}</span>
                <template v-if="!artist">
                    <span
                        v-if="count === undefined"
                        class="skeleton h-2.5 w-12 shrink-0"
                    />
                    <span v-else-if="count" class="shrink-0 text-faint"
                        ><template v-if="album || playlist?.author">· </template
                        >{{
                            $tChoice(':count track|:count tracks', count)
                        }}</span
                    >
                </template>
            </span>
        </span>
    </Link>
</template>
