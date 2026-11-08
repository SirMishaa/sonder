<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Play, Shuffle } from 'lucide-vue-next';
import { computed } from 'vue';
import ArtistController from '@/actions/App/Http/Controllers/ArtistController';
import Artwork from '@/components/music/Artwork.vue';
import TrackRow from '@/components/music/TrackRow.vue';
import { usePlayer } from '@/composables/usePlayer';
import type { QueueSource } from '@/composables/usePlayer';
import { useTrackTap } from '@/composables/useTrackTap';
import { shuffled } from '@/lib/utils';

/**
 * The top artist of a search as a block tinted by its own picture: portrait,
 * name, Play / Shuffle / See artist, and on the right what the library
 * already holds from it.
 */
type Props = {
    artist: App.Data.CatalogArtistData;
    inLibrary: App.Data.ArtistInLibraryData | null;
    /** The artist's most recent library tracks, at most three are shown. */
    libraryTracks: App.Data.CatalogTrackData[];
    /** What Play and Shuffle queue. */
    tracks: App.Data.CatalogTrackData[];
    source: QueueSource;
};

const props = defineProps<Props>();

const player = usePlayer();
const { tap, preventSelection } = useTrackTap(() => props.source);

const rows = computed(() => props.libraryTracks.slice(0, 3));
const hasLibrary = computed(
    () => (props.inLibrary?.trackCount ?? 0) > 0 && rows.value.length > 0,
);
const playable = computed(() =>
    props.tracks.some((track) => track.isAvailable),
);

function play(): void {
    player.playTracks(props.tracks, 0, props.source, 'search');
}

function shuffle(): void {
    player.playTracks(shuffled(props.tracks), 0, props.source, 'search');
}

function playRow(index: number): void {
    player.playTracks(rows.value, index, props.source, 'search');
}

function onRowTap(event: MouseEvent, index: number): void {
    const track = rows.value[index];

    if (track?.isAvailable) {
        tap(event, track, index, () => playRow(index));
    }
}
</script>

<template>
    <section
        class="spot reveal"
        :class="{ 'has-library': hasLibrary }"
        :aria-label="$t('Top result')"
    >
        <div class="spot-bg" aria-hidden="true">
            <div
                :style="{
                    backgroundImage: artist.thumbnailUrl
                        ? `url('${artist.thumbnailUrl}')`
                        : undefined,
                }"
            />
        </div>

        <div class="spot-main">
            <Artwork
                :src="artist.thumbnailUrl"
                :alt="artist.name"
                class="portrait rounded-full"
                eager
            />
            <div class="min-w-0">
                <p class="text-[13px] font-bold text-amber">
                    {{ $t('Top result · Artist') }}
                </p>
                <h2 class="spot-name truncate">
                    <Link
                        :href="ArtistController.show(artist.id)"
                        class="hover:underline hover:decoration-line hover:underline-offset-4"
                        >{{ artist.name }}</Link
                    >
                </h2>
                <p
                    v-if="artist.subscribers"
                    class="text-[13px] font-medium text-dim"
                >
                    {{
                        $t(':count subscribers', { count: artist.subscribers })
                    }}
                </p>
                <div class="mt-4 flex flex-wrap items-center gap-2.5">
                    <button
                        type="button"
                        class="play"
                        :disabled="!playable"
                        :aria-label="$t('Play')"
                        :title="$t('Play')"
                        @click="play"
                    >
                        <Play class="size-5 fill-current" />
                    </button>
                    <button
                        type="button"
                        class="button"
                        :disabled="!playable"
                        @click="shuffle"
                    >
                        <Shuffle class="size-4" aria-hidden="true" />{{
                            $t('Shuffle')
                        }}
                    </button>
                    <Link
                        :href="ArtistController.show(artist.id)"
                        class="button"
                        >{{ $t('See artist') }}</Link
                    >
                </div>
            </div>
        </div>

        <div v-if="hasLibrary && inLibrary" class="spot-lib">
            <h3 class="mb-1.5 flex items-baseline gap-2.5 font-bold">
                {{ $t('In your library') }}
                <span class="text-[12.5px] font-medium text-faint">
                    {{
                        $tChoice(
                            ':count track|:count tracks',
                            inLibrary.trackCount,
                        )
                    }}
                    <template v-if="inLibrary.albumCount">
                        ·
                        {{
                            $tChoice(
                                ':count album|:count albums',
                                inLibrary.albumCount,
                            )
                        }}
                    </template>
                </span>
            </h3>
            <div
                class="lib-rows"
                role="table"
                :aria-label="$t('In your library')"
            >
                <TrackRow
                    v-for="(track, index) in rows"
                    :key="track.videoId ?? index"
                    :track="track"
                    :position="index + 1"
                    :current="
                        player.isCurrentTrack(source.playlistId, track, index)
                    "
                    :playing="player.state.playing"
                    :show-album="false"
                    :show-artist="false"
                    @mousedown="preventSelection"
                    @tap="onRowTap($event, index)"
                    @play="playRow(index)"
                />
            </div>
        </div>
    </section>
