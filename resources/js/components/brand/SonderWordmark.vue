<script setup lang="ts">
import { useId } from 'vue';

const gradientId = `sonder-o-${useId()}`;
</script>

<template>
    <span class="wordmark" aria-label="sonder">
        <span aria-hidden="true">s</span>
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <defs>
                <linearGradient :id="gradientId" x1="0" y1="0" x2="1" y2="1">
                    <stop offset="0" stop-color="oklch(0.84 0.14 75)" />
                    <stop offset="1" stop-color="oklch(0.66 0.17 48)" />
                </linearGradient>
            </defs>
            <circle cx="12" cy="12" r="3.3" :fill="`url(#${gradientId})`" />
            <circle
                cx="12"
                cy="12"
                r="9.4"
                fill="none"
                :stroke="`url(#${gradientId})`"
                stroke-width="3.6"
            />
        </svg>
        <span aria-hidden="true">nder</span>
    </span>
</template>

<style scoped>
.wordmark {
    display: inline-flex;
    align-items: baseline;
    font-family: var(--font-display);
    font-weight: 800;
    font-size: 25px;
    line-height: 1;
    letter-spacing: -0.02em;
    color: var(--color-paper);
}

.wordmark svg {
    width: 0.6em;
    height: 0.6em;
    margin: 0 0.02em 0 0.03em;
    transform: translateY(0.07em);
}
</style>
