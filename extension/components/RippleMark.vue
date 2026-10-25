<script setup lang="ts">
/**
 * Sonder's Ripple mark: an amber dot inside two broken rings. The rings turn
 * slowly, in opposite directions, while `turning`; never with reduced motion.
 */
withDefaults(defineProps<{ turning?: boolean; dim?: boolean }>(), {
    turning: false,
    dim: false,
});
</script>

<template>
    <svg
        viewBox="0 0 40 40"
        class="ripple"
        :class="{ 'is-turning': turning, 'is-dim': dim }"
        aria-hidden="true"
    >
        <circle class="outer" cx="20" cy="20" r="17" />
        <circle class="inner" cx="20" cy="20" r="11" />
        <circle class="dot" cx="20" cy="20" r="4.5" />
    </svg>
</template>

<style scoped>
.ripple {
    color: var(--color-amber);
}

.ripple.is-dim {
    color: var(--color-faint);
}

circle.outer,
circle.inner {
    fill: none;
    stroke: currentColor;
    stroke-width: 2.6;
    stroke-linecap: round;
    transform-origin: 20px 20px;
}

circle.outer {
    stroke-dasharray: 74 33;
    opacity: 0.55;
}

circle.inner {
    stroke-dasharray: 48 21;
}

circle.dot {
    fill: currentColor;
}

@media (prefers-reduced-motion: no-preference) {
    .is-turning circle.outer {
        animation: turn 22s linear infinite reverse;
    }

    .is-turning circle.inner {
        animation: turn 14s linear infinite;
    }
}

@keyframes turn {
    to {
        transform: rotate(1turn);
    }
}
</style>
