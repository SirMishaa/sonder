<script setup lang="ts">
import { Deferred, Head, router } from '@inertiajs/vue3';
import { useTimeoutFn } from '@vueuse/core';
import { trans } from 'laravel-vue-i18n';
import { Search, SearchX } from 'lucide-vue-next';
import { computed, onMounted, useTemplateRef } from 'vue';
import SearchController from '@/actions/App/Http/Controllers/SearchController';
import AlbumCard from '@/components/catalog/AlbumCard.vue';
import ArtistCard from '@/components/catalog/ArtistCard.vue';
import CatalogError from '@/components/catalog/CatalogError.vue';
import CompactRow from '@/components/catalog/CompactRow.vue';
import PlaylistCard from '@/components/catalog/PlaylistCard.vue';
import SearchSpotlight from '@/components/catalog/SearchSpotlight.vue';
import AlbumGroup from '@/components/music/AlbumGroup.vue';
import Artwork from '@/components/music/Artwork.vue';
import GroupToggle from '@/components/music/GroupToggle.vue';
import SortControl from '@/components/music/SortControl.vue';
import TrackRow from '@/components/music/TrackRow.vue';
import { useAlbumGrouping } from '@/composables/useAlbumGrouping';
import { useCatalogSort } from '@/composables/useCatalogSort';
import { usePlayer } from '@/composables/usePlayer';
import { useSearchText } from '@/composables/useSearchText';
import { useTrackTap } from '@/composables/useTrackTap';
import AppLayout from '@/layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

type SearchType = 'tracks' | 'albums' | 'artists' | 'playlists';

type Props = {
    query: string;
    type: SearchType | null;
    results?: App.Data.SearchResultsData | null;
    catalogError?: string | null;
    albumTrackCounts?: Record<string, number>;
};

const props = defineProps<Props>();

const player = usePlayer();
const trackSort = useCatalogSort('search.tracks');
const albumSort = useCatalogSort('search.albums');
const { grouped, group } = useAlbumGrouping();

const source = computed(() => ({
    playlistId: null,
    title: trans('Search “:query”', { query: props.query }),
}));
const { tap, preventSelection } = useTrackTap(() => source.value);

const { text, current, requested } = useSearchText(() => props.query);
const input = useTemplateRef<HTMLInputElement>('input');
const debounce = useTimeoutFn(submit, 300, { immediate: false });

onMounted(() => {
    if (!props.query) {
        input.value?.focus();
    }
});

const chips = computed<{ value: SearchType | null; label: string }[]>(() => [
    { value: null, label: trans('All') },
    { value: 'tracks', label: trans('Tracks') },
    { value: 'albums', label: trans('Albums') },
    { value: 'artists', label: trans('Artists') },
    { value: 'playlists', label: trans('Playlists') },
]);

function visit(query: string, type: SearchType | null, replace: boolean): void {
    debounce.stop();
    requested(query);
    router.get(
        SearchController.index({
            query: { q: query || undefined, type: type ?? undefined },
        }),
        {},
        { preserveState: true, preserveScroll: !replace, replace },
    );
}

/** Searches the typed text, unless it is what the page already shows. */
function submit(): void {
    debounce.stop();

    if (current() !== props.query) {
        visit(current(), props.type, true);
    }
}

function choose(type: SearchType | null): void {
    debounce.stop();

    if (type !== props.type) {
        visit(current(), type, false);
    }
}

const tracks = computed(() =>
    trackSort.apply(
        props.results?.tracks ?? [],
        (track) => track.year,
        (track) => track.title,
    ),
);
const albums = computed(() =>
    albumSort.apply(
        props.results?.albums ?? [],
        (album) => album.year,
        (album) => album.title,
    ),
);
const groups = computed(() => group(tracks.value, props.results?.albums ?? []));
const groupStarts = computed(() => {
    let position = 1;

    return groups.value.map((entry) => {
        const start = position;
        position += entry.tracks.length;

        return start;
    });
});

