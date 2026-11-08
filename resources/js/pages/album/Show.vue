<script setup lang="ts">
import { Deferred, Head, Link } from '@inertiajs/vue3';
import { Clock3, ListPlus, Music2, Play, Shuffle } from 'lucide-vue-next';
import { computed } from 'vue';
import ArtistController from '@/actions/App/Http/Controllers/ArtistController';
import AlbumCard from '@/components/catalog/AlbumCard.vue';
import AlbumFacts from '@/components/catalog/AlbumFacts.vue';
import CatalogError from '@/components/catalog/CatalogError.vue';
import CatalogMenu from '@/components/catalog/CatalogMenu.vue';
import Artwork from '@/components/music/Artwork.vue';
import TrackRow from '@/components/music/TrackRow.vue';
import { useTrackListPlayback } from '@/composables/useTrackListPlayback';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatListeningTime } from '@/lib/i18n';

defineOptions({ layout: AppLayout });

type Props = {
    page: App.Data.AlbumPageData | null;
    catalogError: string | null;
    moreByArtist?: App.Data.CatalogAlbumData[];
};

const props = defineProps<Props>();

/** "More by" is a shelf, not the whole discography. */
const MORE_BY_LIMIT = 10;

const tracks = computed(() => props.page?.tracks ?? []);
const source = computed(() => ({
    playlistId: null,
    title: props.page?.album.title ?? '',
}));
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

const duration = computed(() =>
    props.page?.durationSeconds
        ? formatListeningTime(props.page.durationSeconds)
        : null,
);
const moreBy = computed(() =>
    (props.moreByArtist ?? []).slice(0, MORE_BY_LIMIT),
);

/** Features only: the album's own artist is already on the page. */
function showsArtist(track: App.Data.CatalogTrackData): boolean {
    return track.artists !== props.page?.album.artists;
}

const youTubeMusicUrl = computed(() =>
    props.page
        ? `https://music.youtube.com/browse/${encodeURIComponent(props.page.album.id)}`
        : '',
);
</script>

<template>
    <Head :title="page?.album.title ?? $t('Album')" />

    <div v-if="!page" class="max-w-[1320px] px-4 pt-6 md:px-8 md:pt-8">
        <CatalogError
            :message="catalogError ?? $t('YouTube Music could not be reached.')"
        />
    </div>

    <div v-else class="relative">
        <div class="ambient" aria-hidden="true">
            <div
                :style="{
                    backgroundImage: page.album.thumbnailUrl
                        ? `url('${page.album.thumbnailUrl}')`
                        : undefined,
                }"
            />
        </div>

        <div class="page relative max-w-[1320px] px-4 pt-6 md:px-8 md:pt-8">
            <div class="layout">
                <header class="side">
                    <Artwork
                        :src="page.album.thumbnailUrl"
                        :alt="page.album.title"
                        class="cover rounded-[10px]"
                        eager
                    />
                    <p class="reveal mt-5 text-[13px] font-bold text-amber">
                        {{ page.album.type || $t('Album') }}
                    </p>
                    <h1
                        class="reveal mt-1.5 mb-2 text-[30px] leading-[1.1] font-extrabold tracking-[-0.03em] text-balance"
                        style="--i: 1"
                    >
                        {{ page.album.title }}
                    </h1>
                    <p
                        class="reveal flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px] font-medium text-dim"
                        style="--i: 2"
                    >
                        <Link
                            v-if="page.album.artistId"
                            :href="ArtistController.show(page.album.artistId)"
                            class="font-bold text-paper hover:underline"
                            >{{ page.album.artists }}</Link
                        >
                        <span v-else class="font-bold text-paper">{{
                            page.album.artists
                        }}</span>
                        <template v-if="page.album.year">
                            <i class="dot" /><span>{{ page.album.year }}</span>
                        </template>
                        <i class="dot" />
                        <span>{{
                            $tChoice(
                                ':count track|:count tracks',
                                page.tracks.length,
                            )
                        }}</span>
                        <template v-if="duration">
                            <i class="dot" /><span>{{ duration }}</span>
                        </template>
                    </p>

                    <div class="actions reveal" style="--i: 3">
                        <button
                            type="button"
                            class="round-play"
                            :disabled="!playable"
                            :aria-label="$t('Play')"
                            :title="$t('Play')"
                            @click="play(0)"
                        >
                            <Play class="size-[18px] fill-current" />
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
                        <span class="wide" :title="$t('Coming soon')">
                            <button
                                type="button"
                                class="action-button w-full"
                                disabled
                            >
                                <ListPlus class="size-4" aria-hidden="true" />{{
                                    $t('Save as playlist')
                                }}
                            </button>
                        </span>
                    </div>

                    <AlbumFacts class="reveal" style="--i: 4" :page="page" />
                </header>

                <div class="min-w-0">
                    <div
                        v-if="tracks.length"
                        class="track-table reveal"
                        style="--i: 2"
                        role="table"
                        :aria-label="$t('Tracks')"
                    >
                        <div class="contents" role="row">
                            <span class="head justify-end" role="columnheader"
                                >#</span
                            >
                            <span class="head" role="columnheader">{{
                                $t('Title')
                            }}</span>
                            <span
                                class="head from-md justify-end"
                                role="columnheader"
                                >{{ $t('Plays') }}</span
                            >
                            <span
                                class="head justify-end"
                                role="columnheader"
                                :aria-label="$t('Time')"
                                :title="$t('Time')"
                            >
                                <Clock3 class="size-3.5" aria-hidden="true" />
                            </span>
                        </div>
                        <TrackRow
                            v-for="(track, index) in tracks"
                            :key="`${track.videoId ?? track.title}-${index}`"
                            :track="track"
                            :position="index + 1"
                            :current="isCurrent(index)"
                            :playing="player.state.playing"
                            :show-artwork="false"
                            :show-album="false"
                            :show-artist="showsArtist(track)"
                            show-plays
                            @mousedown="preventSelection"
                            @tap="onTrackTap($event, index)"
                            @play="play(index)"
                        />
                    </div>

                    <div
                        v-else
                        class="flex flex-col items-center gap-2 rounded-xl border border-dashed border-line p-12 text-center"
                    >
                        <Music2 class="size-8 text-faint" />
                        <p class="font-semibold">
                            {{ $t('This album has no tracks') }}
                        </p>
                    </div>

                    <Deferred data="moreByArtist">
                        <template #fallback>
                            <section class="more-by" aria-busy="true">
                                <div class="skeleton mb-3 h-4 w-40" />
                                <div class="shelf">
                                    <div
                                        v-for="index in 5"
                                        :key="index"
                                        class="skeleton aspect-square w-full rounded-md"
                                    />
                                </div>
                            </section>
                        </template>
                        <section v-if="moreBy.length" class="more-by">
                            <h2 class="section-title">
                                {{
                                    $t('More by :artist', {
                                        artist: page.album.artists,
                                    })
                                }}
                                <Link
                                    v-if="page.album.artistId"
                                    :href="
                                        ArtistController.show(
                                            page.album.artistId,
                                        )
                                    "
                                    class="ml-auto text-[12.5px] font-semibold text-dim transition-colors duration-150 hover:text-paper"
                                    >{{ $t('See artist') }}</Link
                                >
                            </h2>
                            <div class="shelf">
                                <AlbumCard
                                    v-for="(album, index) in moreBy"
                                    :key="album.id"
                                    class="reveal"
                                    :style="{ '--i': index }"
                                    :album="album"
                                    :track-count="album.trackCount"
                                />
                            </div>
                        </section>
                    </Deferred>
                </div>
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

