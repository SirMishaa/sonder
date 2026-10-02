<script setup lang="ts">
import {
    TransitionPresets,
    usePreferredReducedMotion,
    useTransition,
} from '@vueuse/core';
import { computed, ref, watch } from 'vue';
import { formatNumber } from '@/lib/i18n';

/**
 * A count that rolls to its new value and glows amber for a moment when it
 * changes, so a live update is seen without being read.
 */
const props = defineProps<{ value: number }>();

const motion = usePreferredReducedMotion();
const target = computed(() => props.value);
const duration = computed(() => (motion.value === 'reduce' ? 0 : 520));
const shown = useTransition(target, {
    duration,
    transition: TransitionPresets.easeOutQuint,
});

const flashing = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

watch(target, (next, previous) => {
    if (next === previous) {
        return;
    }

    flashing.value = false;
    clearTimeout(timer);
    requestAnimationFrame(() => {
        flashing.value = true;
        timer = setTimeout(() => (flashing.value = false), 700);
    });
});
</script>

<template>
    <span class="live-number" :class="{ 'is-flashing': flashing }">{{
        formatNumber(Math.round(shown))
    }}</span>
</template>

<style scoped>
.live-number {
    font-variant-numeric: tabular-nums;
    transition: color 700ms var(--ease-out-quint);
}

.live-number.is-flashing {
    color: var(--color-amber-light);
    transition-duration: 80ms;
}
</style>
