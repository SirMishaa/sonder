<script setup lang="ts">
import { Deferred, Head, router, usePage } from '@inertiajs/vue3';
import { useTimeAgo } from '@vueuse/core';
import {
    History,
    LoaderCircle,
    Music2,
    Play,
    RefreshCw,
    TriangleAlert,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';
import PlaylistRefreshController from '@/actions/App/Http/Controllers/PlaylistRefreshController';
import Artwork from '@/components/music/Artwork.vue';
import Equalizer from '@/components/music/Equalizer.vue';
import SuggestionsBlock from '@/components/music/SuggestionsBlock.vue';
import Waveform from '@/components/music/Waveform.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { usePlayer } from '@/composables/usePlayer';
import { useShortcuts } from '@/composables/useShortcuts';
import { useToast } from '@/composables/useToast';
import AppLayout from '@/layouts/AppLayout.vue';

defineOptions({ layout: AppLayout });

type Props = {
    playlistId: string;
    summary: App.Data.PlaylistSummaryData;
    playlist: App.Data.PlaylistData;
    syncState: App.Data.PlaylistSyncStateData;
    suggestionPool?: App.Data.SampledTrackData[];
};

const props = defineProps<Props>();

const page = usePage();
const player = usePlayer();
const { toast } = useToast();

/** Tracks added from the suggestions block. Fixture: shown here only, never saved. */
const previewAdditions = ref<App.Data.TrackData[]>([]);

const tracks = computed(() => [
    ...props.playlist.tracks,
    ...previewAdditions.value,
]);
const source = computed(() => ({
    playlistId: props.playlistId,
    title: props.summary.title,
}));

const checkedAgo = useTimeAgo(() => props.syncState.lastCheckedAt);
const changedAgo = useTimeAgo(
    () => props.syncState.lastChangedAt ?? props.syncState.lastCheckedAt,
);

const isFromThisPlaylist = computed(() =>
    player.isPlayingFrom(props.playlistId),
);

function isCurrent(index: number): boolean {
    const current = player.current.value;
    const track = tracks.value[index];

    return (
        isFromThisPlaylist.value &&
        current !== null &&
        track !== undefined &&
        current.key === `${track.videoId ?? track.title}#${index}`
    );
}

function play(index = 0): void {
    player.playTracks(tracks.value, index, source.value);
}

const refreshing = ref(false);
const refreshError = computed(
    () => (page.props.errors as Record<string, string | undefined>).refresh,
);

function refresh(): void {
    if (refreshing.value || props.syncState.removedAt) {
        return;
    }

    router.visit(PlaylistRefreshController.store(props.playlistId), {
        preserveScroll: true,
        onStart: () => {
            refreshing.value = true;
        },
        onFinish: () => {
            refreshing.value = false;
        },
        onSuccess: () => {
            if (!refreshError.value) {
                toast('Refreshed ', props.summary.title);
            }
        },
    });
}

useShortcuts({ r: refresh });
</script>

<template>
    <Head :title="summary.title" />

    <div class="relative">
        <div class="ambient" aria-hidden="true">
            <div
                :style="{
                    backgroundImage: summary.thumbnailUrl
                        ? `url('${summary.thumbnailUrl}')`
                        : undefined,
                }"
            />
        </div>

        <div class="relative max-w-[1480px] px-8 pt-8">
            <Alert
                v-if="syncState.removedAt"
                variant="destructive"
                class="mb-6"
            >
                <TriangleAlert class="size-4" />
                <AlertTitle>No longer in your YouTube Music library</AlertTitle>
                <AlertDescription>
                    This playlist was deleted or unfollowed on YouTube Music.
                    Its tracks are kept here as they were last synced.
                </AlertDescription>
            </Alert>

            <header
                class="mb-6 grid items-end gap-6 md:grid-cols-[188px_minmax(0,1fr)_auto]"
            >
                <Artwork
                    :src="summary.thumbnailUrl"
                    :alt="summary.title"
                    class="cover size-[188px] rounded-[10px]"
                    eager
                />
                <div class="min-w-0">
                    <p class="reveal text-[13px] font-bold text-amber">
                        Playlist
                    </p>
                    <h1
                        class="reveal mt-1.5 mb-3 text-[44px] leading-none font-extrabold tracking-[-0.04em] text-balance"
                        style="--i: 1"
                    >
                        {{ summary.title }}
                    </h1>
                    <p
                        class="reveal flex flex-wrap items-center gap-x-2 gap-y-1 text-[13px] font-medium text-dim"
                        style="--i: 2"
                    >
                        <span>{{ playlist.trackCount }} tracks</span>
                        <template v-if="playlist.duration">
                            <i class="dot" /><span>{{
                                playlist.duration
                            }}</span>
                        </template>
                        <i class="dot" />
                        <span
                            class="inline-flex items-center gap-1.5"
                            :title="
                                new Date(
                                    syncState.lastCheckedAt,
                                ).toLocaleString()
                            "
                        >
                            <History class="size-3.5" />Checked {{ checkedAgo }}
                        </span>
                        <template v-if="syncState.lastChangedAt">
                            <i class="dot" /><span
                                >Changed {{ changedAgo }}</span
                            >
                        </template>
                    </p>
                </div>
                <div
                    class="reveal flex flex-col items-start gap-1.5 md:items-end"
                    style="--i: 3"
                >
                    <div class="flex gap-2">
                        <button
                            v-if="!syncState.removedAt"
                            type="button"
                            class="button"
                            :disabled="refreshing"
                            @click="refresh"
                        >
                            <LoaderCircle
                                v-if="refreshing"
                                class="size-4 animate-spin"
                            />
                            <RefreshCw v-else class="size-4" />
                            {{ refreshing ? 'Refreshing…' : 'Refresh' }}
                            <kbd v-if="!refreshing" class="keycap">R</kbd>
                        </button>
                        <button
                            type="button"
                            class="button accent-fill border-transparent"
                            :disabled="!tracks.length"
                            @click="play()"
                        >
                            <Play class="size-4 fill-current" />Play
                        </button>
                    </div>
                    <p v-if="refreshError" class="text-sm text-alarm">
                        {{ refreshError }}
                    </p>
                </div>
            </header>

            <Deferred data="suggestionPool">
                <template #fallback>
                    <div class="skeleton mb-6 h-[250px] rounded-xl" />
                </template>
                <SuggestionsBlock
                    v-if="suggestionPool?.length && !syncState.removedAt"
                    class="reveal mb-6"
                    style="--i: 4"
                    :pool="suggestionPool"
                    :playlist-tracks="playlist.tracks"
                    :playlist-title="summary.title"
                    @add="(track) => previewAdditions.push(track)"
                />
            </Deferred>

            <div
                v-if="tracks.length"
                class="reveal track-table"
                style="--i: 5"
                role="table"
                aria-label="Tracks"
            >
                <div class="contents" role="row">
                    <span class="head justify-end" role="columnheader">#</span>
                    <span class="head" role="columnheader">Title</span>
                    <span class="head hidden md:flex" role="columnheader"
                        >Album</span
                    >
                    <span class="head justify-end" role="columnheader"
                        >Time</span
                    >
                </div>
                <div
                    v-for="(track, index) in tracks"
                    :key="`${track.videoId ?? track.title}-${index}`"
                    class="track"
                    :class="{
                        'is-current': isCurrent(index),
                        'is-unavailable': !track.isAvailable,
                        'is-added': index >= playlist.tracks.length,
                    }"
                    role="row"
                    :tabindex="track.isAvailable ? 0 : -1"
                    @click="track.isAvailable && play(index)"
                    @keydown.enter="track.isAvailable && play(index)"
                >
                    <span
                        class="cell justify-end text-[12.5px] font-medium text-faint"
                        role="cell"
                    >
                        <Equalizer
                            v-if="isCurrent(index)"
                            :playing="player.state.playing"
                        />
                        <template v-else>
                            <span class="number">{{ index + 1 }}</span>
                            <Play
                                v-if="track.isAvailable"
                                class="hover-play size-3.5 fill-current text-amber"
                            />
                        </template>
                    </span>
                    <span class="cell gap-2.5" role="cell">
                        <Artwork
                            :src="track.thumbnailUrl"
                            class="size-8 shrink-0 rounded"
                        />
                        <span class="min-w-0">
                            <span class="title block truncate font-semibold">
                                {{ track.title }}
                                <span
                                    v-if="track.isExplicit"
                                    class="ml-1 rounded bg-raised px-1 text-[10px] font-semibold text-dim"
                                    >E</span
                                >
                            </span>
                            <span
                                class="block truncate text-[12.5px] text-dim"
                                >{{ track.artists }}</span
                            >
                        </span>
                    </span>
                    <span
                        class="cell relative hidden text-dim md:flex"
                        role="cell"
                    >
                        <Waveform
                            v-if="isCurrent(index)"
                            :seed="track.title"
                            :playing="player.state.playing"
                            class="absolute inset-x-0 inset-y-2"
                        />
                        <span class="relative truncate">{{ track.album }}</span>
                    </span>
                    <span
                        class="cell justify-end text-[12.5px] font-medium text-faint"
                        role="cell"
                        >{{ track.duration }}</span
                    >
                </div>
            </div>

            <div
                v-else
                class="flex flex-col items-center gap-2 rounded-xl border border-dashed border-line p-12 text-center"
            >
                <Music2 class="size-8 text-faint" />
                <p class="font-semibold">This playlist is empty</p>
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

