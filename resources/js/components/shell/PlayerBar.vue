<script setup lang="ts">
import {
    ListMusic,
    Pause,
    Play,
    SkipBack,
    SkipForward,
    Volume2,
    VolumeX,
} from 'lucide-vue-next';
import { computed } from 'vue';
import Artwork from '@/components/music/Artwork.vue';
import { formatSeconds, usePlayer } from '@/composables/usePlayer';

const player = usePlayer();

const volume = computed(() => (player.state.muted ? 0 : player.state.volume));

function onVolume(event: Event): void {
    player.setVolume(Number((event.target as HTMLInputElement).value));
}

function onSeek(event: MouseEvent): void {
    const box = (event.currentTarget as HTMLElement).getBoundingClientRect();

    if (box.width === 0) {
        return;
    }

    const ratio = Math.min(
        1,
        Math.max(0, (event.clientX - box.left) / box.width),
    );
    player.seek(ratio * player.totalSeconds.value);
}

const controlsDisabled = computed(
    () => !player.current.value || player.state.unavailable,
);
</script>

<template>
    <div
        class="material-glass player grid items-center gap-5 overflow-hidden rounded-2xl px-3.5 py-2.5"
        role="region"
        :aria-label="$t('Player')"
    >
        <div class="flex min-w-0 items-center gap-3">
            <template v-if="player.current.value">
                <Artwork
                    :key="player.current.value.key"
                    :src="player.current.value.thumbnailUrl"
                    class="swap size-12 shrink-0 rounded-md"
                    eager
                />
                <div
                    :key="`${player.current.value.key}-text`"
                    class="swap min-w-0"
                >
                    <p class="truncate font-bold">
                        {{ player.current.value.title }}
                    </p>
                    <p class="truncate text-[13px] text-dim">
                        {{ player.current.value.artists }}
                    </p>
                </div>
            </template>
            <template v-else>
                <div
                    class="grid size-12 shrink-0 place-items-center rounded-md bg-raised"
                >
                    <ListMusic class="size-5 text-faint" />
                </div>
                <p class="min-w-0 text-[13px] text-dim">
                    {{ $t('Pick a track in a playlist to start.') }}
                </p>
            </template>
        </div>

        <div class="flex flex-col items-center gap-1.5">
            <div class="flex items-center gap-3.5 text-dim">
                <button
                    type="button"
                    class="icon-button"
                    :aria-label="$t('Previous  P')"
                    :disabled="controlsDisabled"
                    @click="player.previous"
                >
                    <SkipBack class="size-[18px] fill-current" />
                </button>
                <button
                    type="button"
                    class="play-button"
                    :aria-label="
                        player.state.playing ? $t('Pause') : $t('Play')
                    "
                    :disabled="controlsDisabled"
                    @click="player.toggle"
                >
                    <Pause
                        v-if="player.state.playing"
                        class="size-[18px] fill-current"
                    />
                    <Play v-else class="ml-0.5 size-[18px] fill-current" />
                </button>
                <button
                    type="button"
                    class="icon-button"
                    :aria-label="$t('Next  N')"
                    :disabled="controlsDisabled"
                    @click="player.next"
                >
                    <SkipForward class="size-[18px] fill-current" />
                </button>
            </div>
            <div
                class="grid w-full max-w-[540px] grid-cols-[38px_1fr_38px] items-center gap-2.5 text-xs font-medium text-faint"
            >
                <span>{{ formatSeconds(player.state.elapsed) }}</span>
                <div
                    class="cursor-pointer py-1.5"
                    data-testid="seek-bar"
                    @click="onSeek"
                >
                    <div
                        class="h-1 overflow-hidden rounded-full bg-line"
                        role="progressbar"
                        :aria-valuenow="Math.round(player.progress.value * 100)"
                        aria-valuemin="0"
                        aria-valuemax="100"
                        :aria-label="$t('Playback progress')"
                    >
                        <i
                            class="progress block h-full origin-left"
                            :style="{
                                transform: `scaleX(${player.progress.value})`,
                            }"
                        />
                    </div>
                </div>
                <span class="text-right">{{
                    formatSeconds(player.totalSeconds.value)
                }}</span>
            </div>
        </div>

        <div class="flex items-center justify-end gap-1.5">
            <button
                type="button"
                class="icon-button"
                :aria-label="player.state.muted ? $t('Unmute') : $t('Mute')"
                @click="player.toggleMute"
            >
                <VolumeX v-if="volume === 0" class="size-[18px]" />
                <Volume2 v-else class="size-[18px]" />
            </button>
            <input
                type="range"
                min="0"
                max="100"
                :value="volume"
                class="volume"
                :style="{ '--v': `${volume}%` }"
                :aria-label="$t('Volume')"
                @input="onVolume"
            />
            <button
                type="button"
                class="queue-button"
                :class="{ 'is-on': player.state.queueOpen }"
                data-queue-target
                :title="$t('Up next  Q')"
                @click="player.toggleQueue"
            >
                <ListMusic class="size-[18px]" />
                {{ $t('Up next') }}
            </button>
        </div>
    </div>
