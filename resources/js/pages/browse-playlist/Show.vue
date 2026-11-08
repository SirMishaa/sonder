<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Music2, Play, Shuffle } from 'lucide-vue-next';
import { computed } from 'vue';
import CatalogError from '@/components/catalog/CatalogError.vue';
import CatalogMenu from '@/components/catalog/CatalogMenu.vue';
import ClampedDescription from '@/components/catalog/ClampedDescription.vue';
import Artwork from '@/components/music/Artwork.vue';
import TrackRow from '@/components/music/TrackRow.vue';
import { useTrackListPlayback } from '@/composables/useTrackListPlayback';
import AppLayout from '@/layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

type Props = {
    page: App.Data.PublicPlaylistPageData | null;
    catalogError?: string | null;
};

const props = defineProps<Props>();

const tracks = computed(() => props.page?.tracks ?? []);
const source = computed(() => ({
    playlistId: null,
    title: props.page?.playlist.title ?? '',
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

const youTubeMusicUrl = computed(() =>
    props.page
        ? `https://music.youtube.com/playlist?list=${encodeURIComponent(props.page.playlist.id)}`
        : '',
);
</script>

<template>
    <Head :title="page?.playlist.title ?? $t('Playlist')" />

    <div v-if="!page" class="max-w-[1320px] px-4 pt-6 md:px-8 md:pt-8">
        <CatalogError
            :message="catalogError ?? $t('YouTube Music could not be reached.')"
        />
    </div>

    <div v-else class="relative">
        <div class="ambient" aria-hidden="true">
            <div
                :style="{
                    backgroundImage: page.playlist.thumbnailUrl
                        ? `url('${page.playlist.thumbnailUrl}')`
                        : undefined,
                }"
            />
        </div>

        <div class="relative max-w-[1320px] px-4 pt-6 md:px-8 md:pt-8">
            <header class="hero">
                <Artwork
                    :src="page.playlist.thumbnailUrl"
                    :alt="page.playlist.title"
                    class="cover rounded-[10px]"
                    eager
                />
                <div class="min-w-0">
                    <p class="reveal text-[13px] font-bold text-amber">
                        {{ $t('Playlist') }}
                    </p>
                    <h1
                        class="reveal mt-1.5 mb-3 text-[34px] leading-none font-extrabold tracking-[-0.04em] text-balance md:text-[44px]"
                        style="--i: 1"
                    >
                        {{ page.playlist.title }}
                    </h1>
                    <p
                        class="reveal flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px] font-medium text-dim"
                        style="--i: 2"
                    >
                        <template v-if="page.playlist.author">
                            <span class="font-bold text-paper">{{
                                page.playlist.author
                            }}</span>
                            <i class="dot" />
                        </template>
                        <span>{{
                            $tChoice(
                                ':count track|:count tracks',
                                page.playlist.trackCount ?? tracks.length,
                            )
                        }}</span>
                    </p>
                    <ClampedDescription
                        v-if="page.description"
                        :key="page.playlist.id"
                        class="reveal mt-3 max-w-[64ch]"
                        style="--i: 2"
                        :text="page.description"
                    />
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

            <div
                v-if="tracks.length"
                class="track-table reveal"
                style="--i: 4"
                role="table"
                :aria-label="$t('Tracks')"
            >
                <div class="contents" role="row">
                    <span class="head justify-end" role="columnheader">#</span>
                    <span class="head" role="columnheader">{{
                        $t('Title')
                    }}</span>
                    <span class="head from-md" role="columnheader">{{
                        $t('Album')
                    }}</span>
                    <span class="head justify-end" role="columnheader">{{
                        $t('Time')
                    }}</span>
                </div>
                <TrackRow
                    v-for="(track, index) in tracks"
                    :key="`${track.videoId ?? track.title}-${index}`"
                    :track="track"
                    :position="index + 1"
                    :current="isCurrent(index)"
                    :playing="player.state.playing"
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
                <p class="font-semibold">{{ $t('This playlist is empty') }}</p>
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

.hero {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    justify-items: start;
    gap: 20px;
    margin-bottom: 28px;
}

.hero > div {
    justify-self: stretch;
}

.cover {
    width: 132px;
    height: 132px;
    box-shadow:
        0 34px 60px -28px oklch(0 0 0 / 0.95),
        0 0 0 1px oklch(1 0 0 / 0.08);
}

@media (min-width: 768px) {
    .hero {
        grid-template-columns: 188px minmax(0, 1fr);
        align-items: end;
        gap: 28px;
    }

    .cover {
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

.track-table {
    display: grid;
    grid-template-columns: 36px minmax(0, 1fr) 64px;
}

@media (min-width: 768px) {
    .track-table {
        grid-template-columns: 36px minmax(0, 1.4fr) minmax(0, 1fr) 64px;
    }
}

.head {
    display: flex;
    padding: 8px 10px;
    border-bottom: 1px solid var(--color-line);
    color: var(--color-faint);
    font-size: 12px;
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
</style>
