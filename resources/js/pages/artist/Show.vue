<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { Play, Shuffle } from 'lucide-vue-next';
import { computed } from 'vue';
import AlbumCard from '@/components/catalog/AlbumCard.vue';
import ArtistAside from '@/components/catalog/ArtistAside.vue';
import ArtistCard from '@/components/catalog/ArtistCard.vue';
import CatalogError from '@/components/catalog/CatalogError.vue';
import CatalogMenu from '@/components/catalog/CatalogMenu.vue';
import VideoCard from '@/components/catalog/VideoCard.vue';
import AlbumGroup from '@/components/music/AlbumGroup.vue';
import Artwork from '@/components/music/Artwork.vue';
import GenreTags from '@/components/music/GenreTags.vue';
import GroupToggle from '@/components/music/GroupToggle.vue';
import SortControl from '@/components/music/SortControl.vue';
import TrackRow from '@/components/music/TrackRow.vue';
import { useAlbumGrouping } from '@/composables/useAlbumGrouping';
import { useCatalogSort } from '@/composables/useCatalogSort';
import { useDiscographyTab } from '@/composables/useDiscographyTab';
import type { DiscographyTab } from '@/composables/useDiscographyTab';
import { useTrackListPlayback } from '@/composables/useTrackListPlayback';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatCompactNumber, parseCompactCount } from '@/lib/i18n';

defineOptions({ layout: AppLayout });

type Props = {
    page: App.Data.ArtistPageData | null;
    catalogError: string | null;
    albumTrackCounts?: Record<string, number>;
};

const props = defineProps<Props>();

const trackSort = useCatalogSort('artist.tracks');
const albumSort = useCatalogSort('artist.albums');
const { grouped, group } = useAlbumGrouping();

const source = computed(() => ({
    playlistId: null,
    title: props.page?.artist.name ?? '',
}));

/** A count YouTube Music abbreviates ("43.3M"), shortened in the active language. */
function compact(value: string | null): string | null {
    if (!value) {
        return null;
    }

    const count = parseCompactCount(value);

    return count === null ? value : formatCompactNumber(count, 3);
}

const stats = computed(() => {
    const page = props.page;

    if (!page) {
        return [];
    }

    const listeners = compact(page.monthlyListeners);
    const subscribers = compact(page.artist.subscribers);

    return [
        listeners && trans(':count monthly listeners', { count: listeners }),
        subscribers && trans(':count subscribers', { count: subscribers }),
        page.views !== null &&
            trans(':count views', { count: formatCompactNumber(page.views) }),
    ].filter((stat): stat is string => typeof stat === 'string');
});

const tracks = computed(() =>
    trackSort.apply(
        props.page?.tracks ?? [],
        (track) => track.year,
        (track) => track.title,
    ),
);
const allAlbums = computed(() => [
    ...(props.page?.albums ?? []),
    ...(props.page?.singles ?? []),
]);
const groups = computed(() => group(tracks.value, allAlbums.value));
const groupStarts = computed(() => {
    let position = 1;

    return groups.value.map((entry) => {
        const start = position;
        position += entry.tracks.length;

        return start;
    });
});
const {
    player,
    playable,
    isCurrent,
    play,
    shuffle,
    onTrackTap,
    preventSelection,
} = useTrackListPlayback(
    () => tracks.value,
    () => source.value,
    'search',
);

const tabs = computed<{ value: DiscographyTab; label: string }[]>(() =>
    props.page?.albums.length && props.page.singles.length
        ? [
              { value: 'all', label: trans('All') },
              { value: 'albums', label: trans('Albums') },
              { value: 'singles', label: trans('Singles & EP') },
          ]
        : [],
);
const tab = useDiscographyTab(
    () => tabs.value.map(({ value }) => value),
    () => props.page?.artist.id ?? null,
);
const discography = computed(() => {
    const list =
        tab.value === 'albums'
            ? (props.page?.albums ?? [])
            : tab.value === 'singles'
              ? (props.page?.singles ?? [])
              : allAlbums.value;

    return albumSort.apply(
        list,
        (album) => album.year,
        (album) => album.title,
    );
});

/** Clips as queue entries, so the player can load them like tracks. */
const videoTracks = computed<App.Data.TrackData[]>(() =>
    (props.page?.videos ?? []).map((video) => ({
        videoId: video.videoId,
        title: video.title,
        artists: props.page?.artist.name ?? '',
        album: null,
        duration: null,
        durationSeconds: null,
        thumbnailUrl: video.thumbnailUrl,
        isExplicit: false,
        isAvailable: video.videoId !== null,
        genres: [],
    })),
);