</template>

<style scoped>
.player {
    grid-template-columns: minmax(160px, 1fr) minmax(300px, 1.6fr) minmax(
            160px,
            1fr
        );
}

.player::after {
    content: '';
    position: absolute;
    inset: 0;
    z-index: -1;
    pointer-events: none;
    background: radial-gradient(
        120% 140% at 12% 0%,
        oklch(0.8 0.15 68 / 0.1),
        transparent 55%
    );
}

.swap {
    animation: swap-in 380ms var(--ease-out-quint) both;
}

.progress {
    background: var(--accent-grad);
    box-shadow: 0 0 10px oklch(0.8 0.15 68 / 0.45);
    transition: transform 250ms linear;
}

.icon-button {
    display: grid;
    place-items: center;
    width: 32px;
    height: 32px;
    border-radius: 999px;
    color: var(--color-dim);
    transition:
        color 140ms,
        background 140ms;
}

.icon-button:hover:not(:disabled) {
    color: var(--color-paper);
    background: var(--color-raised);
}

.icon-button:disabled,
.play-button:disabled {
    opacity: 0.4;
    cursor: default;
}

.play-button {
    display: grid;
    place-items: center;
    width: 38px;
    height: 38px;
    border-radius: 999px;
    background: var(--accent-grad-d);
    color: var(--color-amber-ink);
    box-shadow: 0 6px 18px -6px oklch(0.7 0.17 55 / 0.7);
    transition:
        filter 140ms,
        transform 140ms;
}

.play-button:hover:not(:disabled) {
    filter: brightness(1.08);
}

.play-button:active:not(:disabled) {
    transform: scale(0.94);
}

.volume {
    appearance: none;
    width: clamp(56px, 7vw, 96px);
    height: 4px;
    border-radius: 4px;
    cursor: pointer;
    background: linear-gradient(
        90deg,
        oklch(0.84 0.14 75),
        oklch(0.66 0.17 48) var(--v),
        var(--color-line) var(--v)
    );
}

.volume::-webkit-slider-thumb {
    appearance: none;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: oklch(0.9 0.1 75);
    box-shadow: 0 0 0 3px oklch(0.8 0.15 68 / 0.25);
    opacity: 0;
    transition: opacity 140ms;
}

.volume:hover::-webkit-slider-thumb,
.volume:focus-visible::-webkit-slider-thumb {
    opacity: 1;
}

.volume::-moz-range-thumb {
    width: 12px;
    height: 12px;
    border: 0;
    border-radius: 50%;
    background: oklch(0.9 0.1 75);
}

.queue-button {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    white-space: nowrap;
    padding: 6px 9px;
    border: 1px solid transparent;
    border-radius: 8px;
    color: var(--color-dim);
    font-size: 13px;
    font-weight: 600;
}

.queue-button:hover,
.queue-button.is-on {
    color: var(--color-paper);
    background: var(--color-raised);
    border-color: var(--color-line);
}

.queue-button.is-on svg {
    color: var(--color-amber);
}
</style>
