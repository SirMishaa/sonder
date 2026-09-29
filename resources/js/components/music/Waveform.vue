<script setup lang="ts">
import { computed } from 'vue';
import { seededHash } from '@/lib/fixtures';

type Props = {
    seed: string;
    bars?: number;
    playing?: boolean;
    strength?: number;
};

const props = withDefaults(defineProps<Props>(), {
    bars: 56,
    playing: true,
    strength: 0.16,
});

/**
 * A stable, per-track waveform. It is decorative (the real audio is not
 * available) and only ever drawn behind what is currently playing.
 */
const shape = computed(() => {
    let state = seededHash(props.seed) || 1;
    const random = () => {
        state ^= state << 13;
        state ^= state >>> 17;
        state ^= state << 5;

        return ((state >>> 0) % 1000) / 1000;
    };

    return Array.from({ length: props.bars }, (_, index) => {
        const envelope =
            0.35 + 0.65 * Math.sin(Math.PI * (index / (props.bars - 1))) ** 0.6;

        return {
            height: 0.18 + random() * 0.82 * envelope,
            delay: random(),
        };
    });
});
</script>

<template>
    <span
        class="waveform"
        :class="{ 'is-paused': !playing }"
        aria-hidden="true"
    >
        <i
            v-for="(bar, index) in shape"
            :key="index"
            :style="{
                '--h': bar.height,
                '--d': bar.delay,
                background: `oklch(0.8 0.15 68 / ${strength})`,
            }"
        />
    </span>
</template>

<style scoped>
.waveform {
    display: flex;
    align-items: center;
    gap: 2px;
    pointer-events: none;
    mask-image: linear-gradient(
        90deg,
        transparent,
        #000 20%,
        #000 80%,
        transparent
    );
}

.waveform i {
    flex: 1;
    min-width: 2px;
    border-radius: 2px;
    height: calc(var(--h) * 80%);
    animation: spectrum 1.1s ease-in-out infinite alternate;
    animation-delay: calc(var(--d) * -1s);
}

.is-paused i {
    animation-play-state: paused;
}
</style>