/** Track count of an album; undefined while the counts are loading. */
function albumCount(
    album: App.Data.CatalogAlbumData,
): number | null | undefined {
    if (props.albumTrackCounts === undefined) {
        return undefined;
    }

    return props.albumTrackCounts[album.id] ?? album.trackCount;
}

function indexOf(track: App.Data.CatalogTrackData): number {
    return tracks.value.indexOf(track);
}

function isCurrentVideo(video: App.Data.CatalogVideoData): boolean {
    return (
        video.videoId !== null &&
        player.current.value?.videoId === video.videoId
    );
}

/** Plays the clip, then the clips after it; the one playing toggles. */
function playVideo(index: number): void {
    const video = props.page?.videos[index];

    if (video && isCurrentVideo(video)) {
        player.toggle();

        return;
    }

    player.playTracks(videoTracks.value, index, source.value, 'search');
}

const youTubeMusicUrl = computed(() =>
    props.page
        ? `https://music.youtube.com/channel/${encodeURIComponent(props.page.artist.id)}`
        : '',
);
</script>

<template>
    <Head :title="page?.artist.name ?? $t('Artist')" />

    <div v-if="!page" class="max-w-[1320px] px-4 pt-6 md:px-8 md:pt-8">
        <CatalogError
            :message="catalogError ?? $t('YouTube Music could not be reached.')"
        />
    </div>

    <div v-else class="relative">
        <div class="ambient" aria-hidden="true">
            <div
                :style="{
                    backgroundImage: page.artist.thumbnailUrl
                        ? `url('${page.artist.thumbnailUrl}')`
                        : undefined,
                }"
            />
        </div>

        <div class="page relative max-w-[1320px] px-4 pt-6 md:px-8 md:pt-8">
            <header class="hero">
                <Artwork
                    :src="page.artist.thumbnailUrl"
                    :alt="page.artist.name"
                    class="portrait rounded-full"
                    eager
                />
                <div class="min-w-0">
                    <p class="reveal text-[13px] font-bold text-amber">
                        {{ $t('Artist') }}
                    </p>
                    <h1
                        class="reveal mt-1.5 mb-3 text-[34px] leading-none font-extrabold tracking-[-0.04em] text-balance md:text-[44px]"
                        style="--i: 1"
                    >
                        {{ page.artist.name }}
                    </h1>
                    <p
                        v-if="stats.length"
                        class="reveal flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px] font-medium text-dim"
                        style="--i: 2"
                    >
                        <template v-for="(stat, index) in stats" :key="stat">
                            <i v-if="index > 0" class="dot" />
                            <span>{{ stat }}</span>
                        </template>
                    </p>
                    <div
                        v-if="page.genres.length"
                        class="reveal mt-3 flex flex-wrap gap-1.5"
                        style="--i: 2"
                    >
                        <GenreTags :genres="page.genres" />
                    </div>
                    <div
                        class="reveal mt-[18px] flex items-center gap-2.5"
                        style="--i: 3"
                    >
                        <button
                            type="button"
                            class="round-play"
                            :disabled="!playable"
                            :aria-label="$t('Play')"
                            :title="$t('Play')"
                            @click="play(0)"
                        >
                            <Play class="size-5 fill-current" />
                        </button>
                        <button
                            type="button"
                            class="action-button"
                            :disabled="!playable"
                            @click="shuffle"
                        >
                            <Shuffle class="size-4" aria-hidden="true" />{{
                                $t('Shuffle')
                            }}
                        </button>
                        <CatalogMenu :you-tube-music-url="youTubeMusicUrl" />
                    </div>
                </div>
            </header>

            <div class="layout">
                <div class="min-w-0">
                    <section v-if="tracks.length">
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
                                :albums="allAlbums"
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
                                show-plays
                                @mousedown="preventSelection"
                                @tap="onTrackTap($event, index)"
                                @play="play(index)"
                            />
                        </div>
                    </section>

                    <section v-if="allAlbums.length">
                        <h2 class="section-title">
                            {{ $t('Discography') }}
                            <span
                                v-if="tabs.length"
                                class="ml-1.5 flex gap-1.5"
                                role="group"
                                :aria-label="$t('Release type')"
                            >
                                <button
                                    v-for="option in tabs"
                                    :key="option.value"
                                    type="button"
                                    class="chip"
                                    :aria-pressed="tab === option.value"
                                    @click="tab = option.value"
                                >
                                    {{ option.label }}
                                </button>
                            </span>
                            <SortControl
                                v-model="albumSort.sort.value"
                                :class="{ 'ml-1.5': !tabs.length }"
                            />
                        </h2>
                        <div class="shelf">
                            <AlbumCard
                                v-for="(album, index) in discography"
                                :key="album.id"
                                class="reveal"
                                :style="{ '--i': index }"
                                :album="album"
                                :track-count="albumCount(album)"
                            />
                        </div>
                    </section>

                    <section v-if="page.videos.length">
                        <h2 class="section-title">
                            {{ $t('Clips & lives') }}
                        </h2>
                        <div class="videos">
                            <VideoCard
                                v-for="(video, index) in page.videos"
                                :key="video.videoId ?? index"
                                class="reveal"
                                :style="{ '--i': index }"
                                :video="video"
                                :current="isCurrentVideo(video)"
                                :playing="player.state.playing"
                                @play="playVideo(index)"
                            />
                        </div>
                    </section>

                    <section v-if="page.related.length">
                        <h2 class="section-title">
                            {{ $t('Similar artists') }}
                        </h2>
                        <div class="portraits">
                            <ArtistCard
                                v-for="(artist, index) in page.related"
                                :key="artist.id"
                                class="reveal"
                                :style="{ '--i': index }"
                                :artist="artist"
                            />
                        </div>
                    </section>
                </div>

                <ArtistAside
                    class="side"
                    :artist-id="page.artist.id"
                    :artist-name="page.artist.name"
                    :in-library="page.inLibrary"
                    :description="page.description"
                />
            </div>
        </div>
    </div>
