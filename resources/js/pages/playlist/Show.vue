<script setup lang="ts">
import { Deferred, Head, router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
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
import AmbientBackdrop from '@/components/music/AmbientBackdrop.vue';
import Artwork from '@/components/music/Artwork.vue';
import SuggestionsBlock from '@/components/music/SuggestionsBlock.vue';
import TrackRow from '@/components/music/TrackRow.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { usePlayer } from '@/composables/usePlayer';
import { useRelativeTime } from '@/composables/useRelativeTime';
import { useShortcuts } from '@/composables/useShortcuts';
import { useToast } from '@/composables/useToast';
import { useTrackTap } from '@/composables/useTrackTap';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatDateTime } from '@/lib/i18n';

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
const { tap, preventSelection } = useTrackTap(() => source.value);

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

const checkedAgo = useRelativeTime(() => props.syncState.lastCheckedAt);
const changedAgo = useRelativeTime(
    () => props.syncState.lastChangedAt ?? props.syncState.lastCheckedAt,
);

function isCurrent(index: number): boolean {
    const track = tracks.value[index];

    return (
        track !== undefined &&
        player.isCurrentTrack(props.playlistId, track, index)
    );
}

function play(index = 0): void {
    player.playTracks(tracks.value, index, source.value, 'playlist');
}

function onTrackClick(event: MouseEvent, index: number): void {
    const track = tracks.value[index];

    if (track?.isAvailable) {
        tap(event, track, index, () => play(index));
    }
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
                toast(trans('Refreshed :title'), props.summary.title);
            }
        },
    });
}

useShortcuts({ r: refresh });
</script>

<template>
    <Head :title="summary.title" />

    <div class="relative">
        <AmbientBackdrop :src="summary.thumbnailUrl" />

        <div class="relative max-w-[1480px] px-4 pt-6 md:px-8 md:pt-8">
            <Alert
                v-if="syncState.removedAt"
                variant="destructive"
                class="mb-6"
            >
                <TriangleAlert class="size-4" />
                <AlertTitle>{{
                    $t('No longer in your YouTube Music library')
                }}</AlertTitle>
                <AlertDescription>
                    {{
                        $t(
                            'This playlist was deleted or unfollowed on YouTube Music. Its tracks are kept here as they were last synced.',
                        )
                    }}
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
                        {{ $t('Playlist') }}
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
                        <span>{{
                            $tChoice(
                                ':count track|:count tracks',
                                playlist.trackCount ?? 0,
                            )
                        }}</span>
                        <template v-if="playlist.duration">
                            <i class="meta-dot" /><span>{{
                                playlist.duration
                            }}</span>
                        </template>
                        <i class="meta-dot" />
                        <span
                            class="inline-flex items-center gap-1.5"
                            :title="formatDateTime(syncState.lastCheckedAt)"
                        >
                            <History class="size-3.5" />{{
                                $t('Checked :ago', { ago: checkedAgo })
                            }}
                        </span>
                        <template v-if="syncState.lastChangedAt">
                            <i class="meta-dot" /><span>{{
                                $t('Changed :ago', { ago: changedAgo })
                            }}</span>
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
                            <Play class="size-4 fill-current" />{{ $t('Play') }}
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
                :aria-label="$t('Tracks')"
            >
                <div class="contents" role="row">
                    <span class="track-head justify-end" role="columnheader"
                        >#</span
                    >
                    <span class="track-head" role="columnheader">{{
                        $t('Title')
                    }}</span>
                    <span class="track-head from-md" role="columnheader">{{
                        $t('Album')
                    }}</span>
                    <span class="track-head from-lg" role="columnheader">{{
                        $t('Genre')
                    }}</span>
                    <span class="track-head justify-end" role="columnheader">{{
                        $t('Time')
                    }}</span>
                </div>
                <TrackRow
                    v-for="(track, index) in tracks"
                    :key="`${track.videoId ?? track.title}-${index}`"
                    :class="{ 'is-added': index >= playlist.tracks.length }"
                    :track="track"
                    :position="index + 1"
                    :current="isCurrent(index)"
                    :playing="player.state.playing"
                    genre-column
                    @mousedown="preventSelection"
                    @tap="onTrackClick($event, index)"
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
.cover {
    box-shadow:
        0 34px 60px -28px oklch(0 0 0 / 0.95),
        0 0 0 1px oklch(1 0 0 / 0.08);
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

@media (min-width: 1024px) {
    .track-table {
        grid-template-columns:
            36px minmax(0, 1.4fr) minmax(0, 1fr) minmax(0, 0.9fr)
            64px;
    }
}
</style>
