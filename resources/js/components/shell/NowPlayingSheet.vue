<script setup lang="ts">
import {
    ChevronDown,
    ListMusic,
    Pause,
    Play,
    SkipBack,
    SkipForward,
} from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
import { useMobileSheets } from '@/composables/useMobileSheets';
import { formatSeconds, usePlayer } from '@/composables/usePlayer';
import { useSheetDrag } from '@/composables/useSheetDrag';

/**
 * The phone's full-screen player: large artwork, a seek bar sized for a
 * thumb, the transport controls and the way into up next. Pulled down or
 * closed with the chevron, it folds back into the mini player.
 */
const player = usePlayer();
const { sheets, openQueue } = useMobileSheets();

const close = (): void => {
    sheets.nowPlaying = false;
};

const { offset, dragging, grab } = useSheetDrag(close);

/**
 * YouTube's 480px still, framed 16:9 so its letterbox bars fall outside:
 * the stored thumbnail is too small at this size.
 */
const largeArtwork = computed(() => {
    const track = player.current.value;

    return track?.videoId
        ? `https://i.ytimg.com/vi/${track.videoId}/hqdefault.jpg`
        : (track?.thumbnailUrl ?? null);
});
const artworkFailed = ref(false);
const artwork = computed(() =>
    artworkFailed.value
        ? (player.current.value?.thumbnailUrl ?? null)
        : largeArtwork.value,
);

watch(largeArtwork, () => {
    artworkFailed.value = false;
});

/** Where the thumb is while seeking; the player moves on release. */
const scrubbing = ref<number | null>(null);
const position = computed(() => scrubbing.value ?? player.state.elapsed);
const percent = computed(() =>
    player.totalSeconds.value > 0
        ? (position.value / player.totalSeconds.value) * 100
        : 0,
);

function onScrub(event: Event): void {
    scrubbing.value = Number((event.target as HTMLInputElement).value);
}

function onSeek(event: Event): void {
    player.seek(Number((event.target as HTMLInputElement).value));
    scrubbing.value = null;
}
</script>

<template>
    <Transition name="sheet">
        <section
            v-if="sheets.nowPlaying && player.current.value"
            class="sheet now-playing fixed inset-0 z-40 flex flex-col bg-ink"
            :class="{ 'is-dragging': dragging }"
            :style="{ '--drag': `${offset}px` }"
            role="dialog"
            :aria-label="$t('Now playing')"
        >
            <div
                class="flex min-h-0 flex-1 touch-none flex-col select-none"
                @pointerdown="grab"
            >
                <header class="flex items-center gap-2 px-3 pt-2">
                    <button
                        type="button"
                        class="grid size-11 place-items-center rounded-full text-dim active:bg-raised"
                        :aria-label="$t('Close')"
                        @click="close"
                    >
                        <ChevronDown class="size-6" />
                    </button>
                    <p class="min-w-0 flex-1 text-center">
                        <span class="block text-[13px] font-bold text-amber">
                            {{ $t('Now playing') }}
                        </span>
                        <span
                            v-if="player.state.source"
                            class="block truncate text-[12.5px] text-faint"
                        >
                            {{ player.state.source.title }}
                        </span>
                    </p>
                    <span class="size-11" aria-hidden="true" />
                </header>

                <div class="flex flex-1 items-center px-5 py-6">
                    <div
                        class="artwork mx-auto aspect-video w-full max-w-[560px] overflow-hidden rounded-xl bg-raised"
                    >
                        <img
                            v-if="artwork"
                            :key="artwork"
                            :src="artwork"
                            alt=""
                            class="size-full object-cover"
                            @error="artworkFailed = true"
                        />
                    </div>
                </div>
            </div>

            <div class="flex flex-col gap-5 px-7 pb-6">
                <div class="min-w-0">
                    <h2
                        class="truncate text-[22px] leading-tight font-extrabold tracking-[-0.02em]"
                    >
                        {{ player.current.value.title }}
                    </h2>
                    <p class="mt-1 flex min-w-0 items-center gap-2 text-dim">
                        <span class="truncate">{{
                            player.current.value.artists
                        }}</span>
                        <span
                            v-if="player.current.value.genres?.length"
                            class="genre"
                            >{{ player.current.value.genres[0] }}</span
                        >
                    </p>
                </div>

                <div>
                    <input
                        type="range"
                        min="0"
                        :max="player.totalSeconds.value || 1"
                        step="1"
                        :value="position"
                        class="seek"
                        :style="{ '--v': `${percent}%` }"
                        :aria-label="$t('Playback progress')"
                        :aria-valuetext="`${formatSeconds(position)} / ${formatSeconds(player.totalSeconds.value)}`"
                        @input="onScrub"
                        @change="onSeek"
                    />
                    <p
                        class="mt-1.5 flex justify-between text-xs font-medium text-faint tabular-nums"
                    >
                        <span>{{ formatSeconds(position) }}</span>
                        <span>{{
                            formatSeconds(player.totalSeconds.value)
                        }}</span>
                    </p>
                </div>

                <div class="flex items-center justify-center gap-8">
                    <button
                        type="button"
                        class="grid size-14 place-items-center rounded-full text-paper active:bg-raised"
                        :aria-label="$t('Previous')"
                        :disabled="player.state.unavailable"
                        @click="player.previous"
                    >
                        <SkipBack class="size-7 fill-current" />
                    </button>
                    <button
                        type="button"
                        class="play grid size-[72px] place-items-center rounded-full"
                        :aria-label="
                            player.state.playing ? $t('Pause') : $t('Play')
                        "
                        :disabled="player.state.unavailable"
                        @click="player.toggle"
                    >
                        <Pause
                            v-if="player.state.playing"
                            class="size-8 fill-current"
                        />
                        <Play v-else class="ml-1 size-8 fill-current" />
                    </button>
                    <button
                        type="button"
                        class="grid size-14 place-items-center rounded-full text-paper active:bg-raised"
                        :aria-label="$t('Next')"
                        :disabled="player.state.unavailable"
                        @click="player.next"
                    >
                        <SkipForward class="size-7 fill-current" />
                    </button>
                </div>

                <button
                    type="button"
                    class="mx-auto inline-flex items-center gap-2 rounded-full border border-line px-4 py-2.5 text-sm font-semibold text-dim active:bg-raised"
                    @click="openQueue"
                >
                    <ListMusic class="size-[18px] text-amber" />
                    {{ $t('Up next') }}
                    <span
                        v-if="player.upNext.value.length"
                        class="text-faint tabular-nums"
                        >{{ player.upNext.value.length }}</span
                    >
                </button>
            </div>
        </section>
    </Transition>