/** Library tracks among the results, shown when there is no top artist. */
const libraryTracks = computed(() =>
    tracks.value.filter((track) => track.inLibrary).slice(0, 6),
);

/** What the spotlight's Play queues: the artist's own tracks, else all. */
const spotlightTracks = computed(() => {
    const artistId = props.results?.topArtist?.id;
    const own = tracks.value.filter((track) => track.artistId === artistId);

    return own.length > 0 ? own : tracks.value;
});

const isEmpty = computed(() => {
    const results = props.results;

    if (!results) {
        return false;
    }

    switch (props.type) {
        case 'tracks':
            return results.tracks.length === 0;
        case 'albums':
            return results.albums.length === 0;
        case 'artists':
            return results.artists.length === 0;
        case 'playlists':
            return results.playlists.length === 0;
        default:
            return (
                results.topArtist === null &&
                results.tracks.length === 0 &&
                results.albums.length === 0 &&
                results.artists.length === 0 &&
                results.playlists.length === 0
            );
    }
});

/** Track count of an album; undefined while the counts are loading. */
function albumCount(
    album: App.Data.CatalogAlbumData,
): number | null | undefined {
    if (props.albumTrackCounts === undefined) {
        return undefined;
    }

    return props.albumTrackCounts[album.id] ?? album.trackCount;
}

function isCurrent(index: number): boolean {
    const track = tracks.value[index];

    return (
        track !== undefined &&
        player.isCurrentTrack(source.value.playlistId, track, index)
    );
}

function play(index: number): void {
    player.playTracks(tracks.value, index, source.value, 'search');
}

function onTrackTap(event: MouseEvent, index: number): void {
    const track = tracks.value[index];

    if (track?.isAvailable) {
        tap(event, track, index, () => play(index));
    }
}

function indexOf(track: App.Data.CatalogTrackData): number {
    return tracks.value.indexOf(track);
}
</script>