</template>

<style scoped>
.ambient {
    position: absolute;
    inset: 0 0 auto 0;
    height: 520px;
    overflow: hidden;
    pointer-events: none;
    mask-image: linear-gradient(180deg, #000 45%, transparent 100%);
}

.ambient div {
    position: absolute;
    inset: -60px;
    background-size: cover;
    background-position: center;
    filter: blur(70px) saturate(1.35);
    opacity: 0.42;
    animation: fade-in 900ms var(--ease-out-quint) both;
}

.ambient::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(
        180deg,
        oklch(0.165 0.012 60 / 0.15),
        oklch(0.165 0.012 60 / 0.7) 55%,
        transparent 100%
    );
}

@keyframes fade-in {
    from {
        opacity: 0;
    }
}

.page {
    container-type: inline-size;
}

.hero {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    justify-items: start;
    gap: 20px;
    margin-bottom: 28px;
}

.portrait {
    width: 132px;
    height: 132px;
    box-shadow:
        0 34px 60px -28px oklch(0 0 0 / 0.95),
        0 0 0 1px oklch(1 0 0 / 0.08);
}

@media (min-width: 768px) {
    .hero {
        grid-template-columns: 188px minmax(0, 1fr);
        justify-items: stretch;
        align-items: end;
        gap: 28px;
    }

    .portrait {
        width: 188px;
        height: 188px;
    }
}

.dot {
    width: 3px;
    height: 3px;
    border-radius: 999px;
    background: var(--color-faint);
}

/* The aside sits beside the content once the page itself is wide enough. */
.layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 40px;
    align-items: start;
}

@container (min-width: 860px) {
    .layout {
        grid-template-columns: minmax(0, 1fr) 320px;
    }

    .side {
        position: sticky;
        top: 24px;
        max-height: calc(100dvh - 148px);
        overflow-y: auto;
        scrollbar-width: thin;
    }
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

section:first-child > .section-title {
    margin-top: 0;
}

.chip {
    padding: 3px 11px;
    border: 1px solid var(--color-line);
    border-radius: 999px;
    color: var(--color-dim);
    font-size: 12.5px;
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

.track-table {
    display: grid;
    grid-template-columns: 36px minmax(0, 1fr) 64px;
}

@media (min-width: 768px) {
    .track-table {
        grid-template-columns: 36px minmax(0, 1.4fr) minmax(0, 1fr) 72px 64px;
    }
}

.shelf {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 22px 16px;
}

.videos {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 18px 16px;
}

.portraits {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(124px, 1fr));
    gap: 20px 16px;
}

@media (max-width: 480px) {
    .shelf,
    .videos,
    .portraits {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
</style>