/* One column on narrow pages; the cover column sticks beside the tracks. */
.layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 32px;
    align-items: start;
}

.cover {
    width: 100%;
    max-width: 320px;
    aspect-ratio: 1;
    box-shadow:
        0 34px 60px -28px oklch(0 0 0 / 0.95),
        0 0 0 1px oklch(1 0 0 / 0.08);
}

@container (min-width: 860px) {
    .layout {
        grid-template-columns: 280px minmax(0, 1fr);
        gap: 36px;
    }

    .side {
        position: sticky;
        top: 24px;
        max-height: calc(100dvh - 148px);
        overflow-y: auto;
        scrollbar-width: none;
        padding-bottom: 8px;
    }

    .cover {
        width: 280px;
        height: 280px;
    }
}

.dot {
    width: 3px;
    height: 3px;
    border-radius: 999px;
    background: var(--color-faint);
}

/* [Play][Shuffle, fills][⋯] then a full-width row, every control 40px high. */
.actions {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr) auto;
    gap: 10px;
    align-items: center;
    max-width: 360px;
    margin-top: 20px;
}

.actions .round-play {
    width: 40px;
    height: 40px;
}

.actions .wide {
    grid-column: 1 / -1;
}

.track-table {
    display: grid;
    grid-template-columns: 36px minmax(0, 1fr) 64px;
}

@media (min-width: 768px) {
    .track-table {
        grid-template-columns: 36px minmax(0, 1fr) 84px 64px;
    }
}

.head {
    display: flex;
    align-items: center;
    height: 34px;
    padding: 0 10px;
    border-bottom: 1px solid var(--color-line);
    color: var(--color-faint);
    font-size: 12.5px;
    font-weight: 600;
}

/* Scoped display rules outrank Tailwind's `hidden`, so columns hide here. */
.head.from-md {
    display: none;
}

@media (min-width: 768px) {
    .head.from-md {
        display: flex;
    }
}

.more-by {
    margin-top: 40px;
}

.section-title {
    display: flex;
    align-items: baseline;
    gap: 10px;
    margin-bottom: 12px;
    font-size: 14px;
    font-weight: 700;
}

.shelf {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
    gap: 22px 16px;
}

@media (max-width: 480px) {
    .shelf {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }
}
</style>