<template>
    <Head :title="query ? $t('Search “:query”', { query }) : $t('Search')" />

    <div class="relative max-w-[1320px] px-4 pt-6 md:px-8 md:pt-8">
        <p class="text-[13px] font-bold text-amber">{{ $t('Search') }}</p>

        <form
            role="search"
            class="field mt-3.5 flex max-w-[640px] items-center gap-3 rounded-xl border border-line px-4 py-3"
            @submit.prevent="submit"
        >
            <Search class="size-5 shrink-0 text-faint" aria-hidden="true" />
            <input
                v-model="text"
                type="search"
                name="q"
                enterkeyhint="search"
                autocomplete="off"
                ref="input"
                :aria-label="$t('Search')"
                :placeholder="$t('Artist, album, track…')"
                class="min-w-0 flex-1 bg-transparent text-[17px] font-semibold outline-none placeholder:font-medium placeholder:text-faint"
                @input="debounce.start()"
            />
        </form>

        <div
            class="sticky top-0 z-10 -mx-4 bg-[linear-gradient(180deg,oklch(0.165_0.012_60/0.96)_70%,transparent)] px-4 pt-3.5 pb-3 md:-mx-8 md:px-8"
        >
            <div
                class="chips -m-1 flex gap-2 overflow-x-auto p-1"
                role="group"
                :aria-label="$t('Result type')"
            >
                <button
                    v-for="chip in chips"
                    :key="chip.value ?? 'all'"
                    type="button"
                    class="chip"
                    :aria-pressed="type === chip.value"
                    @click="choose(chip.value)"
                >
                    {{ chip.label }}
                </button>
            </div>
        </div>

        <div
            v-if="!query"
            class="mt-10 flex flex-col items-center gap-2 text-center text-dim"
        >
            <Search class="size-8 text-faint" aria-hidden="true" />
            <p class="font-semibold text-paper">
                {{ $t('Search YouTube Music') }}
            </p>
            <p class="text-[13px]">
                {{ $t('Find an artist, an album, a track or a playlist.') }}
            </p>
        </div>

        <Deferred v-else data="results">
            <template #fallback>
                <div aria-busy="true" :aria-label="$t('Loading')">
                    <div
                        v-if="!type"
                        class="skeleton mt-1.5 h-[136px] rounded-2xl lg:h-[200px]"
                    />
                    <div class="dense mt-7">
                        <div>
                            <div class="skeleton mb-3 h-3.5 w-24" />
                            <div
                                v-for="row in 6"
                                :key="row"
                                class="flex items-center gap-2.5 px-2.5 py-[7px]"
                            >
                                <div class="skeleton size-9 shrink-0" />
                                <div class="flex-1">
                                    <div
                                        class="skeleton mb-[7px] h-[11px] w-[42%]"
                                    />
                                    <div class="skeleton h-2.5 w-[26%]" />
                                </div>
                            </div>
                        </div>
                        <div class="max-lg:hidden">
                            <div class="skeleton mb-3 h-3.5 w-20" />
                            <div
                                v-for="row in 4"
                                :key="row"
                                class="flex items-center gap-3 px-2 py-1.5"
                            >
                                <div class="skeleton size-11 shrink-0" />
                                <div class="flex-1">
                                    <div
                                        class="skeleton mb-[7px] h-[11px] w-1/2"
                                    />
                                    <div class="skeleton h-2.5 w-1/3" />
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <CatalogError
                v-if="catalogError"
                class="mt-4"
                :message="catalogError"
            />

            <div
                v-else-if="!results || isEmpty"
                class="mt-4 flex flex-col items-center gap-2 rounded-xl border border-dashed border-line px-6 py-12 text-center"
            >
                <SearchX class="size-8 text-faint" aria-hidden="true" />
                <p class="font-semibold text-balance">
                    {{ $t('Nothing found for “:query”', { query }) }}
                </p>
                <p class="text-[13px] text-dim">
                    {{ $t('Check the spelling or try fewer words.') }}
                </p>
            </div>

            <!-- One type: the whole list for that chip. -->
            <section v-else-if="type === 'tracks'" class="mt-3">
                <h2 class="section-title">
                    {{ $t('Tracks') }}
                    <GroupToggle v-model="grouped" class="ml-1.5" />
                    <SortControl v-model="trackSort.sort.value" />
                </h2>
                <template v-if="grouped">
                    <AlbumGroup
                        v-for="(entry, groupIndex) in groups"
                        :key="entry.albumId ?? 'singles'"
                        class="reveal"
                        :style="{ '--i': groupIndex }"
                        :group="entry"
                        :albums="results.albums"
                        :track-counts="albumTrackCounts"
                        :current-video-id="
                            player.current.value?.videoId ?? null
                        "
                        :playing="player.state.playing"
                        :start-position="groupStarts[groupIndex]"
                        @tap="
                            (track, event) => onTrackTap(event, indexOf(track))
                        "
                        @play="(track) => play(indexOf(track))"
                    />
                </template>
                <div
                    v-else
                    class="track-table full reveal"
                    role="table"
                    :aria-label="$t('Tracks')"
                >
                    <TrackRow
                        v-for="(track, index) in tracks"
                        :key="`${track.videoId ?? track.title}-${index}`"
                        :track="track"
                        :position="index + 1"
                        :current="isCurrent(index)"
                        :playing="player.state.playing"
                        show-plays
                        @mousedown="preventSelection"
                        @tap="onTrackTap($event, index)"
                        @play="play(index)"
                    />
                </div>
            </section>

            <section v-else-if="type === 'albums'" class="mt-3">
                <h2 class="section-title">
                    {{ $t('Albums') }}
                    <SortControl
                        v-model="albumSort.sort.value"
                        class="ml-1.5"
                    />
                </h2>
                <div class="shelf">
                    <AlbumCard
                        v-for="(album, index) in albums"
                        :key="album.id"
                        class="reveal"
                        :style="{ '--i': index }"
                        :album="album"
                        :track-count="albumCount(album)"
                    />
                </div>
            </section>

            <section v-else-if="type === 'artists'" class="mt-3">
                <h2 class="section-title">{{ $t('Artists') }}</h2>
                <div class="portraits">
                    <ArtistCard
                        v-for="(artist, index) in results.artists"
                        :key="artist.id"
                        class="reveal"
                        :style="{ '--i': index }"
                        :artist="artist"
                    />
                </div>
            </section>

            <section v-else-if="type === 'playlists'" class="mt-3">
                <h2 class="section-title">{{ $t('Playlists') }}</h2>
                <div class="shelf">
                    <PlaylistCard
                        v-for="(playlist, index) in results.playlists"
                        :key="playlist.id"
                        class="reveal"
                        :style="{ '--i': index }"
                        :playlist="playlist"
                    />
                </div>
            </section>

            <!-- All: spotlight, then tracks beside albums, artists and playlists. -->
            <template v-else>
                <SearchSpotlight
                    v-if="results.topArtist"
                    class="mt-1.5"
                    :artist="results.topArtist"
                    :in-library="results.topArtistInLibrary"
                    :library-tracks="results.topArtistLibraryTracks"
                    :tracks="spotlightTracks"
                    :source="source"
                />
                <section
                    v-else-if="libraryTracks.length"
                    class="reveal mt-1.5 rounded-xl border border-line bg-panel/50 p-1"
                >
                    <h2 class="section-title mx-2.5 mt-2 mb-1">
                        {{ $t('In your library') }}
                        <span class="count">{{ libraryTracks.length }}</span>
                    </h2>
                    <div
                        class="grid grid-cols-[repeat(auto-fill,minmax(240px,1fr))] gap-0.5"
                    >
                        <button
                            v-for="track in libraryTracks"
                            :key="track.videoId ?? track.title"
                            type="button"
                            class="grid grid-cols-[36px_minmax(0,1fr)] items-center gap-2.5 rounded-lg px-2 py-1.5 text-left transition-colors duration-150 hover:bg-hover"
                            @mousedown="preventSelection"
                            @click="onTrackTap($event, indexOf(track))"
                        >
                            <Artwork
                                :src="track.thumbnailUrl"
                                class="size-9 rounded"
                                data-track-artwork
                            />
                            <span class="min-w-0">
                                <span class="block truncate font-semibold">{{
                                    track.title
                                }}</span>
                                <span
                                    class="block truncate text-[12.5px] font-medium text-faint"
                                    >{{ track.artists }}</span
                                >
                            </span>
                        </button>
                    </div>
                </section>

                <div class="dense mt-2">
                    <section v-if="tracks.length">
                        <h2 class="section-title">
                            {{ $t('Tracks') }}
                            <GroupToggle v-model="grouped" class="ml-1.5" />
                            <SortControl v-model="trackSort.sort.value" />
                            <button
                                type="button"
                                class="more"
                                @click="choose('tracks')"
                            >
                                {{ $t('See all') }}
                            </button>
                        </h2>
                        <template v-if="grouped">
                            <AlbumGroup
                                v-for="(entry, groupIndex) in groups"
                                :key="entry.albumId ?? 'singles'"
                                class="reveal"
                                :style="{ '--i': groupIndex }"
                                :group="entry"
                                :albums="results.albums"
                                :track-counts="albumTrackCounts"
                                :current-video-id="
                                    player.current.value?.videoId ?? null
                                "
                                :playing="player.state.playing"
                                :start-position="groupStarts[groupIndex]"
                                @tap="
                                    (track, event) =>
                                        onTrackTap(event, indexOf(track))
                                "
                                @play="(track) => play(indexOf(track))"
                            />
                        </template>
                        <div
                            v-else
                            class="track-table reveal"
                            role="table"
                            :aria-label="$t('Tracks')"
                        >
                            <TrackRow
                                v-for="(track, index) in tracks"
                                :key="`${track.videoId ?? track.title}-${index}`"
                                :track="track"
                                :position="index + 1"
                                :current="isCurrent(index)"
                                :playing="player.state.playing"
                                :show-album="false"
                                @mousedown="preventSelection"
                                @tap="onTrackTap($event, index)"
                                @play="play(index)"
                            />
                        </div>
                    </section>

                    <div>
                        <section v-if="albums.length">
                            <h2 class="section-title">
                                {{ $t('Albums') }}
                                <SortControl
                                    v-model="albumSort.sort.value"
                                    class="ml-1.5"
                                />
                                <button
                                    type="button"
                                    class="more"
                                    @click="choose('albums')"
                                >
                                    {{ $t('See all') }}
                                </button>
                            </h2>
                            <CompactRow
                                v-for="(album, index) in albums.slice(0, 4)"
                                :key="album.id"
                                class="reveal"
                                :style="{ '--i': index }"
                                kind="album"
                                :item="album"
                                :track-count="albumCount(album)"
                            />
                        </section>
                        <section v-if="results.artists.length">
                            <h2 class="section-title">
                                {{ $t('Artists') }}
                                <button
                                    type="button"
                                    class="more"
                                    @click="choose('artists')"
                                >
                                    {{ $t('See all') }}
                                </button>
                            </h2>
                            <CompactRow
                                v-for="(artist, index) in results.artists.slice(
                                    0,
                                    3,
                                )"
                                :key="artist.id"
                                class="reveal"
                                :style="{ '--i': index }"
                                kind="artist"
                                :item="artist"
                            />
                        </section>
                        <section v-if="results.playlists.length">
                            <h2 class="section-title">
                                {{ $t('Playlists') }}
                                <button
                                    type="button"
                                    class="more"
                                    @click="choose('playlists')"
                                >
                                    {{ $t('See all') }}
                                </button>
                            </h2>
                            <CompactRow
                                v-for="(
                                    playlist, index
                                ) in results.playlists.slice(0, 3)"
                                :key="playlist.id"
                                class="reveal"
                                :style="{ '--i': index }"
                                kind="playlist"
                                :item="playlist"
                            />
                        </section>
                    </div>
                </div>
            </template>
        </Deferred>
    </div>