</template>

<style scoped>
.spot {
    position: relative;
    isolation: isolate;
    overflow: hidden;
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 18px;
    padding: 18px;
    border: 1px solid oklch(1 0 0 / 0.07);
    border-radius: 16px;
    box-shadow: 0 30px 60px -36px oklch(0 0 0 / 0.9);
}

.spot-bg {
    position: absolute;
    inset: 0;
    z-index: -1;
    pointer-events: none;
}

.spot-bg div {
    position: absolute;
    inset: -80px;
    background-size: cover;
    background-position: center;
    filter: blur(60px) saturate(1.5);
    opacity: 0.55;
}

.spot-bg::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(
        100deg,
        oklch(0.165 0.012 60 / 0.25) 0%,
        oklch(0.165 0.012 60 / 0.72) 58%,
        oklch(0.165 0.012 60 / 0.88) 100%
    );
}

.spot-bg::before {
    content: '';
    position: absolute;
    inset: 0;
    z-index: 1;
    background-image: var(--grain);
    opacity: 0.08;
    mix-blend-mode: overlay;
}

.spot-main {
    display: grid;
    grid-template-columns: 88px minmax(0, 1fr);
    gap: 16px;
    align-items: center;
}

.portrait {
    width: 88px;
    height: 88px;
    box-shadow:
        0 30px 50px -24px oklch(0 0 0 / 0.95),
        0 0 0 1px oklch(1 0 0 / 0.1);
}

.spot-name {
    margin: 6px 0 8px;
    font-size: 32px;
    font-weight: 800;
    line-height: 1.05;
    letter-spacing: -0.04em;
}

.spot-lib {
    min-width: 0;
    padding-top: 14px;
    border-top: 1px solid oklch(1 0 0 / 0.08);
}

.lib-rows {
    display: grid;
    grid-template-columns: 36px minmax(0, 1fr) 64px;
}

.lib-rows :deep(.cell) {
    border-bottom-color: oklch(1 0 0 / 0.06);
}

.lib-rows :deep(.track:hover .cell) {
    background: oklch(1 0 0 / 0.05);
}

@media (min-width: 1024px) {
    .spot {
        gap: 28px;
        padding: 26px;
    }

    .spot.has-library {
        grid-template-columns: minmax(0, 1.15fr) minmax(0, 1fr);
    }

    .spot-main {
        grid-template-columns: 148px minmax(0, 1fr);
        gap: 24px;
    }

    .portrait {
        width: 148px;
        height: 148px;
    }

    .spot-name {
        font-size: 44px;
        line-height: 1;
    }

    .spot-lib {
        padding-top: 0;
        padding-left: 24px;
        border-top: 0;
        border-left: 1px solid oklch(1 0 0 / 0.08);
    }
}

.play {
    display: grid;
    place-items: center;
    width: 46px;
    height: 46px;
    border-radius: 999px;
    background: var(--accent-grad-d);
    color: var(--color-amber-ink);
    box-shadow: 0 10px 24px -12px oklch(0.66 0.17 48 / 0.8);
    transition:
        transform 140ms,
        filter 140ms;
}

.play:hover:not(:disabled) {
    filter: brightness(1.06);
}

.play:active:not(:disabled) {
    transform: scale(0.95);
}

.button {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 14px;
    border: 1px solid var(--color-line);
    border-radius: 9px;
    background: oklch(0.19 0.013 60 / 0.8);
    font-weight: 600;
    transition:
        background 140ms,
        border-color 140ms,
        transform 140ms;
}

.button:hover:not(:disabled) {
    border-color: oklch(0.38 0.014 60);
    background: var(--color-raised);
}

.button:active:not(:disabled) {
    transform: scale(0.97);
}

.play:disabled,
.button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}
</style>
