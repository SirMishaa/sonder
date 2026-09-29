<script setup lang="ts">
import { useId } from 'vue';

type Props = {
    spinning?: boolean;
};

withDefaults(defineProps<Props>(), { spinning: false });

const gradientId = `sonder-amber-${useId()}`;
</script>

<template>
    <svg
        viewBox="0 0 48 48"
        class="sonder-mark"
        :class="{ 'is-spinning': spinning }"
        aria-hidden="true"
    >
        <defs>
            <linearGradient :id="gradientId" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0" stop-color="oklch(0.84 0.14 75)" />
                <stop offset="1" stop-color="oklch(0.66 0.17 48)" />
            </linearGradient>
        </defs>
        <circle cx="24" cy="24" r="5" :fill="`url(#${gradientId})`" />
        <g class="ring-inner ring">
            <circle
                cx="24"
                cy="24"
                r="11.5"
                fill="none"
                :stroke="`url(#${gradientId})`"
                stroke-width="3.2"
                stroke-dasharray="46 26"
                stroke-linecap="round"
            />
        </g>
        <g class="ring-outer ring">
            <circle
                cx="24"
                cy="24"
                r="19"
                fill="none"
                :stroke="`url(#${gradientId})`"
                stroke-width="3.2"
                stroke-dasharray="30 12 52 25"
                stroke-linecap="round"
                opacity="0.5"
            />
        </g>
    </svg>
</template>

<style scoped>
.ring {
    transform-origin: 24px 24px;
    animation: orbit 14s linear infinite paused;
}

.ring-inner {
    transform: rotate(-35deg);
}

.ring-outer {
    transform: rotate(20deg);
    animation-duration: 22s;
    animation-direction: reverse;
}

.is-spinning .ring {
    animation-play-state: running;
}
</style>