</template>

<style scoped>
.field {
    background: oklch(0.14 0.01 60 / 0.6);
    transition:
        border-color 140ms,
        box-shadow 140ms;
}

.field:focus-within {
    border-color: oklch(0.8 0.15 68 / 0.5);
    box-shadow: 0 0 0 3px oklch(0.8 0.15 68 / 0.12);
}

.field input::-webkit-search-cancel-button {
    filter: grayscale(1);
    opacity: 0.6;
}

.chips {
    scrollbar-width: none;
}

.chip {
    padding: 5px 13px;
    border: 1px solid var(--color-line);
    border-radius: 999px;
    color: var(--color-dim);
    font-size: 13.5px;
    font-weight: 600;
    white-space: nowrap;
    transition:
        background 140ms,
        color 140ms,
        border-color 140ms;
}

.chip:hover {
    border-color: oklch(0.38 0.014 60);
    color: var(--color-paper);
}

.chip[aria-pressed='true'] {
    border-color: var(--color-paper);
    background: var(--color-paper);
    color: var(--color-ink);
}

.section-title {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 8px;
    margin: 24px 0 10px;
    font-size: 14px;
    font-weight: 700;
}

.section-title .count {
    color: var(--color-faint);
    font-size: 12.5px;
    font-weight: 500;
}

.more {
    margin-left: auto;
    color: var(--color-dim);
    font-size: 12.5px;
    font-weight: 600;
    transition: color 140ms;
}

.more:hover {
    color: var(--color-paper);
}

.dense {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    column-gap: 32px;
}

@media (min-width: 1024px) {
    .dense {
        grid-template-columns: minmax(0, 1.25fr) minmax(0, 1fr);
    }
}

.track-table {
    display: grid;
    grid-template-columns: 36px minmax(0, 1fr) 64px;
}

@media (min-width: 768px) {
    .track-table.full {
        grid-template-columns: 36px minmax(0, 1.4fr) minmax(0, 1fr) 72px 64px;
    }
}

.shelf {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 22px 16px;
}

.portraits {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(124px, 1fr));
    gap: 20px 16px;
}

@media (max-width: 480px) {
    .shelf,
    .portraits {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
</style>