.cover {
    box-shadow:
        0 34px 60px -28px oklch(0 0 0 / 0.95),
        0 0 0 1px oklch(1 0 0 / 0.08);
}

.dot {
    width: 3px;
    height: 3px;
    border-radius: 999px;
    background: var(--color-faint);
}

.button {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 8px 14px;
    border-radius: 9px;
    border: 1px solid var(--color-line);
    background: oklch(0.19 0.013 60 / 0.8);
    font-weight: 600;
    transition:
        border-color 140ms,
        filter 140ms,
        transform 140ms;
}

.button:hover:not(:disabled) {
    border-color: oklch(0.38 0.014 60);
}

.button.accent-fill {
    background: var(--accent-grad-d);
    color: var(--color-amber-ink);
}

.button.accent-fill:hover:not(:disabled) {
    filter: brightness(1.08);
}

.button:active:not(:disabled) {
    transform: scale(0.97);
}

.button:disabled {
    opacity: 0.6;
}

.track-table {
    display: grid;
    grid-template-columns: 36px minmax(0, 1.4fr) 64px;
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

.track {
    display: contents;
    cursor: pointer;
}

.cell {
    display: flex;
    align-items: center;
    min-width: 0;
    padding: 7px 10px;
    border-bottom: 1px solid oklch(0.285 0.012 60 / 0.55);
    transition: background 140ms;
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
    display: block;
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
