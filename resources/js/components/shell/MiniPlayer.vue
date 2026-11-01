<script setup lang="ts">
import { Pause, Play, SkipForward } from 'lucide-vue-next';
import Artwork from '@/components/music/Artwork.vue';
import { useMobileSheets } from '@/composables/useMobileSheets';
import { usePlayer } from '@/composables/usePlayer';

/**
 * The phone's player: artwork, title, play and next above the tab bar, with
 * the progress drawn along its bottom edge. Tapping it opens now playing.
 */
const player = usePlayer();
const { openNowPlaying } = useMobileSheets();
</script>

<template>
    <div
        v-if="player.current.value"
        class="material-glass mini relative flex items-center gap-3 overflow-hidden rounded-[14px] py-2 pr-1.5 pl-2"
        role="region"
        :aria-label="$t('Player')"
    >
        <button
            type="button"
            class="flex min-w-0 flex-1 items-center gap-3 text-left"
            :aria-label="$t('Open now playing')"
            @click="openNowPlaying"
        >
            <Artwork
                :key="player.current.value.key"
                :src="player.current.value.thumbnailUrl"
                class="swap size-11 shrink-0 rounded-md"
                data-queue-target
                eager
            />
            <span
                :key="`${player.current.value.key}-text`"
                class="swap min-w-0"
            >
                <span class="block truncate text-[15px] font-bold">
                    {{ player.current.value.title }}
                </span>
                <span class="block truncate text-[13px] text-dim">
                    {{ player.current.value.artists }}
                </span>
            </span>
        </button>
        <button
            type="button"
            class="grid size-11 shrink-0 place-items-center rounded-full text-paper active:bg-raised"
            :aria-label="player.state.playing ? $t('Pause') : $t('Play')"
            :disabled="player.state.unavailable"
            @click="player.toggle"
        >
            <Pause
                v-if="player.state.playing"
                class="size-[22px] fill-current"
            />
            <Play v-else class="ml-0.5 size-[22px] fill-current" />
        </button>
        <button
            type="button"
            class="grid size-11 shrink-0 place-items-center rounded-full text-dim active:bg-raised"
            :aria-label="$t('Next')"
            :disabled="player.state.unavailable"
            @click="player.next"
        >
            <SkipForward class="size-5 fill-current" />
        </button>
        <i
            class="progress absolute inset-x-3 bottom-0 h-0.5 origin-left rounded-full"
            :style="{ transform: `scaleX(${player.progress.value})` }"
            aria-hidden="true"
        />
    </div>
</template>

<style scoped>
.mini::after {
    content: '';
    position: absolute;
    inset: 0;
    z-index: -1;
    pointer-events: none;
    background: radial-gradient(
        120% 160% at 8% 0%,
        oklch(0.8 0.15 68 / 0.12),
        transparent 60%
    );
}

.swap {
    animation: swap-in 380ms var(--ease-out-quint) both;
}

.progress {
    background: var(--accent-grad);
    box-shadow: 0 0 8px oklch(0.8 0.15 68 / 0.45);
    transition: transform 250ms linear;
}
</style>