</template>

<style scoped>
.now-playing {
    background:
        radial-gradient(
            110% 60% at 20% 0%,
            oklch(0.8 0.15 68 / 0.14),
            transparent 65%
        ),
        var(--color-ink);
}

.artwork {
    box-shadow:
        0 34px 60px -28px oklch(0 0 0 / 0.95),
        0 0 0 1px oklch(1 0 0 / 0.08);
}

.genre {
    flex-shrink: 0;
    padding: 0 8px;
    border: 1px solid var(--color-line);
    border-radius: 999px;
    font-size: 12px;
    line-height: 19px;
    white-space: nowrap;
}

.play {
    background: var(--accent-grad-d);
    color: var(--color-amber-ink);
    box-shadow: 0 10px 28px -8px oklch(0.7 0.17 55 / 0.7);
    transition: transform 140ms;
}

.play:active {
    transform: scale(0.94);
}

button:disabled {
    opacity: 0.4;
}

.seek {
    appearance: none;
    display: block;
    width: 100%;
    height: 28px;
    background: transparent;
    cursor: pointer;
}

.seek::-webkit-slider-runnable-track {
    height: 4px;
    border-radius: 4px;
    background: linear-gradient(
        90deg,
        oklch(0.84 0.14 75),
        oklch(0.66 0.17 48) var(--v),
        var(--color-line) var(--v)
    );
}

.seek::-webkit-slider-thumb {
    appearance: none;
    width: 16px;
    height: 16px;
    margin-top: -6px;
    border-radius: 50%;
    background: oklch(0.92 0.08 75);
    box-shadow: 0 0 0 4px oklch(0.8 0.15 68 / 0.25);
}

.seek::-moz-range-track {
    height: 4px;
    border-radius: 4px;
    background: linear-gradient(
        90deg,
        oklch(0.84 0.14 75),
        oklch(0.66 0.17 48) var(--v),
        var(--color-line) var(--v)
    );
}

.seek::-moz-range-thumb {
    width: 16px;
    height: 16px;
    border: 0;
    border-radius: 50%;
    background: oklch(0.92 0.08 75);
}
</style>
