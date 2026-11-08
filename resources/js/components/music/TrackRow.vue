<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Play } from 'lucide-vue-next';
import { computed } from 'vue';
import AlbumController from '@/actions/App/Http/Controllers/AlbumController';
import ArtistController from '@/actions/App/Http/Controllers/ArtistController';
import Artwork from '@/components/music/Artwork.vue';
import Equalizer from '@/components/music/Equalizer.vue';
import GenreTags from '@/components/music/GenreTags.vue';
import InLibraryMark from '@/components/music/InLibraryMark.vue';
import Waveform from '@/components/music/Waveform.vue';

/**
 * One row of a track table. Renders `display: contents` cells, so the parent
 * owns the grid and must declare a column for each cell shown: number, title,
 * album (showAlbum), genre (genreColumn), plays (showPlays), time.
 */
type Props = {
    track: App.Data.TrackData | App.Data.CatalogTrackData;
    position: number;
    current: boolean;
    playing: boolean;
    showAlbum?: boolean;
    showArtwork?: boolean;
    showArtist?: boolean;
    showPlays?: boolean;
    /** Genres in their own column (playlist table) instead of after the artist. */
    genreColumn?: boolean;
};

const props = withDefaults(defineProps<Props>(), {
    showAlbum: true,
    showArtwork: true,
    showArtist: true,
    showPlays: false,
    genreColumn: false,
});

const emit = defineEmits<{
    tap: [event: MouseEvent];
    play: [];
}>();

const catalog = computed(
    () => props.track as Partial<App.Data.CatalogTrackData>,
);
const inLibrary = computed(() => catalog.value.inLibrary === true);
const plays = computed(() =>
    catalog.value.plays == null
        ? ''
        : new Intl.NumberFormat(undefined, { notation: 'compact' }).format(
              catalog.value.plays,
          ),
);

function isFromLink(event: Event): boolean {
    return (event.target as HTMLElement).closest('a') !== null;
}

function onClick(event: MouseEvent): void {
    if (!isFromLink(event)) {
        emit('tap', event);
    }
}

function onKeydownEnter(event: KeyboardEvent): void {
    if (props.track.isAvailable && !isFromLink(event)) {
        emit('play');
    }
}
</script>

<template>
    <div
        class="track"
        :class="{
            'is-current': current,
            'is-unavailable': !track.isAvailable,
        }"
        role="row"
        :tabindex="track.isAvailable ? 0 : -1"
        @click="onClick"
        @keydown.enter="onKeydownEnter"
    >
        <span
            class="cell justify-end text-[12.5px] font-medium text-faint"
            role="cell"
        >
            <Equalizer v-if="current" :playing="playing" />
            <template v-else>
                <span class="number">{{ position }}</span>
                <button
                    v-if="track.isAvailable"
                    type="button"
                    class="hover-play"
                    tabindex="-1"
                    :aria-label="$t('Play :title', { title: track.title })"
                    @click.stop="$event.detail <= 1 && emit('play')"
                >
                    <Play class="size-3.5 fill-current text-amber" />
                </button>
            </template>
        </span>
        <span class="cell gap-2.5" role="cell">
            <Artwork
                v-if="showArtwork"
                :src="track.thumbnailUrl"
                class="size-8 shrink-0 rounded"
                data-track-artwork
            />
            <span class="min-w-0">
                <span class="title block truncate font-semibold">
                    {{ track.title }}
                    <span
                        v-if="track.isExplicit"
                        class="ml-1 rounded bg-raised px-1 text-[10px] font-semibold text-dim"
                        >E</span
                    >
                    <InLibraryMark
                        v-if="inLibrary"
                        class="ml-1.5 inline align-text-bottom"
                    />
                </span>
                <span
                    v-if="showArtist || (!genreColumn && track.genres.length)"
                    class="flex items-center gap-1.5 overflow-hidden text-[12.5px] text-dim"
                >
                    <template v-if="showArtist">
                        <Link
                            v-if="catalog.artistId"
                            :href="ArtistController.show(catalog.artistId)"
                            class="truncate hover:text-paper hover:underline"
                            >{{ track.artists }}</Link
                        >
                        <span v-else class="truncate">{{ track.artists }}</span>
                    </template>
                    <GenreTags
                        v-if="!genreColumn"
                        :genres="track.genres"
                        :limit="2"
                    />
                </span>
            </span>
        </span>
        <span
            v-if="showAlbum"
            class="cell from-md relative text-dim"
            role="cell"
        >
            <Waveform
                v-if="current"
                :seed="track.title"
                :playing="playing"
                class="absolute inset-x-0 inset-y-2"
            />
            <Link
                v-if="catalog.albumId && track.album"
                :href="AlbumController.show(catalog.albumId)"
                class="relative truncate hover:text-paper hover:underline"
                >{{ track.album }}</Link
            >
            <span v-else class="relative truncate">{{ track.album }}</span>
        </span>
        <span
            v-if="genreColumn"
            class="cell from-lg gap-1 overflow-hidden"
            role="cell"
        >
            <GenreTags :genres="track.genres" />
        </span>
        <span
            v-if="showPlays"
            class="cell from-md justify-end text-[12.5px] font-medium text-faint tabular-nums"
            role="cell"
            >{{ plays }}</span
        >
        <span
            class="cell justify-end text-[12.5px] font-medium text-faint"
            role="cell"
            >{{ track.duration }}</span
        >
    </div>
</template>

<style scoped>
.track {
    display: contents;
    cursor: pointer;
    user-select: none;
}

.cell {
    display: flex;
    align-items: center;
    min-width: 0;
    padding: 7px 10px;
    border-bottom: 1px solid oklch(0.285 0.012 60 / 0.55);
    transition: background 140ms;
}

/* Scoped display rules outrank Tailwind's `hidden`, so columns hide here. */
.cell.from-md,
.cell.from-lg {
    display: none;
}

@media (min-width: 768px) {
    .cell.from-md {
        display: flex;
    }
}

@media (min-width: 1024px) {
    .cell.from-lg {
        display: flex;
    }
}

.track:hover .cell,
.track:focus-visible .cell {
    background: var(--color-hover);
}

.track:focus-visible {
    outline: none;
}

.hover-play {
    display: none;
}

.track:not(.is-unavailable):hover .number {
    display: none;
}

.track:hover .hover-play {
    display: grid;
    place-items: center;
    cursor: pointer;
}

.track.is-current .cell {
    background: oklch(0.8 0.15 68 / 0.05);
}

.track.is-current .title {
    color: var(--color-amber);
}

.track.is-unavailable {
    cursor: default;
}

.track.is-unavailable .cell {
    opacity: 0.4;
}

.track.is-added .cell {
    animation: flash-amber 1.6s var(--ease-out-quint);
}
</style>
