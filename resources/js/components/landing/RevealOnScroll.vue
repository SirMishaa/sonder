<script setup lang="ts">
import { useIntersectionObserver } from '@vueuse/core';
import { onMounted, ref, useTemplateRef } from 'vue';

const target = useTemplateRef<HTMLElement>('target');
const seen = ref(false);
const armed = ref(false);

onMounted(() => (armed.value = true));

const { stop } = useIntersectionObserver(
    target,
    ([entry]) => {
        if (entry?.isIntersecting) {
            seen.value = true;
            stop();
        }
    },
    { threshold: 0.2 },
);
</script>

<template>
    <div
        ref="target"
        class="reveal-on-scroll"
        :class="{ 'is-armed': armed, 'is-seen': seen }"
    >
        <slot />
    </div>
</template>

<style scoped>
/* Hidden only once mounted, so server-rendered HTML reads fine on its own. */
.is-armed > :deep(* > *) {
    opacity: 0;
    transform: translateY(14px);
    filter: blur(4px);
    transition:
        opacity 520ms var(--ease-out-quint),
        transform 520ms var(--ease-out-quint),
        filter 520ms var(--ease-out-quint);
    transition-delay: calc(var(--i, 0) * 90ms);
}

.is-seen > :deep(* > *) {
    opacity: 1;
    transform: none;
    filter: none;
}

@media (prefers-reduced-motion: reduce) {
    .is-armed > :deep(* > *) {
        opacity: 1;
        transform: none;
        filter: none;
    }
}
</style>
