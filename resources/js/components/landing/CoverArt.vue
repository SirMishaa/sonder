<script setup lang="ts">
import { computed, useId } from 'vue';

type Props = {
    hue: number;
};

const props = defineProps<Props>();

const gradientId = `cover-${useId()}`;

/**
 * A generated sleeve: a hue-driven gradient with a record-groove motif, so
 * the showcase never ships someone else's artwork.
 */
const colors = computed(() => ({
    from: `oklch(0.62 0.13 ${props.hue})`,
    to: `oklch(0.32 0.09 ${(props.hue + 40) % 360})`,
    groove: `oklch(0.9 0.05 ${props.hue} / 0.35)`,
}));
</script>

<template>
    <svg
        viewBox="0 0 40 40"
        class="block"
        preserveAspectRatio="xMidYMid slice"
        aria-hidden="true"
    >
        <defs>
            <linearGradient :id="gradientId" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0" :stop-color="colors.from" />
                <stop offset="1" :stop-color="colors.to" />
            </linearGradient>
        </defs>
        <rect width="40" height="40" :fill="`url(#${gradientId})`" />
        <g fill="none" :stroke="colors.groove" stroke-width="0.6">
            <circle cx="30" cy="30" r="8" />
            <circle cx="30" cy="30" r="13" />
            <circle cx="30" cy="30" r="18" />
        </g>
    </svg>
</template>
